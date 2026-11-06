import { Ionicons } from '@expo/vector-icons';
import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { Image } from 'expo-image';
import * as Location from 'expo-location';
import { router, useLocalSearchParams } from 'expo-router';
import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Linking, Pressable, ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AttendanceModeChips } from '@/components/AttendanceModeChips';
import { type CapturedShot, type LivenessFrames, LivenessStepCard, LivenessThumbnails } from '@/components/LivenessGuide';
import { bottomGap, Button, Group, IconButton, type IconName, Notice, Text } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { useRefreshUser } from '@/hooks/useRefreshUser';
import { ApiError, attendanceApi, type AttendancePurpose, type Company, errorMessage, type LivenessStep, type LivenessStepKey, type UploadFile } from '@/lib/api';
import { formatDistance, nearestLocation, type NearestLocation } from '@/lib/geo';
import { prepareUpload } from '@/lib/image';
import { BRAND_GLOSS, BRAND_GRADIENT, elevation, fontSize, makeStyles, radius, spacing, useTheme } from '@/theme';

/** The camera frame stays black in both color schemes, so its text and guide are light on dark. */
const ON_CAMERA = 'rgba(255, 255, 255, 0.78)';

/** Server message for a mocked position when the policy rejects it; shown before sending too. */
const MOCK_REJECT_MESSAGE = 'Lokasi palsu (mock location) terdeteksi. Matikan aplikasi pemalsu lokasi lalu coba lagi.';

class LocationDeniedError extends Error {
  canAskAgain: boolean;

  constructor(canAskAgain: boolean) {
    super('Izin lokasi ditolak.');
    this.name = 'LocationDeniedError';
    this.canAskAgain = canAskAgain;
  }
}

interface DeviceLocation {
  latitude: number;
  longitude: number;
  accuracy: number | null;
  /** The OS says the position comes from a mock provider or a spoofing tool. */
  mocked: boolean;
  nearest: NearestLocation | null;
}

async function readLocation(company: Company | null): Promise<DeviceLocation> {
  const permission = await Location.requestForegroundPermissionsAsync();
  if (!permission.granted) {
    throw new LocationDeniedError(permission.canAskAgain);
  }
  const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
  const { latitude, longitude, accuracy } = position.coords;
  return {
    latitude,
    longitude,
    accuracy: accuracy ?? null,
    mocked: position.mocked === true,
    nearest: nearestLocation(company, latitude, longitude),
  };
}

interface FetchedChallenge {
  challenge_id: string;
  steps: LivenessStep[];
  expires_in: number;
  /** Device clock when the challenge arrived; the countdown uses it to stay clear of clock skew. */
  receivedAt: number;
}

/** Errors the user cannot fix by sending the same data again: they must retake or change something. */
function isRuleError(error: unknown): error is ApiError {
  return error instanceof ApiError && (error.status === 422 || error.status === 409);
}

/** A 422 about the selfie itself: missing, unreadable or not matching the master face. */
function isPhotoError(error: unknown): boolean {
  if (!(error instanceof ApiError) || error.status !== 422) {
    return false;
  }
  return Boolean(error.errors?.photo) || /^(Verifikasi wajah gagal|Wajah tidak terdeteksi)/.test(error.detail);
}

