import { Ionicons } from '@expo/vector-icons';
import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { Image } from 'expo-image';
import * as Location from 'expo-location';
import { router, useLocalSearchParams } from 'expo-router';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button, Group, IconButton, type IconName, Notice, Text } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { attendanceApi, errorMessage, type Company } from '@/lib/api';
import { formatDistance, nearestLocation, type NearestLocation } from '@/lib/geo';
import { prepareUpload } from '@/lib/image';
import { BRAND_GLOSS, BRAND_GRADIENT, elevation, fontSize, makeStyles, radius, spacing, useTheme } from '@/theme';

/** The camera frame stays black in both color schemes, so its text and guide are light on dark. */
const ON_CAMERA = 'rgba(255, 255, 255, 0.78)';

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
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
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
  const shutterDisabled = !cameraReady || capturing || !cameraPermission?.granted;

  return (
    <View style={styles.root}>
      <View style={[styles.topBar, { paddingTop: insets.top + spacing.sm }]}>
        <IconButton icon="close" onPress={() => router.back()} disabled={submit.isPending} accessibilityLabel="Tutup" />
        <View style={styles.titlePill}>
          <Text style={styles.title} numberOfLines={1}>
            {title}
          </Text>
        </View>
      </View>

      <View style={[styles.cameraArea, !needsPhoto && styles.cameraAreaIdle]}>
        {!needsPhoto ? (
          <View style={styles.noPhoto}>
            <View style={styles.noPhotoIcon}>
              <Ionicons name="location" size={40} color={colors.onPrimary} />
            </View>
            <Text style={[styles.noPhotoText, styles.idleText]}>Perusahaan tidak mewajibkan foto selfie. Pastikan lokasi sudah terbaca lalu kirim.</Text>
          </View>
        ) : shot ? (
          <Image source={{ uri: shot.uri }} style={styles.preview} contentFit="cover" />
        ) : !cameraPermission ? (
          <ActivityIndicator color={ON_CAMERA} />
        ) : !cameraPermission.granted ? (
          <View style={styles.noPhoto}>
            <Ionicons name="camera-outline" size={56} color={ON_CAMERA} />
            <Text style={styles.noPhotoText}>Izin kamera dibutuhkan untuk foto selfie presensi.</Text>
            <Button title="Izinkan kamera" variant="primary" onPress={() => requestCameraPermission()} />
          </View>
        ) : (
          <>
            <CameraView ref={cameraRef} style={styles.camera} facing="front" mirror onCameraReady={() => setCameraReady(true)} />
            <View style={styles.guideLayer} pointerEvents="none">
              <View style={styles.faceGuide} />
              <Text style={styles.guideText}>Posisikan wajah di dalam lingkaran</Text>
            </View>
          </>
        )}
      </View>

      <View style={[styles.panel, { paddingBottom: Math.max(insets.bottom, spacing.lg) }]}>
        <Group title="Lokasi">
          <LocationStatus location={location} required={needsGps} />
        </Group>

        {submit.isError ? <Notice tone="danger" message={errorMessage(submit.error)} /> : null}

        {needsPhoto && !shot ? (
          <Pressable
            onPress={capture}
            disabled={shutterDisabled}
            accessibilityRole="button"
            accessibilityLabel="Ambil foto"
            accessibilityState={{ disabled: shutterDisabled, busy: capturing }}
            style={({ pressed }) => [styles.shutter, pressed && styles.shutterPressed, (capturing || !cameraReady) && styles.shutterDisabled]}>
            <View style={styles.shutterInner}>
              {capturing ? <ActivityIndicator color={colors.onPrimary} /> : <Ionicons name="camera" size={28} color={colors.onPrimary} />}
            </View>
          </Pressable>
        ) : (
          <View style={styles.actions}>
            {needsPhoto ? (
              <Button title="Ulangi foto" variant="secondary" icon="refresh" onPress={() => setShot(null)} disabled={submit.isPending} />
            ) : null}
            <Button title={`Kirim ${title}`} icon="send" size="lg" onPress={() => submit.mutate()} disabled={!canSubmit} loading={submit.isPending} />
          </View>
        )}
      </View>
    </View>
  );
}

/** One line inside the location group: a tinted status icon, the message and an optional action. */
function LocationLine({ icon, tint, background, message, loading, right }: {
  icon?: IconName;
  tint: string;
  background: string;
  message: string;
  loading?: boolean;
  right?: ReactNode;
}) {
  const styles = useStyles();
  return (
    <View style={styles.locationRow}>
      <View style={[styles.locationIcon, { backgroundColor: background }]}>
        {loading ? <ActivityIndicator color={tint} /> : icon ? <Ionicons name={icon} size={22} color={tint} /> : null}
      </View>
      <Text style={styles.locationText} numberOfLines={3}>
        {message}
      </Text>
      {right}
    </View>
  );
}

