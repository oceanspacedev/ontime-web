import { Ionicons } from '@expo/vector-icons';
import { useMutation, useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Alert, Pressable, Switch, View } from 'react-native';

import { DateTimeField } from '@/components/DateTimeField';
import { Badge, Button, Choice, EmptyState, ErrorBanner, Field, Group, LoadingView, Notice, Screen, Text } from '@/components/ui';
import {
  ApiError,
  type ClientPolicyInput,
  type ClientPolicyResponse,
  clientAdminApi,
  errorMessage,
  type FaceFailAction,
  type MockLocationAction,
  type PolicySelfieMode,
  type SelfieModeOption,
} from '@/lib/api';
import { useClientAdminSaved } from '@/hooks/useClientAdminSaved';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import { queryKeys } from '@/lib/queryClient';
import { fontSize, makeStyles, radius, spacing, useTheme } from '@/theme';

/** Same rule and wording as the server: GPS needs the company's own map pin or an active location. */
const GPS_NEEDS_LOCATION = 'Wajib Lokasi GPS membutuhkan titik lokasi badan usaha atau minimal satu lokasi absensi aktif.';

/** Same short description the server builds in CompanyPolicy::modeLabel, so the summary follows the form. */
function modeLabelFor(requireGps: boolean, selfieMode: PolicySelfieMode): string {
  const parts: string[] = requireGps ? ['GPS'] : [];
  if (selfieMode === 'liveness') {
    parts.push('Liveness', 'Wajah');
  } else if (selfieMode === 'face') {
    parts.push('Foto', 'Wajah');
  } else if (selfieMode === 'photo') {
    parts.push('Foto');
  }
  return parts.length ? parts.join(' + ') : 'Klik saja';
}

function usesFaceMatch(mode: PolicySelfieMode): boolean {
  return mode === 'face' || mode === 'liveness';
}

/** Digits only, so number fields never hold a stray comma or minus sign. */
function digits(value: string): string {
  return value.replace(/[^0-9]/g, '');
}

/** The attendance policy of one company: the same settings as the Kebijakan Absensi action in the web panel. */
export default function ClientPolicyScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const companyId = Number(id);
  const policy = useQuery({
    queryKey: queryKeys.clientPolicy(companyId),
    queryFn: () => clientAdminApi.policy(companyId),
    enabled: Number.isFinite(companyId) && companyId > 0,
  });

  if (!Number.isFinite(companyId) || companyId <= 0) {
    return (
      <Screen>
        <EmptyState icon="storefront-outline" title="Badan usaha tidak ditemukan" message="Badan usaha ini tidak ada atau bukan wewenang Anda." />
      </Screen>
    );
  }
  if (policy.isPending) {
    return (
      <Screen>
        <LoadingView />
      </Screen>
    );
  }
  if (policy.isError) {
    if (policy.error instanceof ApiError && (policy.error.status === 403 || policy.error.status === 404)) {
      return (
        <Screen>
          <EmptyState icon="lock-closed-outline" title="Tidak bisa dibuka" message={errorMessage(policy.error)} />
        </Screen>
      );
    }
    return (
      <Screen>
        <ErrorBanner message={errorMessage(policy.error)} onRetry={() => policy.refetch()} />
      </Screen>
    );
  }

  return <PolicyEditor companyId={companyId} response={policy.data} />;
}