export default function AttendanceCheckScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { mode } = useLocalSearchParams<{ mode?: string }>();
  const isCheckIn = mode !== 'out';
  const purpose: AttendancePurpose = isCheckIn ? 'check_in' : 'check_out';
  const access = useAccess();
  const refreshUser = useRefreshUser();
  const { mutate: reloadPolicy } = refreshUser;
  const queryClient = useQueryClient();

  // The attendance rules can change in the panel at any time; read them fresh every time the
  // screen opens so the flow never asks for less (or more) than the server will enforce.
  useEffect(() => {
    reloadPolicy();
  }, [reloadPolicy]);

  const policy = access.company?.policy ?? null;
  const needsGps = Boolean(policy?.require_gps);
  const selfieMode = attendanceApi.selfieModeOf(policy);
  const needsCamera = selfieMode !== 'none';
  const isLiveness = selfieMode === 'liveness';
  const missingMasterFace = (selfieMode === 'face' || isLiveness) && !access.masterFacePhoto;
  const mockAction = policy?.mock_location_action ?? 'reject';

  const [cameraPermission, requestCameraPermission] = useCameraPermissions();
  const cameraRef = useRef<CameraView>(null);
  const [cameraReady, setCameraReady] = useState(false);
  const [capturing, setCapturing] = useState(false);
  const [shot, setShot] = useState<CapturedShot | null>(null);
  const [frames, setFrames] = useState<LivenessFrames>({});
  const [challengeAttempt, setChallengeAttempt] = useState(0);
  const [livenessNotice, setLivenessNotice] = useState<string | null>(null);
  const [now, setNow] = useState(0);

  const location = useQuery({
    queryKey: ['device-location', access.company?.id ?? 0, mode],
    queryFn: () => readLocation(access.company),
    enabled: needsGps,
    staleTime: 0,
    gcTime: 0,
    retry: false,
  });

  const challenge = useQuery({
    queryKey: ['liveness-challenge', purpose, challengeAttempt],
    queryFn: async (): Promise<FetchedChallenge> => {
      try {
        const fetched = await attendanceApi.livenessChallenge(purpose);
        return { challenge_id: fetched.challenge_id, steps: fetched.steps, expires_in: fetched.expires_in, receivedAt: Date.now() };
      } catch (error) {
        if (error instanceof ApiError && error.status === 422) {
          // The policy changed in the panel; reload it so the screen switches to the new mode.
          refreshUser.mutate();
        }
        throw error;
      }
    },
    enabled: isLiveness && !missingMasterFace,
    staleTime: Infinity,
    gcTime: 0,
    retry: false,
    refetchOnReconnect: false,
    refetchOnWindowFocus: false,
  });

  useEffect(() => {
    if (needsCamera && !missingMasterFace && cameraPermission && !cameraPermission.granted && cameraPermission.canAskAgain) {
      void requestCameraPermission();
    }
  }, [needsCamera, missingMasterFace, cameraPermission, requestCameraPermission]);

  /** Drops the current challenge and asks for a new one; frames are kept only when the user did nothing wrong. */
  const restartChallenge = useCallback((keepFrames: boolean, notice: string | null) => {
    if (!keepFrames) {
      setFrames({});
    }
    setLivenessNotice(notice);
    setChallengeAttempt((attempt) => attempt + 1);
  }, []);

  const submit = useMutation({
    mutationFn: async () => {
      const payload: attendanceApi.CheckPayload = {};
      if (needsGps && location.data) {
        payload.latitude = location.data.latitude;
        payload.longitude = location.data.longitude;
        payload.accuracy = location.data.accuracy;
        payload.isMocked = location.data.mocked;
      }
      const prefix = isCheckIn ? 'check-in' : 'check-out';
      if ((selfieMode === 'photo' || selfieMode === 'face') && shot) {
        payload.photo = await prepareUpload(shot.uri, prefix, shot.width, shot.height);
      }
      if (isLiveness) {
        const active = challenge.data;
        if (!active) {
          throw new Error('Verifikasi liveness belum siap. Tunggu sebentar lalu coba lagi.');
        }
        const uploads: UploadFile[] = [];
        for (const [index, step] of active.steps.entries()) {
          const frame = frames[step.key];
          if (!frame) {
            throw new Error(`Foto langkah "${step.label}" belum diambil.`);
          }
          uploads.push(await prepareUpload(frame.uri, `${prefix}-liveness-${index + 1}-${step.key}`, frame.width, frame.height));
        }
        payload.livenessChallengeId = active.challenge_id;
        payload.livenessFrames = uploads;
      }
      return isCheckIn ? attendanceApi.checkIn(payload) : attendanceApi.checkOut(payload);
    },
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['attendance'] });
      await queryClient.invalidateQueries({ queryKey: ['report'] });
      const waiting = response.requires_approval === true || response.attendance.status === 'pending_approval';
      const recorded = isCheckIn ? 'Absen masuk tercatat' : 'Absen pulang tercatat';
      Alert.alert(waiting ? `${recorded}, menunggu persetujuan` : recorded, response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => {
      if (error instanceof ApiError && error.status === 409) {
        void queryClient.invalidateQueries({ queryKey: ['attendance'] });
      }
      if (error instanceof ApiError && error.status === 422) {
        // The rules may have changed in the panel since the app last loaded the policy.
        refreshUser.mutate();
      }
      if (isLiveness) {
        // The server uses a challenge once, so every attempt after a failure needs a new one.
        if (error instanceof ApiError && error.status === 422) {
          restartChallenge(false, null);
        } else {
          restartChallenge(true, null);
        }
      } else if (isPhotoError(error)) {
        setShot(null);
      }
    },
  });

  const challengeData = isLiveness ? challenge.data ?? null : null;
  const expiresAt = challengeData ? challengeData.receivedAt + challengeData.expires_in * 1000 : null;
  const countdownPaused = submit.isPending || submit.isSuccess;

  useEffect(() => {
    if (expiresAt === null || countdownPaused) {
      return;
    }
    const timer = setInterval(() => {
      const current = Date.now();
      setNow(current);
      if (current >= expiresAt) {
        restartChallenge(false, 'Waktu verifikasi habis. Ikuti langkah baru dari awal.');
      }
    }, 1000);
    return () => clearInterval(timer);
  }, [expiresAt, countdownPaused, restartChallenge]);

  const steps = challengeData?.steps ?? [];
  const currentStep = steps.find((step) => !frames[step.key]) ?? null;
  const livenessComplete = steps.length > 0 && currentStep === null;
  const remainingSeconds = challengeData && expiresAt !== null
    ? Math.min(challengeData.expires_in, Math.max(0, Math.ceil((expiresAt - now) / 1000)))
    : 0;

  const capture = async () => {
    if (!cameraRef.current || !cameraReady || capturing) {
      return;
    }
    const stepKey: LivenessStepKey | null = currentStep?.key ?? null;
    if (isLiveness && !stepKey) {
      return;
    }
    setCapturing(true);
    try {
      const picture = await cameraRef.current.takePictureAsync({ quality: 0.9, shutterSound: false });
      if (picture) {
        const captured: CapturedShot = { uri: picture.uri, width: picture.width, height: picture.height };
        if (isLiveness && stepKey) {
          setFrames((previous) => ({ ...previous, [stepKey]: captured }));
          setLivenessNotice(null);
        } else {
          setShot(captured);
        }
        if (submit.isError) {
          submit.reset();
        }
      }
    } catch (error) {
      Alert.alert('Gagal mengambil foto', errorMessage(error));
    } finally {
      setCapturing(false);
    }
  };

  const retakeFrame = (key: LivenessStepKey) => {
    setFrames((previous) => {
      const next = { ...previous };
      delete next[key];
      return next;
    });
  };

  const mocked = needsGps && location.data?.mocked === true;
  const mockBlocked = mocked && mockAction === 'reject';
  const locationBlocked = needsGps && (!location.data || mockBlocked);
  const photoBlocked = (selfieMode === 'photo' || selfieMode === 'face') && !shot;
  const livenessBlocked = isLiveness && (!challengeData || !livenessComplete);
  const canSubmit = !missingMasterFace && !locationBlocked && !photoBlocked && !livenessBlocked && !submit.isPending;

  const title = isCheckIn ? 'Absen Masuk' : 'Absen Pulang';
  const cameraUsable = needsCamera && !missingMasterFace && cameraPermission?.granted === true;
  const previewUri = isLiveness
    ? livenessComplete
      ? (frames.front ?? frames[steps[0]?.key ?? 'front'])?.uri ?? null
      : null
    : shot?.uri ?? null;
  const awaitingCapture = cameraUsable && !previewUri && (!isLiveness || (challengeData !== null && currentStep !== null));
  const shutterDisabled = !cameraReady || capturing || submit.isPending;

  const submitError = submit.isError ? submit.error : null;
  const errorHint = !submitError
    ? null
    : isLiveness && submitError instanceof ApiError && submitError.status === 422
      ? 'Ambil ulang foto mengikuti langkah baru di layar.'
      : isPhotoError(submitError)
        ? 'Ambil ulang foto selfie dengan wajah terlihat jelas, lalu kirim lagi.'
        : null;
  const canRetrySubmit = Boolean(submitError) && !isRuleError(submitError);

  const renderCameraArea = (): ReactNode => {
    if (!needsCamera) {
      return (
        <View style={styles.idle}>
          <View style={styles.idleIcon}>
            <Ionicons name={needsGps ? 'location' : 'finger-print'} size={40} color={colors.onPrimary} />
          </View>
          <Text style={[styles.idleText, styles.idleTextThemed]}>
            {needsGps
              ? 'Perusahaan tidak mewajibkan foto selfie. Pastikan lokasi sudah terbaca lalu kirim.'
              : `Presensi cukup dengan satu klik. Tekan tombol di bawah untuk mencatat ${isCheckIn ? 'jam masuk' : 'jam pulang'}.`}
          </Text>
        </View>
      );
    }
    if (missingMasterFace) {
      return (
        <View style={styles.idle}>
          <Ionicons name="scan-outline" size={56} color={ON_CAMERA} />
          <Text style={styles.idleText}>
            Perusahaan mewajibkan verifikasi wajah. Daftarkan Foto Master Wajah dulu agar selfie presensi bisa dicocokkan.
          </Text>
        </View>
      );
    }
    if (!cameraPermission) {
      return <ActivityIndicator color={ON_CAMERA} />;
    }
    if (!cameraPermission.granted) {
      return (
        <View style={styles.idle}>
          <Ionicons name="camera-outline" size={56} color={ON_CAMERA} />
          <Text style={styles.idleText}>
            {isLiveness ? 'Izin kamera dibutuhkan untuk verifikasi wajah dengan gerakan kepala.' : 'Izin kamera dibutuhkan untuk foto selfie presensi.'}
          </Text>
          {cameraPermission.canAskAgain ? (
            <Button title="Izinkan kamera" onPress={() => requestCameraPermission()} />
          ) : (
            <Button title="Buka pengaturan" icon="settings-outline" onPress={() => Linking.openSettings()} />
          )}
        </View>
      );
    }
    return (
      <>
        <CameraView
          ref={cameraRef}
          style={styles.camera}
          facing="front"
          mirror
          active={!previewUri}
          onCameraReady={() => setCameraReady(true)}
        />
        {previewUri ? <Image source={{ uri: previewUri }} style={styles.fill} contentFit="cover" /> : null}
        {isLiveness ? (
          <View style={[styles.fill, styles.livenessLayer]} pointerEvents="box-none">
            {challengeData ? (
              <>
                <LivenessStepCard steps={steps} current={currentStep} remainingSeconds={remainingSeconds} />
                {currentStep ? <View style={styles.livenessGuide} pointerEvents="none" /> : <View />}
                <LivenessThumbnails steps={steps} frames={frames} current={currentStep} onRetake={retakeFrame} disabled={submit.isPending || capturing} />
              </>
            ) : challenge.isError ? (
              <View style={styles.overlayCenter}>
                <Ionicons name="alert-circle-outline" size={44} color={ON_CAMERA} />
                <Text style={styles.idleText}>{errorMessage(challenge.error)}</Text>
                <Button title="Coba lagi" icon="refresh" variant="secondary" onPress={() => restartChallenge(true, null)} />
              </View>
            ) : (
              <View style={styles.overlayCenter}>
                <ActivityIndicator color={ON_CAMERA} />
                <Text style={styles.idleText}>Menyiapkan verifikasi wajah…</Text>
              </View>
            )}
          </View>
        ) : !previewUri ? (
          <View style={[styles.fill, styles.guideLayer]} pointerEvents="none">
            <View style={styles.faceGuide} />
            <Text style={styles.guideText}>Posisikan wajah di dalam lingkaran</Text>
          </View>
        ) : null}
      </>
    );
  };

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
      <AttendanceModeChips policy={policy} style={styles.chips} />

      <View style={[styles.cameraArea, (!needsCamera || missingMasterFace) && styles.cameraAreaIdle, !needsCamera && styles.cameraAreaThemed]}>
        {renderCameraArea()}
      </View>

      <ScrollView style={styles.details} contentContainerStyle={styles.detailsContent} bounces={false} keyboardShouldPersistTaps="handled">
        {needsGps ? (
          <Group title="Lokasi">
            <LocationStatus location={location} />
          </Group>
        ) : null}

        {mocked ? (
          <Notice
            tone={mockBlocked ? 'danger' : 'warning'}
            icon="navigate-circle-outline"
            message={mockBlocked ? MOCK_REJECT_MESSAGE : 'Lokasi palsu (mock location) terdeteksi. Presensi tetap bisa dikirim, tetapi akan menunggu persetujuan atasan.'}
          />
        ) : null}

        {livenessNotice ? <Notice tone="info" icon="time-outline" message={livenessNotice} /> : null}

        {submitError ? (
          <CheckError
            message={errorMessage(submitError)}
            hint={errorHint}
            onRetry={canRetrySubmit ? () => submit.mutate() : undefined}
            retryDisabled={!canSubmit}
          />
        ) : null}
      </ScrollView>

      <View style={[styles.actionsArea, { paddingBottom: bottomGap(insets.bottom) }]}>
        {missingMasterFace ? (
          <Button title="Daftarkan Foto Master Wajah" icon="scan-outline" size="lg" onPress={() => router.replace('/attendance/master-face')} />
        ) : awaitingCapture ? (
          <Pressable
            onPress={capture}
            disabled={shutterDisabled}
            accessibilityRole="button"
            accessibilityLabel={isLiveness && currentStep ? `Ambil foto: ${currentStep.label}` : 'Ambil foto'}
            accessibilityState={{ disabled: shutterDisabled, busy: capturing }}
            style={({ pressed }) => [styles.shutter, pressed && styles.shutterPressed, shutterDisabled && styles.shutterDisabled]}>
            <View style={styles.shutterInner}>
              {capturing ? <ActivityIndicator color={colors.onPrimary} /> : <Ionicons name="camera" size={28} color={colors.onPrimary} />}
            </View>
          </Pressable>
        ) : (
          <View style={styles.actions}>
            {selfieMode === 'photo' || selfieMode === 'face' ? (
              <Button title="Ulangi foto" variant="secondary" icon="refresh" onPress={() => setShot(null)} disabled={submit.isPending || !shot} />
            ) : null}
            {isLiveness && cameraUsable ? (
              <Button
                title="Ulangi semua langkah"
                variant="secondary"
                icon="refresh"
                onPress={() => restartChallenge(false, null)}
                disabled={submit.isPending || challenge.isFetching}
              />
            ) : null}
            <Button title={`Kirim ${title}`} icon="send" size="lg" onPress={() => submit.mutate()} disabled={!canSubmit} loading={submit.isPending} />
          </View>
        )}
      </View>
    </View>
  );
}

