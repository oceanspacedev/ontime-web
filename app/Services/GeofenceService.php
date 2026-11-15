<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyLocation;

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

    /** Shown when GPS is required but the company has no coordinate to check a worker against. */
    public const NO_LOCATION_CONFIGURED_MESSAGE = 'Lokasi absen belum diatur. Hubungi admin.';

    /**
     * The radius a location is checked with: its own, else the policy default, else 100 m.
     */
    public static function resolveRadius(int|string|null $locationRadius, int|string|null $policyRadius = null): int
    {
        $default = max(1, (int) ($policyRadius ?: static::DEFAULT_RADIUS_METERS));

        return max(1, (int) ($locationRadius ?: $default));
    }

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
     * Whether a change would leave a company that requires GPS without any coordinate to check against:
     * the given locations stop counting (deactivated, deleted or moved) and, with $withoutHq, the HQ
     * coordinates are cleared.
     *
     * @param  array<int, int>  $exceptLocationIds
     */
    public static function wouldLeaveGpsWithoutLocation(Company $company, array $exceptLocationIds = [], bool $withoutHq = false): bool
    {
        if (! $company->policy?->require_gps) {
            return false;
        }

        if (! $withoutHq && $company->latitude && $company->longitude) {
            return false;
        }

        return ! $company->locations()
            ->where('is_active', true)
            ->when($exceptLocationIds !== [], fn ($query) => $query->whereKeyNot($exceptLocationIds))
            ->exists();
    }

    /**
     * Validate user GPS against all active branch/office locations of a Company, or only against
     * the outlet the worker is scheduled at when one is given.
     */
    public static function validateCompanyGeofence(Company $company, float $userLat, float $userLng, ?CompanyLocation $outlet = null): array
    {
        $policy = $company->policy;
        $defaultRadius = static::resolveRadius(null, $policy?->geofence_radius_meters);

        $locations = $outlet
            ? collect([$outlet])
            : $company->locations()->where('is_active', true)->get();

        $validLocations = [];

        // 1. Sertakan Kantor Utama (HQ) jika koordinatnya diisi, kecuali absen dibatasi ke outlet jadwal
        if (! $outlet && $company->latitude && $company->longitude) {
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
                    'radius' => static::resolveRadius($loc->radius_meters, $defaultRadius),
                ];
            }
        }

        if (empty($validLocations)) {
            // With GPS required, no coordinate must never mean "valid from anywhere": the check-in goes to approval.
            $gpsRequired = (bool) $policy?->require_gps;

            return [
                'is_valid' => ! $gpsRequired,
                'matched_location_name' => null,
                'nearest_location_name' => null,
                'nearest_distance' => 0,
                'allowed_radius' => $defaultRadius,
                'message' => $gpsRequired ? static::NO_LOCATION_CONFIGURED_MESSAGE : null,
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