function PolicyEditor({ companyId, response }: { companyId: number; response: ClientPolicyResponse }) {
  const styles = useStyles();
  const { colors } = useTheme();
  const onClientSaved = useClientAdminSaved();
  const { data: initial, options, has_locations: hasLocations } = response;

  const [workStart, setWorkStart] = useState(initial.work_start_time);
  const [workEnd, setWorkEnd] = useState(initial.work_end_time);
  const [lateTolerance, setLateTolerance] = useState(String(initial.late_tolerance_minutes));
  const [requireGps, setRequireGps] = useState(initial.require_gps);
  const [geofenceRadius, setGeofenceRadius] = useState(String(initial.geofence_radius_meters));
  const [mockAction, setMockAction] = useState<MockLocationAction>(initial.mock_location_action);
  const [selfieMode, setSelfieMode] = useState<PolicySelfieMode>(initial.selfie_mode);
  const [faceThreshold, setFaceThreshold] = useState(String(initial.face_match_threshold));
  const [faceFailAction, setFaceFailAction] = useState<FaceFailAction>(initial.face_fail_action);
  const [leaveQuota, setLeaveQuota] = useState(String(initial.annual_leave_quota));
  const [approvalStages, setApprovalStages] = useState(String(initial.default_approval_stages));
  const [errors, setErrors] = useState<FieldErrors>({});

  const gpsBlocked = requireGps && !hasLocations;
  const faceMatch = usesFaceMatch(selfieMode);
  const modeLabel = modeLabelFor(requireGps, selfieMode);
  const selectedMode = options.selfie_modes.find((option) => option.value === selfieMode);

  const save = useMutation({
    mutationFn: (input: ClientPolicyInput) => clientAdminApi.savePolicy(companyId, input),
    onSuccess: async (result) => {
      setErrors({});
      await onClientSaved();
      Alert.alert('Berhasil', result.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const validate = (): boolean => {
    const next: FieldErrors = {};
    const requireNumber = (key: string, label: string, value: string, min: number, max?: number) => {
      if (value === '') {
        next[key] = requiredMessage(label);
        return;
      }
      const number = Number(value);
      if (number < min) {
        next[key] = `${label} minimal ${min}.`;
      } else if (max !== undefined && number > max) {
        next[key] = `${label} maksimal ${max}.`;
      }
    };

    if (!workStart) {
      next.work_start_time = requiredMessage('Jam masuk');
    }
    if (!workEnd) {
      next.work_end_time = requiredMessage('Jam pulang');
    }
    requireNumber('late_tolerance_minutes', 'Toleransi keterlambatan', lateTolerance, 0);
    if (requireGps) {
      if (!hasLocations) {
        next.require_gps = GPS_NEEDS_LOCATION;
      }
      requireNumber('geofence_radius_meters', 'Radius geofence', geofenceRadius, 1);
    }
    if (selectedMode && !selectedMode.available) {
      next.selfie_mode = 'Mode verifikasi ini belum tersedia di server.';
    }
    if (faceMatch) {
      requireNumber('face_match_threshold', 'Ambang kemiripan wajah', faceThreshold, 1, 100);
    }
    requireNumber('annual_leave_quota', 'Kuota cuti tahunan', leaveQuota, 0);
    requireNumber('default_approval_stages', 'Tahap approval', approvalStages, 1);

    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const submit = () => {
    if (!validate()) {
      return;
    }
    save.mutate({
      work_start_time: workStart,
      work_end_time: workEnd,
      late_tolerance_minutes: Number(lateTolerance),
      require_gps: requireGps,
      // Hidden fields keep their last value, the same way the panel sends them.
      geofence_radius_meters: Number(geofenceRadius || initial.geofence_radius_meters),
      mock_location_action: mockAction,
      selfie_mode: selfieMode,
      face_match_threshold: Number(faceThreshold || initial.face_match_threshold),
      face_fail_action: faceFailAction,
      annual_leave_quota: Number(leaveQuota),
      default_approval_stages: Number(approvalStages),
    });
  };

  const clearError = (key: string) => {
    if (errors[key]) {
      setErrors(({ [key]: _removed, ...rest }) => rest);
    }
  };

  const hasFieldErrors = save.error instanceof ApiError && save.error.errors;
  const generalError = save.isError && !hasFieldErrors ? errorMessage(save.error) : null;
  const errorSummary = save.isError && hasFieldErrors ? 'Periksa lagi isian yang ditandai.' : null;

  const addLocation = () => router.push({ pathname: '/client/company/[id]/location', params: { id: String(companyId) } });
  const editCompany = () => router.push({ pathname: '/client/company/[id]/edit', params: { id: String(companyId) } });

  return (
    <Screen
      footer={
        <>
          {generalError ? <Notice tone="danger" message={generalError} /> : null}
          {errorSummary ? <Notice tone="danger" message={errorSummary} /> : null}
          {gpsBlocked ? <Notice tone="warning" message={`${GPS_NEEDS_LOCATION} Tambahkan lokasi atau matikan Wajib Lokasi GPS agar kebijakan bisa disimpan.`} /> : null}
          <Button title="Simpan Kebijakan" icon="checkmark" size="lg" onPress={submit} loading={save.isPending} disabled={gpsBlocked} />
        </>
      }>
      <View style={styles.summary}>
        <View style={styles.summaryIcon}>
          <Ionicons name="shield-checkmark" size={22} color={colors.primary} />
        </View>
        <View style={styles.summaryBody}>
          <Text style={styles.summaryLabel}>Mode absensi</Text>
          <Text style={styles.summaryValue}>{modeLabel}</Text>
        </View>
        {modeLabel !== initial.mode_label ? <Badge label="Belum disimpan" tone="warning" /> : null}
      </View>

      <Group title="Jam Kerja" padded>
        <View style={styles.pair}>
          <View style={styles.pairItem}>
            <DateTimeField
              label="Jam masuk"
              mode="time"
              value={workStart || null}
              onChange={(value) => {
                setWorkStart(value);
                clearError('work_start_time');
              }}
              error={errors.work_start_time}
            />
          </View>
          <View style={styles.pairItem}>
            <DateTimeField
              label="Jam pulang"
              mode="time"
              value={workEnd || null}
              onChange={(value) => {
                setWorkEnd(value);
                clearError('work_end_time');
              }}
              error={errors.work_end_time}
            />
          </View>
        </View>
        <Field
          label="Toleransi keterlambatan (menit)"
          value={lateTolerance}
          onChangeText={(value) => {
            setLateTolerance(digits(value));
            clearError('late_tolerance_minutes');
          }}
          keyboardType="number-pad"
          placeholder="15"
          hint="Presensi lewat dari jam masuk + toleransi dihitung terlambat."
          error={errors.late_tolerance_minutes}
          maxLength={4}
        />
      </Group>

      <Group title="Lokasi" padded>
        <View style={styles.switchRow}>
          <View style={styles.switchBody}>
            <Text style={styles.switchTitle}>Wajib lokasi GPS</Text>
            <Text style={styles.switchHint}>Pekerja harus berada di dalam radius lokasi absensi saat presensi.</Text>
          </View>
          <Switch
            value={requireGps}
            onValueChange={(value) => {
              setRequireGps(value);
              clearError('require_gps');
            }}
            disabled={!hasLocations && !requireGps}
            trackColor={{ false: colors.fill, true: colors.primary }}
            thumbColor="#FFFFFF"
            ios_backgroundColor={colors.fill}
            accessibilityLabel="Wajib lokasi GPS"
          />
        </View>
        {errors.require_gps ? <Text style={styles.error}>{errors.require_gps}</Text> : null}
        {!hasLocations ? (
          <>
            <Notice tone="warning" message={GPS_NEEDS_LOCATION} />
            <Button title="Tambah Lokasi Absensi" icon="location-outline" variant="secondary" onPress={addLocation} />
            <Button title="Ubah Data Badan Usaha" icon="create-outline" variant="secondary" onPress={editCompany} />
          </>
        ) : null}
        {requireGps ? (
          <>
            <Field
              label="Radius geofence (meter)"
              value={geofenceRadius}
              onChangeText={(value) => {
                setGeofenceRadius(digits(value));
                clearError('geofence_radius_meters');
              }}
              keyboardType="number-pad"
              placeholder="100"
              hint="Dipakai untuk lokasi yang tidak punya radius sendiri."
              error={errors.geofence_radius_meters}
              maxLength={6}
            />
            <View style={styles.choiceField}>
              <Text style={styles.label}>Jika lokasi palsu terdeteksi</Text>
              <Choice
                options={options.mock_location_actions}
                value={mockAction}
                onChange={(value) => {
                  setMockAction(value);
                  clearError('mock_location_action');
                }}
              />
              {errors.mock_location_action ? <Text style={styles.error}>{errors.mock_location_action}</Text> : null}
            </View>
          </>
        ) : null}
      </Group>

      <Group title="Verifikasi Selfie" padded>
        <View style={styles.modes}>
          {options.selfie_modes.map((option) => (
            <SelfieModeCard
              key={option.value}
              option={option}
              selected={option.value === selfieMode}
              onPress={() => {
                setSelfieMode(option.value);
                clearError('selfie_mode');
              }}
            />
          ))}
        </View>
        {errors.selfie_mode ? <Text style={styles.error}>{errors.selfie_mode}</Text> : null}
        {faceMatch ? (
          <>
            <Field
              label="Ambang kemiripan wajah (%)"
              value={faceThreshold}
              onChangeText={(value) => {
                setFaceThreshold(digits(value));
                clearError('face_match_threshold');
              }}
              keyboardType="number-pad"
              placeholder="60"
              hint="Antara 1 dan 100. Makin tinggi makin ketat."
              error={errors.face_match_threshold}
              maxLength={3}
            />
            <View style={styles.choiceField}>
              <Text style={styles.label}>Jika wajah tidak cocok</Text>
              <Choice
                options={options.face_fail_actions}
                value={faceFailAction}
                onChange={(value) => {
                  setFaceFailAction(value);
                  clearError('face_fail_action');
                }}
              />
              {errors.face_fail_action ? <Text style={styles.error}>{errors.face_fail_action}</Text> : null}
            </View>
          </>
        ) : null}
      </Group>

      <Group title="Cuti & Approval" padded>
        <View style={styles.pair}>
          <View style={styles.pairItem}>
            <Field
              label="Kuota cuti (hari)"
              value={leaveQuota}
              onChangeText={(value) => {
                setLeaveQuota(digits(value));
                clearError('annual_leave_quota');
              }}
              keyboardType="number-pad"
              placeholder="12"
              error={errors.annual_leave_quota}
              maxLength={3}
            />
          </View>
          <View style={styles.pairItem}>
            <Field
              label="Tahap approval"
              value={approvalStages}
              onChangeText={(value) => {
                setApprovalStages(digits(value));
                clearError('default_approval_stages');
              }}
              keyboardType="number-pad"
              placeholder="1"
              error={errors.default_approval_stages}
              maxLength={2}
            />
          </View>
        </View>
      </Group>
    </Screen>
  );
}

function SelfieModeCard({ option, selected, onPress }: { option: SelfieModeOption; selected: boolean; onPress: () => void }) {
  const styles = useStyles();
  const { colors } = useTheme();
  // A mode the server cannot offer stays selectable only when it is already stored, so it can be switched away from.
  const disabled = !option.available && !selected;

  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="radio"
      accessibilityState={{ selected, disabled }}
      style={({ pressed }) => [styles.mode, selected && styles.modeSelected, pressed && !selected && styles.modePressed, disabled && styles.modeDisabled]}>
      <Ionicons name={selected ? 'radio-button-on' : 'radio-button-off'} size={22} color={selected ? colors.primary : colors.textSubtle} />
      <View style={styles.modeBody}>
        <Text style={styles.modeTitle}>{option.label}</Text>
        <Text style={styles.modeDescription}>{option.description}</Text>
        {!option.available ? <Text style={styles.modeUnavailable}>Belum tersedia: layanan verifikasi wajah di server belum mendukung mode ini.</Text> : null}
      </View>
    </Pressable>
  );
}

const useStyles = makeStyles((c) => ({
  summary: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, padding: 16, borderRadius: radius.lg, backgroundColor: c.card },
  summaryIcon: { width: 44, height: 44, borderRadius: 22, backgroundColor: c.primarySoft, alignItems: 'center', justifyContent: 'center' },
  summaryBody: { flex: 1 },
  summaryLabel: { fontSize: fontSize.xs, color: c.textMuted, fontWeight: '500' },
  summaryValue: { fontSize: fontSize.lg, color: c.text, fontWeight: '700', marginTop: 2 },
  pair: { flexDirection: 'row', gap: spacing.md },
  pairItem: { flex: 1 },
  switchRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  switchBody: { flex: 1, gap: 2 },
  switchTitle: { fontSize: fontSize.md, color: c.text, fontWeight: '600' },
  switchHint: { fontSize: fontSize.xs, color: c.textMuted, lineHeight: 17 },
  choiceField: { gap: 8 },
  label: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500', paddingHorizontal: 4 },
  error: { fontSize: fontSize.xs, color: c.danger, paddingHorizontal: 4 },
  modes: { gap: spacing.sm },
  mode: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.md, padding: 14, borderRadius: radius.lg, backgroundColor: c.fill, borderWidth: 1.5, borderColor: 'transparent' },
  modeSelected: { borderColor: c.primary, backgroundColor: c.primarySoft },
  modePressed: { backgroundColor: c.fillPressed },
  modeDisabled: { opacity: 0.5 },
  modeBody: { flex: 1, gap: 2 },
  modeTitle: { fontSize: fontSize.md, color: c.text, fontWeight: '600' },
  modeDescription: { fontSize: fontSize.xs, color: c.textMuted, lineHeight: 17 },
  modeUnavailable: { fontSize: fontSize.xs, color: c.warning, fontWeight: '500', marginTop: 4, lineHeight: 17 },
}));
