<?php

namespace App\Services;

use App\Models\Company;

class GeofenceService
{
    /**
     * Calculate Haversine distance between two coordinates in meters.
     */
    public static function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // Earth radius in meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Validate if coordinates fall within maxRadiusMeters of center location.
     */
    public static function isWithinGeofence(
        float $centerLat,
        float $centerLon,
        float $userLat,
        float $userLon,
        int $maxRadiusMeters
    ): bool {
        $distance = static::calculateDistance($centerLat, $centerLon, $userLat, $userLon);

        return $distance <= $maxRadiusMeters;
    }

    /**
     * Alias method for backward compatibility.
     */
    public static function isWithinRadius(
        float $userLat,
        float $userLon,
        float $centerLat,
        float $centerLon,
        int $maxRadiusMeters
    ): bool {
        return static::isWithinGeofence($centerLat, $centerLon, $userLat, $userLon, $maxRadiusMeters);
    }

    public const DEFAULT_RADIUS_METERS = 100;

    /**
     * Whether the company has at least one coordinate a geofence can be checked against
     * (HQ coordinates or an active branch location).
     */
    public static function hasConfiguredLocations(Company $company): bool
    {
        if ($company->latitude && $company->longitude) {
            return true;
        }

        return $company->locations()->where('is_active', true)->exists();
    }

    /**
     * Validate user GPS against all active branch/office locations of a Company.
     */
    public static function validateCompanyGeofence(Company $company, float $userLat, float $userLng): array
    {
        $policy = $company->policy;
        $defaultRadius = max(1, (int) ($policy?->geofence_radius_meters ?: static::DEFAULT_RADIUS_METERS));

        $locations = $company->locations()->where('is_active', true)->get();

        $validLocations = [];

        // 1. Sertakan Kantor Utama (HQ) jika koordinatnya diisi
        if ($company->latitude && $company->longitude) {
            $validLocations[] = [
                'name' => $company->name.' (Kantor Utama)',
                'lat' => (float) $company->latitude,
                'lng' => (float) $company->longitude,
                'radius' => $defaultRadius,
            ];
        }

        // 2. Sertakan semua lokasi cabang / tambahan yang aktif
        if ($locations->isNotEmpty()) {
            foreach ($locations as $loc) {
                $validLocations[] = [
                    'name' => $loc->name,
                    'lat' => (float) $loc->latitude,
                    'lng' => (float) $loc->longitude,
                    'radius' => max(1, (int) ($loc->radius_meters ?: $defaultRadius)),
                ];
            }
        }

        if (empty($validLocations)) {
            return [
                'is_valid' => true,
                'matched_location_name' => null,
                'nearest_location_name' => null,
                'nearest_distance' => 0,
                'allowed_radius' => $defaultRadius,
                'message' => null,
            ];
        }

        $nearestDistance = null;
        $nearestLocationName = null;
        $nearestRadius = $defaultRadius;
        $nearestLat = null;
        $nearestLng = null;

        foreach ($validLocations as $loc) {
            $distance = static::calculateDistance($loc['lat'], $loc['lng'], $userLat, $userLng);

            if ($distance <= $loc['radius']) {
                return [
                    'is_valid' => true,
                    'matched_location_name' => $loc['name'],
                    'nearest_location_name' => $loc['name'],
                    'nearest_distance' => $distance,
                    'allowed_radius' => $loc['radius'],
                    'nearest_lat' => $loc['lat'],
                    'nearest_lng' => $loc['lng'],
                    'message' => null,
                ];
            }

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearestLocationName = $loc['name'];
                $nearestRadius = $loc['radius'];
                $nearestLat = $loc['lat'];
                $nearestLng = $loc['lng'];
            }
        }

        return [
            'is_valid' => false,
            'matched_location_name' => null,
            'nearest_location_name' => $nearestLocationName,
            'nearest_distance' => $nearestDistance,
            'allowed_radius' => $nearestRadius,
            'nearest_lat' => $nearestLat,
            'nearest_lng' => $nearestLng,
            'message' => "Posisi Anda ({$nearestDistance} meter) berada di luar radius geofence kantor terdekat '{$nearestLocationName}' ({$nearestRadius} meter).",
        ];
    }
}
