import { useMutation } from '@tanstack/react-query';
import * as Location from 'expo-location';
import { useState } from 'react';
import { Alert, Switch, View } from 'react-native';

import { Button, Field, Group, Notice, Row, Screen } from '@/components/ui';
import { useClientAdminSaved } from '@/hooks/useClientAdminSaved';
import { ApiError, errorMessage, type CompanyInput, type CompanySummary, type SavedResponse } from '@/lib/api';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import { spacing, useTheme } from '@/theme';

/** Optional text becomes null when left blank, so the server clears it instead of keeping spaces. */
function blankToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

function coordinateText(value: number | null | undefined): string {
  return value === null || value === undefined ? '' : String(value);
}

/** Accepts a comma as the decimal mark too, which Indonesian keyboards often type. */
function parseCoordinate(value: string): number | null | 'invalid' {
  const trimmed = value.trim().replace(',', '.');
  if (trimmed === '') {
    return null;
  }
  const parsed = Number(trimmed);
  return Number.isFinite(parsed) ? parsed : 'invalid';
}

interface CompanyFormProps {
  /** The company being edited; leave it out to add a new one. */
  company?: CompanySummary;
  submitLabel: string;
  onSubmit: (input: CompanyInput) => Promise<SavedResponse<CompanySummary>>;
  onSaved: (response: SavedResponse<CompanySummary>) => void;
}

/** Name, code, contact, map pin and status of a company: the same fields as the web panel. */
export function CompanyForm({ company, submitLabel, onSubmit, onSaved }: CompanyFormProps) {
  const onClientSaved = useClientAdminSaved();
  const { colors } = useTheme();

  const [name, setName] = useState(company?.name ?? '');
  const [code, setCode] = useState(company?.code ?? '');
  const [email, setEmail] = useState(company?.email ?? '');
  const [phone, setPhone] = useState(company?.phone ?? '');
  const [address, setAddress] = useState(company?.address ?? '');
  const [latitude, setLatitude] = useState(coordinateText(company?.latitude));
  const [longitude, setLongitude] = useState(coordinateText(company?.longitude));
  const [isActive, setIsActive] = useState(company?.is_active ?? true);
  const [locating, setLocating] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});

  const submit = useMutation({
    mutationFn: (input: CompanyInput) => onSubmit(input),
    onSuccess: async (response) => {
      await onClientSaved();
      onSaved(response);
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const fillCurrentLocation = async () => {
    setLocating(true);
    try {
      const permission = await Location.requestForegroundPermissionsAsync();
      if (!permission.granted) {
        Alert.alert('Izin lokasi dibutuhkan', 'Izinkan akses lokasi agar titik badan usaha bisa diambil dari posisi Anda.');
        return;
      }
      const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
      setLatitude(position.coords.latitude.toFixed(7));
      setLongitude(position.coords.longitude.toFixed(7));
      setErrors((current) => {
        const { latitude: _latitude, longitude: _longitude, ...rest } = current;
        return rest;
      });
    } catch (error) {
      Alert.alert('Gagal mengambil lokasi', errorMessage(error));
    } finally {
      setLocating(false);
    }
  };

  const handleSubmit = () => {
    const next: FieldErrors = {};
    if (!name.trim()) {
      next.name = requiredMessage('Nama');
    }
    if (!code.trim()) {
      next.code = requiredMessage('Kode');
    }
    const lat = parseCoordinate(latitude);
    const lng = parseCoordinate(longitude);
    if (lat === 'invalid' || (lat !== null && (lat < -90 || lat > 90))) {
      next.latitude = 'Latitude harus angka antara -90 dan 90.';
    }
    if (lng === 'invalid' || (lng !== null && (lng < -180 || lng > 180))) {
      next.longitude = 'Longitude harus angka antara -180 dan 180.';
    }
    if (!next.latitude && !next.longitude && (lat === null) !== (lng === null)) {
      next[lat === null ? 'latitude' : 'longitude'] = 'Isi latitude dan longitude sekaligus.';
    }
    setErrors(next);
    if (Object.keys(next).length > 0 || lat === 'invalid' || lng === 'invalid') {
      return;
    }

    submit.mutate({
      name: name.trim(),
      code: code.trim(),
      email: blankToNull(email),
      phone: blankToNull(phone),
      address: blankToNull(address),
      latitude: lat,
      longitude: lng,
      is_active: isActive,
    });
  };

  const generalError = submit.isError && !(submit.error instanceof ApiError && submit.error.errors) ? errorMessage(submit.error) : null;

  return (
    <Screen
      footer={
        <>
          {generalError ? <Notice tone="danger" message={generalError} /> : null}
          <Button title={submitLabel} icon="checkmark" size="lg" onPress={handleSubmit} loading={submit.isPending} />
        </>
      }>
      <Group title="Data badan usaha" padded>
        <Field label="Nama" value={name} onChangeText={setName} autoCapitalize="words" placeholder="PT Contoh Sejahtera" error={errors.name} maxLength={255} />
        <Field
          label="Kode"
          value={code}
          onChangeText={setCode}
          autoCapitalize="characters"
          autoCorrect={false}
          placeholder="PTCS"
          hint="Unik di dalam Klien ini."
          error={errors.code}
          maxLength={50}
        />
        <Field label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoCapitalize="none" autoCorrect={false} placeholder="opsional" error={errors.email} maxLength={255} />
        <Field label="Telepon" value={phone} onChangeText={setPhone} keyboardType="phone-pad" placeholder="opsional" error={errors.phone} maxLength={50} />
        <Field label="Alamat" value={address} onChangeText={setAddress} multiline placeholder="opsional" error={errors.address} />
      </Group>

      <Group title="Titik lokasi" padded footer="Dipakai sebagai titik utama badan usaha. Lokasi absensi diatur terpisah di menu Lokasi Absensi.">
        <View style={{ flexDirection: 'row', gap: spacing.md }}>
          <View style={{ flex: 1 }}>
            <Field label="Latitude" value={latitude} onChangeText={setLatitude} keyboardType="numbers-and-punctuation" placeholder="-6.2000000" error={errors.latitude} />
          </View>
          <View style={{ flex: 1 }}>
            <Field label="Longitude" value={longitude} onChangeText={setLongitude} keyboardType="numbers-and-punctuation" placeholder="106.8166667" error={errors.longitude} />
          </View>
        </View>
        <Button title="Pakai Lokasi Saya Sekarang" icon="locate" variant="secondary" onPress={fillCurrentLocation} loading={locating} />
      </Group>

      <Group title="Status">
        <Row
          icon="power-outline"
          title="Aktif"
          subtitle={isActive ? 'Badan usaha bisa dipakai untuk absensi.' : 'Badan usaha dinonaktifkan.'}
          right={<Switch value={isActive} onValueChange={setIsActive} trackColor={{ false: colors.fill, true: colors.primary }} thumbColor={colors.onPrimary} />}
        />
      </Group>
      {errors.is_active ? <Notice tone="danger" message={errors.is_active} /> : null}
    </Screen>
  );
}
