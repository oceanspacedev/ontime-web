import { Linking } from 'react-native';

import type { Company, CompanyLocation } from './api/types';

const EARTH_RADIUS_METERS = 6371000;

export function distanceMeters(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const toRad = (value: number) => (value * Math.PI) / 180;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a =
    Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
  return 2 * EARTH_RADIUS_METERS * Math.asin(Math.sqrt(a));
}

export interface NearestLocation {
  name: string;
  distance: number;
  radius: number;
  inside: boolean;
}

/**
 * Finds the closest office geofence. The backend decides the final result; this only lets the
 * app warn before sending. Returns null when the company has no coordinates.
 */
export function nearestLocation(company: Company | null, lat: number, lng: number): NearestLocation | null {
  if (!company) {
    return null;
  }
  const candidates: CompanyLocation[] = [...(company.locations ?? [])];
  if (company.latitude !== null && company.longitude !== null) {
    candidates.push({
      id: 0,
      name: 'Kantor Pusat',
      latitude: company.latitude,
      longitude: company.longitude,
      radius_meters: candidates[0]?.radius_meters ?? 100,
    });
  }
  let best: NearestLocation | null = null;
  for (const location of candidates) {
    const distance = distanceMeters(lat, lng, location.latitude, location.longitude);
    const radius = location.radius_meters || 100;
    if (!best || distance < best.distance) {
      best = { name: location.name, distance, radius, inside: distance <= radius };
    }
  }
  return best;
}

export function formatDistance(meters: number): string {
  if (meters < 1000) {
    return `${Math.round(meters)} m`;
  }
  return `${(meters / 1000).toFixed(1)} km`;
}

export function openMaps(lat: string | number | null, lng: string | number | null): void {
  if (lat === null || lng === null) {
    return;
  }
  void Linking.openURL(`https://www.google.com/maps/search/?api=1&query=${lat},${lng}`);
}
