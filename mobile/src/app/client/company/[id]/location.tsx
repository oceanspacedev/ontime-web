import { useMutation, useQuery } from '@tanstack/react-query';
import * as Location from 'expo-location';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Linking, Switch } from 'react-native';

import { Button, Choice, EmptyState, ErrorBanner, Field, Group, LoadingView, Notice, Row, Screen } from '@/components/ui';
import { useClientAdminSaved } from '@/hooks/useClientAdminSaved';
import { clientAdminApi, type ClientDivision, type ClientLocation, errorMessage, type LocationInput } from '@/lib/api';
import { unshownErrorMessage } from '@/lib/errors';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import { formatDistance, openMaps } from '@/lib/geo';
import { queryKeys } from '@/lib/queryClient';
import { useTheme } from '@/theme';

/** How long to wait for a GPS fix before giving up. */
const GPS_TIMEOUT_MS = 15_000;

/** Choice value for "applies to the whole company". */
const NO_DIVISION = 'none';

/** Adds a location to a company, or edits one when `locationId` is passed. */
export default function ClientLocationEditorScreen() {
  const { id, locationId } = useLocalSearchParams<{ id: string; locationId?: string }>();
  const companyId = Number(id);
  const editingId = locationId ? Number(locationId) : null;

  const locations = useQuery({
    queryKey: queryKeys.clientLocations(companyId),
    queryFn: () => clientAdminApi.locations(companyId),
    enabled: editingId !== null,
  });
  const divisions = useQuery({ queryKey: queryKeys.clientDivisions(companyId), queryFn: () => clientAdminApi.divisions(companyId) });
  /** Empty radii fall back to the company policy; the form still works if it fails to load. */
  const policy = useQuery({ queryKey: queryKeys.clientPolicy(companyId), queryFn: () => clientAdminApi.policy(companyId) });
  const policyRadius = policy.data?.data.geofence_radius_meters ?? null;
  const title = <Stack.Screen options={{ title: editingId !== null ? 'Ubah Lokasi' : 'Tambah Lokasi' }} />;

  if ((editingId !== null && locations.isPending) || divisions.isPending) {
    return (
      <Screen>
        {title}
        <LoadingView />
      </Screen>
    );
  }
  if (editingId !== null && locations.isError) {
    return (
      <Screen>
        {title}
        <ErrorBanner message={errorMessage(locations.error)} onRetry={() => locations.refetch()} />
      </Screen>
    );
  }

  const location = editingId !== null ? (locations.data?.data.find((item) => item.id === editingId) ?? null) : null;
  if (editingId !== null && !location) {
    return (
      <Screen>
        {title}
        <EmptyState icon="location-outline" title="Lokasi tidak ditemukan" message="Lokasi ini tidak ada atau bukan wewenang Anda." />
      </Screen>
    );
  }

  /** Divisions are optional here; if they fail to load the location still applies to the whole company. */
  const divisionList = divisions.data?.data ?? [];

  return (
    <>
      {title}
      <LocationForm companyId={companyId} location={location} divisions={divisionList} policyRadius={policyRadius} />
    </>
  );
}

class GpsError extends Error {
  constructor(
    message: string,
    public canOpenSettings = false,
  ) {
    super(message);
    this.name = 'GpsError';
  }
}

/** Reads the device position once, with the Indonesian messages the form shows. */
async function readCurrentPosition(): Promise<{ latitude: number; longitude: number; accuracy: number | null }> {
  const permission = await Location.requestForegroundPermissionsAsync();
  if (!permission.granted) {
    throw new GpsError(
      permission.canAskAgain ? 'Izin lokasi ditolak. Izinkan akses lokasi untuk memakai posisi Anda.' : 'Izin lokasi diblokir. Buka pengaturan lalu izinkan akses lokasi untuk aplikasi ini.',
      !permission.canAskAgain,
    );
  }

  let timer: ReturnType<typeof setTimeout> | undefined;
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => reject(new GpsError('Lokasi belum terbaca setelah 15 detik. Pastikan GPS aktif dan coba di tempat terbuka.')), GPS_TIMEOUT_MS);
  });

  try {
    const position = await Promise.race([Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High }), timeout]);
    return { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy ?? null };
  } catch (error) {
    if (error instanceof GpsError) {
      throw error;
    }
    throw new GpsError('Lokasi tidak bisa dibaca. Pastikan layanan lokasi (GPS) di perangkat aktif.');
  } finally {
    clearTimeout(timer);
  }
}

