import { failureMessage } from '../api/client';
import type { Company } from '../api/types';
import { nearestLocation } from '../geo';
import { ApproximateLocationError, isServicesOffError, LocationDeniedError, locationErrorMessage, LocationServicesOffError } from '../location';

jest.mock('expo-location', () => ({}));

function company(overrides: Partial<Company> = {}): Company {
  return {
    id: 1,
    name: 'PT Contoh',
    latitude: -6.2,
    longitude: 106.8,
    locations: [],
    geofence_radius_meters: 150,
    policy: null,
    ...overrides,
  };
}

/** About 111 m per 0.001 degree of latitude. */
const NORTH_120M = -6.2 + 0.00108;

describe('nearestLocation', () => {
  it('uses the server radius for the head office instead of 100 m', () => {
    const nearest = nearestLocation(company(), NORTH_120M, 106.8);
    expect(nearest?.radius).toBe(150);
    expect(nearest?.inside).toBe(true);
  });

  it('only checks the scheduled outlet when one is given, like the server', () => {
    const outlet = { id: 9, name: 'Outlet Ciledug', latitude: -6.3, longitude: 106.7, radius_meters: 80 };
    const nearest = nearestLocation(company(), -6.2, 106.8, outlet);
    expect(nearest?.name).toBe('Outlet Ciledug');
    expect(nearest?.isScheduledOutlet).toBe(true);
    expect(nearest?.inside).toBe(false);
  });

  it('falls back to the company location for an outlet sent without coordinates', () => {
    const withBranch = company({ locations: [{ id: 9, name: 'Outlet Ciledug', latitude: -6.3, longitude: 106.7, radius_meters: 80 }] });
    const nearest = nearestLocation(withBranch, -6.3, 106.7, { id: 9, name: 'Outlet Ciledug' });
    expect(nearest?.inside).toBe(true);
    expect(nearest?.radius).toBe(80);
  });

  it('counts as inside when any geofence contains the point, even if another centre is closer', () => {
    const withBranch = company({
      latitude: -6.2,
      longitude: 106.8,
      geofence_radius_meters: 50,
      locations: [{ id: 2, name: 'Cabang Besar', latitude: -6.2015, longitude: 106.8, radius_meters: 500 }],
    });
    // 60 m from the head office (radius 50) and 107 m from the branch (radius 500).
    const nearest = nearestLocation(withBranch, -6.20054, 106.8);
    expect(nearest?.name).toBe('Cabang Besar');
    expect(nearest?.inside).toBe(true);
  });
});

describe('locationErrorMessage', () => {
  it('turns native Android errors into Indonesian guidance', () => {
    expect(locationErrorMessage({ code: 'ERR_LOCATION_SETTINGS_UNSATISFIED', message: 'Location request failed due to unsatisfied device settings' })).toMatch(/GPS\/lokasi perangkat mati/);
    expect(locationErrorMessage({ code: 'ERR_CURRENT_LOCATION_IS_UNAVAILABLE' })).toMatch(/Pindah ke area terbuka/);
    expect(locationErrorMessage(new Error('Something in English'))).toBe('Lokasi gagal dibaca. Tekan Ulangi untuk mencoba lagi.');
  });

  it('keeps the messages of its own errors', () => {
    expect(locationErrorMessage(new LocationDeniedError(true))).toMatch(/Izin lokasi ditolak/);
    expect(locationErrorMessage(new ApproximateLocationError(true))).toMatch(/lokasi akurat/);
  });

  it('recognises a switched-off location as fixable by enabling it', () => {
    expect(isServicesOffError(new LocationServicesOffError())).toBe(true);
    expect(isServicesOffError({ code: 'ERR_LOCATION_SETTINGS_UNSATISFIED' })).toBe(true);
    expect(isServicesOffError(new Error('other'))).toBe(false);
  });
});

describe('failureMessage', () => {
  it('keeps the server message', () => {
    expect(failureMessage(422, 'Foto selfie wajib diunggah.', false)).toBe('Foto selfie wajib diunggah.');
  });

  it('replaces an HTML 413 from a proxy with an Indonesian size message', () => {
    expect(failureMessage(413, undefined, true)).toMatch(/terlalu besar/);
  });

  it("replaces Laravel's English defaults", () => {
    expect(failureMessage(500, 'Server Error', false)).toBe('Terjadi kesalahan di server. Coba lagi beberapa saat.');
    expect(failureMessage(413, 'The POST data is too large.', false)).toMatch(/terlalu besar/);
    expect(failureMessage(502, undefined, true)).toMatch(/tidak tersedia/);
  });

  it('names the status for an unreadable body without a known message', () => {
    expect(failureMessage(418, undefined, true)).toContain('kode 418');
    expect(failureMessage(404, undefined, false)).toBe('Permintaan gagal (404).');
  });
});
