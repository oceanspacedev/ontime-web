import { Ionicons } from '@expo/vector-icons';
import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { Image } from 'expo-image';
import * as Location from 'expo-location';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Button, Notice } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { attendanceApi, errorMessage, type Company } from '@/lib/api';
import { formatDistance, nearestLocation, type NearestLocation } from '@/lib/geo';
import { prepareUpload } from '@/lib/image';
import { colors, fontSize, radius, spacing } from '@/theme';

class LocationDeniedError extends Error {
  constructor() {
    super('Izin lokasi ditolak.');
    this.name = 'LocationDeniedError';
  }
}

interface DeviceLocation {
  latitude: number;
  longitude: number;
  accuracy: number | null;
  nearest: NearestLocation | null;
}

async function readLocation(company: Company | null): Promise<DeviceLocation> {
  const permission = await Location.requestForegroundPermissionsAsync();
  if (!permission.granted) {
    throw new LocationDeniedError();
  }
  const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
  const { latitude, longitude, accuracy } = position.coords;
  return { latitude, longitude, accuracy: accuracy ?? null, nearest: nearestLocation(company, latitude, longitude) };
}

interface Shot {
  uri: string;
  width: number;
  height: number;
}

export default function AttendanceCheckScreen() {
  const { mode } = useLocalSearchParams<{ mode?: string }>();
  const isCheckIn = mode !== 'out';
  const access = useAccess();
  const policy = access.company?.policy ?? null;
  const needsPhoto = Boolean(policy?.require_photo || policy?.require_face_recognition);
  const needsGps = Boolean(policy?.require_gps);
  const queryClient = useQueryClient();

  const [cameraPermission, requestCameraPermission] = useCameraPermissions();
  const cameraRef = useRef<CameraView>(null);
  const [cameraReady, setCameraReady] = useState(false);
  const [shot, setShot] = useState<Shot | null>(null);
  const [capturing, setCapturing] = useState(false);
  const location = useQuery({
    queryKey: ['device-location', access.company?.id ?? 0, mode],
    queryFn: () => readLocation(access.company),
    staleTime: 0,
    gcTime: 0,
    retry: false,
  });

  useEffect(() => {
    if (needsPhoto && cameraPermission && !cameraPermission.granted && cameraPermission.canAskAgain) {
      void requestCameraPermission();
    }
  }, [needsPhoto, cameraPermission, requestCameraPermission]);

  const submit = useMutation({
    mutationFn: async () => {
      const payload: attendanceApi.CheckPayload = {};
      if (location.data) {
        payload.latitude = location.data.latitude;
        payload.longitude = location.data.longitude;
      }
      if (shot) {
        payload.photo = await prepareUpload(shot.uri, isCheckIn ? 'check-in' : 'check-out', shot.width, shot.height);
      }
      return isCheckIn ? attendanceApi.checkIn(payload) : attendanceApi.checkOut(payload);
    },
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['attendance'] });
      await queryClient.invalidateQueries({ queryKey: ['report'] });
      Alert.alert(isCheckIn ? 'Absen masuk tercatat' : 'Absen pulang tercatat', response.message, [
        { text: 'OK', onPress: () => router.back() },
      ]);
    },
  });

  const capture = async () => {
    if (!cameraRef.current || !cameraReady || capturing) {
      return;
    }
    setCapturing(true);
    try {
      const picture = await cameraRef.current.takePictureAsync({ quality: 0.9, shutterSound: false });
      if (picture) {
        setShot({ uri: picture.uri, width: picture.width, height: picture.height });
      }
    } catch (error) {
      Alert.alert('Gagal mengambil foto', errorMessage(error));
    } finally {
      setCapturing(false);
    }
  };

  const locationBlocked = needsGps && !location.data;
  const photoBlocked = needsPhoto && !shot;
  const canSubmit = !locationBlocked && !photoBlocked && !submit.isPending;
  const title = isCheckIn ? 'Absen Masuk' : 'Absen Pulang';

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen
        options={{
          title,
          headerLeft: () => (
            <Pressable onPress={() => router.back()} hitSlop={12} disabled={submit.isPending} accessibilityLabel="Tutup">
              <Ionicons name="close" size={26} color={colors.textOnPrimary} />
            </Pressable>
          ),
        }}
      />

      <View style={styles.cameraArea}>
        {!needsPhoto ? (
          <View style={styles.noPhoto}>
            <Ionicons name="location" size={64} color={colors.primarySoft} />
            <Text style={styles.noPhotoText}>Perusahaan tidak mewajibkan foto selfie. Pastikan lokasi sudah terbaca lalu kirim.</Text>
          </View>
        ) : shot ? (
          <Image source={{ uri: shot.uri }} style={styles.preview} contentFit="cover" />
        ) : !cameraPermission ? (
          <ActivityIndicator color={colors.textOnPrimary} />
        ) : !cameraPermission.granted ? (
          <View style={styles.noPhoto}>
            <Ionicons name="camera-outline" size={64} color={colors.primarySoft} />
            <Text style={styles.noPhotoText}>Izin kamera dibutuhkan untuk foto selfie presensi.</Text>
            <Button title="Izinkan kamera" variant="secondary" onPress={() => requestCameraPermission()} />
          </View>
        ) : (
          <CameraView ref={cameraRef} style={styles.camera} facing="front" mirror onCameraReady={() => setCameraReady(true)} />
        )}
      </View>

      <View style={styles.panel}>
        <LocationStatus location={location} required={needsGps} />

        {submit.isError ? <Notice tone="danger" message={errorMessage(submit.error)} /> : null}

        {needsPhoto && !shot ? (
          <Pressable
            onPress={capture}
            disabled={!cameraReady || capturing || !cameraPermission?.granted}
            style={({ pressed }) => [styles.shutter, (pressed || capturing || !cameraReady) && styles.shutterPressed]}>
            {capturing ? <ActivityIndicator color={colors.primary} /> : <Ionicons name="camera" size={30} color={colors.primary} />}
          </Pressable>
        ) : (
          <View style={styles.actions}>
            {needsPhoto ? (
              <Button title="Ulangi foto" variant="secondary" icon="refresh" onPress={() => setShot(null)} disabled={submit.isPending} style={styles.action} />
            ) : null}
            <Button title={`Kirim ${title}`} icon="send" onPress={() => submit.mutate()} disabled={!canSubmit} loading={submit.isPending} style={styles.action} />
          </View>
        )}
      </View>
    </SafeAreaView>
  );
}