/** Accepts both "-6.2" and "-6,2", since Indonesian keyboards often type a comma. */
function parseCoordinate(value: string): number | null {
  const normalized = value.trim().replace(',', '.');
  if (normalized === '' || !/^-?\d+(\.\d+)?$/.test(normalized)) {
    return null;
  }
  return Number(normalized);
}

interface LocationFormProps {
  companyId: number;
  location: ClientLocation | null;
  divisions: ClientDivision[];
  /** The company policy's geofence radius, used when the location leaves its own radius empty. */
  policyRadius: number | null;
}

function LocationForm({ companyId, location, divisions, policyRadius }: LocationFormProps) {
  const onClientSaved = useClientAdminSaved();
  const { colors } = useTheme();

  const [name, setName] = useState(location?.name ?? '');
  const [address, setAddress] = useState(location?.address ?? '');
  const [latitude, setLatitude] = useState(location ? String(location.latitude) : '');
  const [longitude, setLongitude] = useState(location ? String(location.longitude) : '');
  const [radius, setRadius] = useState(location?.radius_meters != null ? String(location.radius_meters) : '');
  const [divisionId, setDivisionId] = useState(location?.division_id != null ? String(location.division_id) : NO_DIVISION);
  const [isActive, setIsActive] = useState(location?.is_active ?? true);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [accuracy, setAccuracy] = useState<number | null>(null);

  const gps = useMutation({
    mutationFn: readCurrentPosition,
    onSuccess: (position) => {
      setLatitude(position.latitude.toFixed(7));
      setLongitude(position.longitude.toFixed(7));
      setAccuracy(position.accuracy);
      setErrors(({ latitude: _latitude, longitude: _longitude, ...rest }) => rest);
    },
  });

  const buildInput = (): LocationInput | null => {
    const next: FieldErrors = {};
    const lat = parseCoordinate(latitude);
    const lng = parseCoordinate(longitude);
    const radiusText = radius.trim();
    const radiusValue = radiusText === '' ? null : Number(radiusText);

    if (!name.trim()) {
      next.name = requiredMessage('Nama lokasi');
    }
    if (!latitude.trim()) {
      next.latitude = requiredMessage('Latitude');
    } else if (lat === null || lat < -90 || lat > 90) {
      next.latitude = 'Latitude harus berupa angka antara -90 dan 90.';
    }
    if (!longitude.trim()) {
      next.longitude = requiredMessage('Longitude');
    } else if (lng === null || lng < -180 || lng > 180) {
      next.longitude = 'Longitude harus berupa angka antara -180 dan 180.';
    }
    if (radiusValue !== null && (!/^\d+$/.test(radiusText) || radiusValue < 1)) {
      next.radius_meters = 'Radius harus berupa angka bulat dalam meter.';
    }

    setErrors(next);
    if (Object.keys(next).length > 0 || lat === null || lng === null) {
      return null;
    }

    return {
      name: name.trim(),
      address: address.trim() || null,
      latitude: lat,
      longitude: lng,
      radius_meters: radiusValue,
      division_id: divisionId === NO_DIVISION ? null : Number(divisionId),
      is_active: isActive,
    };
  };

  const submit = useMutation({
    mutationFn: (input: LocationInput) => (location ? clientAdminApi.updateLocation(location.id, input) : clientAdminApi.createLocation(companyId, input)),
    onSuccess: async () => {
      await onClientSaved();
      router.back();
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const save = () => {
    const input = buildInput();
    if (input) {
      submit.mutate(input);
    }
  };

  const generalError = unshownErrorMessage(submit.error, [
    'latitude',
    'longitude',
    'name',
    'address',
    'radius_meters',
    'is_active',
    ...(divisions.length > 0 ? ['division_id'] : []),
  ]);
  const gpsError = gps.error instanceof GpsError ? gps.error : null;
  const previewLat = parseCoordinate(latitude);
  const previewLng = parseCoordinate(longitude);

  return (
    <Screen
      footer={
        <>
          {generalError ? <Notice tone="danger" message={generalError} /> : null}
          <Button title={location ? 'Simpan Perubahan' : 'Tambah Lokasi'} icon="checkmark" size="lg" onPress={save} loading={submit.isPending} />
        </>
      }>
      <Group title="Titik lokasi" padded footer="Berdiri di lokasi kantor atau outlet saat menekan tombol agar titiknya tepat.">
        <Button title="Pakai Lokasi Saya Sekarang" icon="locate" size="lg" onPress={() => gps.mutate()} loading={gps.isPending} />
        {gps.isPending ? <Notice message="Membaca lokasi perangkat…" icon="navigate-outline" /> : null}
        {gpsError ? (
          <>
            <Notice tone="danger" message={gpsError.message} />
            {gpsError.canOpenSettings ? <Button title="Buka Pengaturan" icon="settings-outline" variant="secondary" onPress={() => Linking.openSettings()} /> : null}
          </>
        ) : null}
        {gps.isSuccess ? (
          <Notice
            tone={accuracy !== null && accuracy > 50 ? 'warning' : 'success'}
            icon="location"
            message={
              accuracy !== null
                ? `Lokasi terbaca dengan akurasi ±${formatDistance(accuracy)}.${accuracy > 50 ? ' Akurasi rendah, coba lagi di tempat terbuka.' : ''}`
                : 'Lokasi terbaca.'
            }
          />
        ) : null}
        <Field label="Latitude" value={latitude} onChangeText={setLatitude} keyboardType="numbers-and-punctuation" placeholder="-6.2000000" error={errors.latitude} maxLength={20} />
        <Field label="Longitude" value={longitude} onChangeText={setLongitude} keyboardType="numbers-and-punctuation" placeholder="106.8166667" error={errors.longitude} maxLength={20} />
        {previewLat !== null && previewLng !== null ? <Button title="Lihat di Maps" icon="map-outline" variant="secondary" onPress={() => openMaps(previewLat, previewLng)} /> : null}
      </Group>

      <Group title="Data lokasi" padded>
        <Field label="Nama lokasi" value={name} onChangeText={setName} autoCapitalize="words" placeholder="Kantor Pusat" error={errors.name} maxLength={255} />
        <Field label="Alamat" value={address} onChangeText={setAddress} multiline placeholder="Opsional" error={errors.address} maxLength={1000} />
        <Field
          label="Radius (meter)"
          value={radius}
          onChangeText={setRadius}
          keyboardType="number-pad"
          placeholder={policyRadius !== null ? String(policyRadius) : undefined}
          hint={policyRadius !== null ? `Kosongkan untuk memakai radius kebijakan (${policyRadius} m).` : 'Kosongkan untuk memakai radius kebijakan.'}
          error={errors.radius_meters}
          maxLength={6}
        />
      </Group>

      {divisions.length > 0 ? (
        <Group title="Berlaku untuk" padded footer="Pilih divisi jika lokasi ini hanya untuk divisi tertentu.">
          <Choice
            options={[{ value: NO_DIVISION, label: 'Semua divisi' }, ...divisions.map((division) => ({ value: String(division.id), label: division.path }))]}
            value={divisionId}
            onChange={setDivisionId}
          />
          {errors.division_id ? <Notice tone="danger" message={errors.division_id} /> : null}
        </Group>
      ) : null}

      <Group footer="Lokasi nonaktif tidak dipakai untuk absensi.">
        <Row
          icon="power-outline"
          title="Aktif"
          right={<Switch value={isActive} onValueChange={setIsActive} trackColor={{ true: colors.primary }} accessibilityLabel="Lokasi aktif" />}
        />
      </Group>
      {errors.is_active ? <Notice tone="danger" message={errors.is_active} /> : null}
    </Screen>
  );
}
