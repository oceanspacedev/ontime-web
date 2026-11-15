import { Linking } from 'react-native';

import type { Company, CompanyLocation, OutletGeofence } from './api/types';

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
  /** The check is limited to the outlet of the worker's schedule, as the server does. */
  isScheduledOutlet: boolean;
}

const DEFAULT_RADIUS_METERS = 100;

/**
 * Finds the closest office geofence with the same candidates and radii as the backend's
 * GeofenceService: with a scheduled outlet only that outlet counts; otherwise every active branch
 * plus the head office. The backend decides the final result; this only lets the app warn before
 * sending. Returns null when there is nothing to compare against.
 */
export function nearestLocation(company: Company | null, lat: number, lng: number, outlet?: OutletGeofence | null): NearestLocation | null {
  if (!company) {
    return null;
  }
  const defaultRadius = company.geofence_radius_meters || DEFAULT_RADIUS_METERS;

  if (outlet) {
    // Older servers send the outlet without coordinates; look them up in the company's locations.
    const known = company.locations?.find((location) => location.id === outlet.id);
    const latitude = outlet.latitude ?? known?.latitude ?? null;
    const longitude = outlet.longitude ?? known?.longitude ?? null;
    if (latitude === null || longitude === null) {
      return null;
    }
    const radius = outlet.radius_meters || known?.radius_meters || defaultRadius;
    const distance = distanceMeters(lat, lng, latitude, longitude);
    return { name: outlet.name, distance, radius, inside: distance <= radius, isScheduledOutlet: true };
  }

  const candidates: CompanyLocation[] = [...(company.locations ?? [])];
  if (company.latitude !== null && company.longitude !== null) {
    candidates.push({
      id: 0,
      name: 'Kantor Pusat',
      latitude: company.latitude,
      longitude: company.longitude,
      radius_meters: defaultRadius,
    });
  }
  let best: NearestLocation | null = null;
  for (const location of candidates) {
    const distance = distanceMeters(lat, lng, location.latitude, location.longitude);
    const radius = location.radius_meters || defaultRadius;
    const inside = distance <= radius;
    // Inside any geofence wins, like the server, even if another centre is closer.
    if (!best || (inside && !best.inside) || (inside === best.inside && distance < best.distance)) {
      best = { name: location.name, distance, radius, inside, isScheduledOutlet: false };
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