/** Inline error above the actions: the server's reason, what to do next and, when it helps, a retry. */
function CheckError({ message, hint, onRetry, retryDisabled }: { message: string; hint: string | null; onRetry?: () => void; retryDisabled: boolean }) {
  const styles = useStyles();
  const { colors } = useTheme();
  return (
    <View style={styles.error} accessibilityRole="alert">
      <Ionicons name="alert-circle" size={20} color={colors.danger} />
      <View style={styles.errorBody}>
        <Text style={styles.errorText}>{message}</Text>
        {hint ? <Text style={styles.errorHint}>{hint}</Text> : null}
      </View>
      {onRetry ? (
        <Pressable
          onPress={onRetry}
          disabled={retryDisabled}
          hitSlop={8}
          accessibilityRole="button"
          style={({ pressed }) => [styles.errorRetry, pressed && styles.retryPressed, retryDisabled && styles.retryDisabled]}>
          <Text style={styles.errorRetryText}>Coba lagi</Text>
        </Pressable>
      ) : null}
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

function RetryPill({ label, onPress }: { label: string; onPress: () => void }) {
  const styles = useStyles();
  return (
    <Pressable onPress={onPress} hitSlop={8} accessibilityRole="button" style={({ pressed }) => [styles.retry, pressed && styles.retryPressed]}>
      <Text style={styles.retryText}>{label}</Text>
    </Pressable>
  );
}

function LocationStatus({ location }: { location: UseQueryResult<DeviceLocation> }) {
  const { colors } = useTheme();

  if (location.isPending) {
    return <LocationLine loading tint={colors.primary} background={colors.primarySoft} message="Membaca lokasi…" />;
  }
  if (location.isError) {
    if (location.error instanceof LocationDeniedError) {
      const canAskAgain = location.error.canAskAgain;
      return (
        <LocationLine
          icon="location-outline"
          tint={colors.danger}
          background={colors.dangerSoft}
          message="Izin lokasi ditolak. Presensi butuh lokasi, aktifkan izin lokasi untuk aplikasi ini."
          right={canAskAgain ? <RetryPill label="Izinkan" onPress={() => location.refetch()} /> : <RetryPill label="Pengaturan" onPress={() => Linking.openSettings()} />}
        />
      );
    }
    return (
      <LocationLine
        icon="alert-circle"
        tint={colors.danger}
        background={colors.dangerSoft}
        message={`Lokasi gagal dibaca. ${errorMessage(location.error)}`}
        right={<RetryPill label="Ulangi" onPress={() => location.refetch()} />}
      />
    );
  }
  const reread = location.isFetching ? undefined : <RetryPill label="Perbarui" onPress={() => location.refetch()} />;
  if (location.data.mocked) {
    return <LocationLine icon="navigate-circle-outline" tint={colors.danger} background={colors.dangerSoft} message="Lokasi terbaca dari aplikasi pemalsu lokasi." right={reread} />;
  }
  const accuracy = location.data.accuracy !== null ? ` Akurasi ±${formatDistance(location.data.accuracy)}.` : '';
  const nearest = location.data.nearest;
  if (!nearest) {
    return <LocationLine icon="location" tint={colors.success} background={colors.successSoft} message={`Lokasi terbaca.${accuracy}`} right={reread} />;
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
      right={reread}
    />
  );
}

const SHUTTER_SIZE = 84;
const FACE_GUIDE_SIZE = 232;

const useStyles = makeStyles((c, dark) => ({
  root: { flex: 1, backgroundColor: c.background },
  topBar: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingHorizontal: spacing.lg, paddingBottom: spacing.sm },
  titlePill: { flex: 1, height: 46, borderRadius: radius.pill, backgroundColor: c.fill, justifyContent: 'center', paddingHorizontal: 20 },
  title: { fontSize: fontSize.md, color: c.textMuted, fontWeight: '500' },
  chips: { paddingHorizontal: spacing.lg, paddingBottom: spacing.md },

  cameraArea: {
    flex: 1,
    minHeight: 260,
    marginHorizontal: spacing.lg,
    borderRadius: 32,
    overflow: 'hidden',
    backgroundColor: '#000000',
    alignItems: 'center',
    justifyContent: 'center',
    boxShadow: elevation(c, 2),
  },
  /** Without a live camera the frame drops its shadow. */
  cameraAreaIdle: { boxShadow: [] },
  /** Without any camera at all the frame takes the themed group tint instead of black. */
  cameraAreaThemed: { backgroundColor: c.group },
  camera: { flex: 1, width: '100%' },
  fill: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 },
  guideLayer: { alignItems: 'center', justifyContent: 'center', gap: spacing.lg },
  livenessLayer: { alignItems: 'center', justifyContent: 'space-between', padding: spacing.md },
  overlayCenter: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.lg, padding: spacing.lg },
  faceGuide: {
    width: FACE_GUIDE_SIZE,
    height: FACE_GUIDE_SIZE,
    borderRadius: FACE_GUIDE_SIZE / 2,
    borderWidth: 2,
    borderColor: 'rgba(255, 255, 255, 0.7)',
    borderStyle: 'dashed',
  },
  /** Taller, narrower guide between the stepper and the thumbnails; shrinks on short screens. */
  livenessGuide: {
    width: 170,
    height: 214,
    maxHeight: '42%',
    borderRadius: radius.pill,
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
  idle: { alignItems: 'center', gap: spacing.lg, padding: spacing.xl },
  idleIcon: {
    width: 84,
    height: 84,
    borderRadius: 42,
    backgroundColor: c.primary,
    alignItems: 'center',
    justifyContent: 'center',
    boxShadow: '0px 8px 24px rgba(242, 41, 41, 0.45)',
  },
  idleText: { color: ON_CAMERA, textAlign: 'center', fontSize: fontSize.md, lineHeight: 22 },
  idleTextThemed: { color: c.textMuted },

  details: { flexGrow: 0, flexShrink: 1 },
  detailsContent: { paddingHorizontal: spacing.lg, paddingTop: spacing.lg, gap: spacing.md },
  locationRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, minHeight: 64, paddingHorizontal: 14, paddingVertical: 12 },
  locationIcon: { width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center' },
  locationText: { flex: 1, fontSize: fontSize.sm, color: c.text, lineHeight: 20, fontWeight: '500' },
  retry: { paddingHorizontal: 14, paddingVertical: 8, borderRadius: radius.pill, backgroundColor: c.primarySoft },
  retryPressed: { opacity: 0.7 },
  retryDisabled: { opacity: 0.45 },
  retryText: { color: c.primary, fontWeight: '700', fontSize: fontSize.sm },

  error: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    paddingHorizontal: 16,
    paddingVertical: 14,
    borderRadius: radius.lg,
    backgroundColor: c.dangerSoft,
  },
  errorBody: { flex: 1, gap: 4 },
  errorText: { fontSize: fontSize.sm, lineHeight: 20, fontWeight: '600', color: c.danger },
  errorHint: { fontSize: fontSize.sm, lineHeight: 20, color: c.text },
  errorRetry: { paddingHorizontal: 12, paddingVertical: 6, borderRadius: radius.pill, backgroundColor: c.card },
  errorRetryText: { color: c.danger, fontWeight: '700', fontSize: fontSize.sm },

  actionsArea: { paddingHorizontal: spacing.lg, paddingTop: spacing.lg },
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