function LocationStatus({ location, required }: { location: UseQueryResult<DeviceLocation>; required: boolean }) {
  if (location.isPending) {
    return (
      <View style={styles.locationRow}>
        <ActivityIndicator color={colors.primary} />
        <Text style={styles.locationText}>Membaca lokasi…</Text>
      </View>
    );
  }
  if (location.isError) {
    if (location.error instanceof LocationDeniedError) {
      return (
        <Notice
          tone={required ? 'danger' : 'warning'}
          icon="location-outline"
          message={required ? 'Izin lokasi ditolak. Presensi butuh lokasi, aktifkan izin lokasi di pengaturan HP.' : 'Izin lokasi ditolak. Presensi tetap bisa dikirim tanpa lokasi.'}
        />
      );
    }
    return (
      <View style={styles.locationRow}>
        <Ionicons name="alert-circle" size={20} color={colors.danger} />
        <Text style={[styles.locationText, { color: colors.danger }]} numberOfLines={2}>
          Lokasi gagal dibaca. {errorMessage(location.error)}
        </Text>
        <Pressable onPress={() => location.refetch()} hitSlop={8}>
          <Text style={styles.retry}>Ulangi</Text>
        </Pressable>
      </View>
    );
  }
  const nearest = location.data.nearest;
  if (!nearest) {
    return (
      <View style={styles.locationRow}>
        <Ionicons name="location" size={20} color={colors.success} />
        <Text style={styles.locationText}>Lokasi terbaca.</Text>
      </View>
    );
  }
  return (
    <View style={[styles.locationRow, { backgroundColor: nearest.inside ? colors.successSoft : colors.warningSoft }]}>
      <Ionicons name={nearest.inside ? 'checkmark-circle' : 'warning'} size={20} color={nearest.inside ? colors.success : colors.warning} />
      <Text style={[styles.locationText, { color: nearest.inside ? colors.success : colors.warning }]} numberOfLines={3}>
        {nearest.inside
          ? `Di area ${nearest.name} (${formatDistance(nearest.distance)} dari titik kantor).`
          : `${formatDistance(nearest.distance)} dari ${nearest.name}, di luar radius ${nearest.radius} m. Presensi akan menunggu persetujuan atasan.`}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.primaryDark },
  cameraArea: { flex: 1, margin: spacing.lg, borderRadius: radius.lg, overflow: 'hidden', backgroundColor: '#000', alignItems: 'center', justifyContent: 'center' },
  camera: { flex: 1, width: '100%' },
  preview: { flex: 1, width: '100%' },
  noPhoto: { alignItems: 'center', gap: spacing.md, padding: spacing.xl },
  noPhotoText: { color: colors.primarySoft, textAlign: 'center', fontSize: fontSize.md, lineHeight: 22 },
  panel: {
    backgroundColor: colors.surface,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    padding: spacing.lg,
    gap: spacing.md,
  },
  locationRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    padding: spacing.md,
    borderRadius: radius.md,
    backgroundColor: colors.background,
  },
  locationText: { flex: 1, fontSize: fontSize.sm, color: colors.text, lineHeight: 20 },
  retry: { color: colors.primary, fontWeight: '700', fontSize: fontSize.sm },
  shutter: {
    alignSelf: 'center',
    width: 72,
    height: 72,
    borderRadius: 36,
    backgroundColor: colors.primarySoft,
    borderWidth: 4,
    borderColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  shutterPressed: { opacity: 0.6 },
  actions: { flexDirection: 'row', gap: spacing.sm },
  action: { flex: 1 },
});