function LocationStatus({ location, required }: { location: UseQueryResult<DeviceLocation>; required: boolean }) {
  const styles = useStyles();
  const { colors } = useTheme();

  if (location.isPending) {
    return <LocationLine loading tint={colors.primary} background={colors.primarySoft} message="Membaca lokasi…" />;
  }
  if (location.isError) {
    if (location.error instanceof LocationDeniedError) {
      return (
        <LocationLine
          icon="location-outline"
          tint={required ? colors.danger : colors.warning}
          background={required ? colors.dangerSoft : colors.warningSoft}
          message={required ? 'Izin lokasi ditolak. Presensi butuh lokasi, aktifkan izin lokasi di pengaturan HP.' : 'Izin lokasi ditolak. Presensi tetap bisa dikirim tanpa lokasi.'}
        />
      );
    }
    return (
      <LocationLine
        icon="alert-circle"
        tint={colors.danger}
        background={colors.dangerSoft}
        message={`Lokasi gagal dibaca. ${errorMessage(location.error)}`}
        right={
          <Pressable onPress={() => location.refetch()} hitSlop={8} accessibilityRole="button" style={({ pressed }) => [styles.retry, pressed && styles.retryPressed]}>
            <Text style={styles.retryText}>Ulangi</Text>
          </Pressable>
        }
      />
    );
  }
  const nearest = location.data.nearest;
  if (!nearest) {
    return <LocationLine icon="location" tint={colors.success} background={colors.successSoft} message="Lokasi terbaca." />;
  }
  return (
    <LocationLine
      icon={nearest.inside ? 'checkmark-circle' : 'warning'}
      tint={nearest.inside ? colors.success : colors.warning}
      background={nearest.inside ? colors.successSoft : colors.warningSoft}
      message={
        nearest.inside
          ? `Di area ${nearest.name} (${formatDistance(nearest.distance)} dari titik kantor).`
          : `${formatDistance(nearest.distance)} dari ${nearest.name}, di luar radius ${nearest.radius} m. Presensi akan menunggu persetujuan atasan.`
      }
    />
  );
}

const SHUTTER_SIZE = 84;
const FACE_GUIDE_SIZE = 232;

const useStyles = makeStyles((c, dark) => ({
  root: { flex: 1, backgroundColor: c.background },
  topBar: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingHorizontal: spacing.lg, paddingBottom: spacing.md },
  titlePill: { flex: 1, height: 46, borderRadius: radius.pill, backgroundColor: c.fill, justifyContent: 'center', paddingHorizontal: 20 },
  title: { fontSize: fontSize.md, color: c.textMuted, fontWeight: '500' },

  cameraArea: {
    flex: 1,
    marginHorizontal: spacing.lg,
    borderRadius: 32,
    overflow: 'hidden',
    backgroundColor: '#000000',
    alignItems: 'center',
    justifyContent: 'center',
    boxShadow: elevation(c, 2),
  },
  /** Without a camera the frame takes the themed group tint instead of black. */
  cameraAreaIdle: { backgroundColor: c.group, boxShadow: [] },
  camera: { flex: 1, width: '100%' },
  preview: { flex: 1, width: '100%' },
  guideLayer: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center', gap: spacing.lg },
  faceGuide: {
    width: FACE_GUIDE_SIZE,
    height: FACE_GUIDE_SIZE,
    borderRadius: FACE_GUIDE_SIZE / 2,
    borderWidth: 2,
    borderColor: 'rgba(255, 255, 255, 0.7)',
    borderStyle: 'dashed',
  },
  guideText: {
    fontSize: fontSize.sm,
    color: '#FFFFFF',
    fontWeight: '500',
    paddingHorizontal: 14,
    paddingVertical: 6,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(0, 0, 0, 0.45)',
    overflow: 'hidden',
  },
  noPhoto: { alignItems: 'center', gap: spacing.lg, padding: spacing.xl },
  noPhotoIcon: {
    width: 84,
    height: 84,
    borderRadius: 42,
    backgroundColor: c.primary,
    alignItems: 'center',
    justifyContent: 'center',
    boxShadow: '0px 8px 24px rgba(242, 41, 41, 0.45)',
  },
  noPhotoText: { color: ON_CAMERA, textAlign: 'center', fontSize: fontSize.md, lineHeight: 22 },
  idleText: { color: c.textMuted },

  panel: { paddingHorizontal: spacing.lg, paddingTop: spacing.lg, gap: spacing.lg },
  locationRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, minHeight: 64, paddingHorizontal: 14, paddingVertical: 12 },
  locationIcon: { width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center' },
  locationText: { flex: 1, fontSize: fontSize.sm, color: c.text, lineHeight: 20, fontWeight: '500' },
  retry: { paddingHorizontal: 14, paddingVertical: 8, borderRadius: radius.pill, backgroundColor: c.primarySoft },
  retryPressed: { opacity: 0.7 },
  retryText: { color: c.primary, fontWeight: '700', fontSize: fontSize.sm },

  shutter: {
    alignSelf: 'center',
    width: SHUTTER_SIZE,
    height: SHUTTER_SIZE,
    borderRadius: SHUTTER_SIZE / 2,
    padding: 6,
    backgroundColor: dark ? c.inverse : c.card,
    borderWidth: dark ? 0 : 1,
    borderColor: c.border,
    boxShadow: elevation(c, 2),
  },
  shutterPressed: { transform: [{ scale: 0.95 }] },
  shutterDisabled: { opacity: 0.55 },
  shutterInner: {
    flex: 1,
    borderRadius: SHUTTER_SIZE / 2,
    backgroundColor: c.primary,
    backgroundImage: BRAND_GRADIENT,
    boxShadow: BRAND_GLOSS,
    alignItems: 'center',
    justifyContent: 'center',
  },

  actions: { gap: spacing.sm },
}));
