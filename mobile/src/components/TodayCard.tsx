import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { View } from 'react-native';

import { Badge, Button, Group, Notice, Text } from '@/components/ui';
import type { Access } from '@/lib/access';
import { type Attendance, attendanceApi, type TodayAttendance } from '@/lib/api';
import { attendanceStatusLabel, attendanceStatusTone, formatDate, formatTime } from '@/lib/format';
import { fontSize, makeStyles, spacing, useTheme } from '@/theme';

interface Props {
  today: TodayAttendance;
  access: Access;
}

/** Group title shared with the home screen's loading state, so both render the same frame. */
export const TODAY_GROUP_TITLE = 'Presensi Hari Ini';

/** Why today's record waits for approval, in the server's own wording for approval reasons. */
function pendingApprovalMessage(attendance: Attendance): string {
  const reasons: string[] = [];
  if (attendance.is_out_of_bounds) {
    reasons.push('di luar area kantor');
  }
  if (attendance.is_mock_location) {
    reasons.push('lokasi palsu terdeteksi');
  }
  if (attendance.is_face_verified === false) {
    reasons.push('verifikasi wajah tidak cocok');
  }
  if (reasons.length === 0) {
    return 'Presensi hari ini menunggu persetujuan atasan.';
  }
  return `Presensi hari ini menunggu persetujuan atasan karena ${reasons.join(' dan ')}.`;
}

export function TodayCard({ today, access }: Props) {
  const styles = useStyles();
  const { colors } = useTheme();
  const attendance = today.attendance;
  const policy = access.company?.policy ?? null;
  const selfieMode = attendanceApi.selfieModeOf(policy);
  const needsMasterFace = (selfieMode === 'face' || selfieMode === 'liveness') && !access.masterFacePhoto;
  const done = today.has_checked_in && today.has_checked_out;
  const schedule = today.schedule ?? null;
  const workStart = schedule?.start_time ?? policy?.work_start_time ?? null;
  const workEnd = schedule?.end_time ?? policy?.work_end_time ?? null;

  return (
    <Group title={TODAY_GROUP_TITLE} padded cardStyle={styles.card}>
      <View style={styles.header}>
        <View style={styles.headerText}>
          <Text style={styles.date} numberOfLines={1}>
            {formatDate(today.date, 'weekday')}
          </Text>
          {schedule?.is_day_off ? (
            <View style={styles.scheduleRow}>
              <Ionicons name="calendar-outline" size={14} color={colors.textMuted} />
              <Text style={styles.schedule}>Hari ini dijadwalkan libur</Text>
            </View>
          ) : workStart ? (
            <View style={styles.scheduleRow}>
              <Ionicons name="time-outline" size={14} color={colors.textMuted} />
              <Text style={styles.schedule} numberOfLines={1}>
                {schedule?.shift ? `Shift ${schedule.shift.name} · ` : 'Jam kerja '}
                {formatTime(workStart)}
                {workEnd ? ` – ${formatTime(workEnd)}` : ''}
              </Text>
            </View>
          ) : null}
          {schedule?.outlet && !schedule.is_day_off ? (
            <View style={styles.scheduleRow}>
              <Ionicons name="storefront-outline" size={14} color={colors.textMuted} />
              <Text style={styles.schedule} numberOfLines={1}>
                {[schedule.outlet.name, schedule.section].filter(Boolean).join(' · ')}
              </Text>
            </View>
          ) : null}
        </View>
        {attendance ? (
          <Badge
            label={attendance.late_minutes ? `Telat ${attendance.late_minutes} mnt` : attendanceStatusLabel(attendance.status)}
            tone={attendanceStatusTone(attendance.status)}
          />
        ) : null}
      </View>

      <View style={[styles.times, done && styles.timesLast]}>
        <TimeHalf label="Masuk" value={attendance?.check_in} icon="log-in-outline" />
        <View style={styles.divider} />
        <TimeHalf label="Pulang" value={attendance?.check_out} icon="log-out-outline" />
      </View>

      {attendance?.status === 'pending_approval' ? (
        <Notice tone="warning" message={pendingApprovalMessage(attendance)} />
      ) : null}

      {needsMasterFace ? (
        <Notice
          tone="warning"
          icon="scan-outline"
          message={
            selfieMode === 'liveness'
              ? 'Perusahaan mewajibkan verifikasi wajah dengan gerakan kepala (liveness). Daftarkan foto master wajah dulu sebelum absen.'
              : 'Perusahaan mewajibkan verifikasi wajah. Daftarkan foto master wajah dulu sebelum absen.'
          }
        />
      ) : null}

      {done ? null : needsMasterFace ? (
        <Button title="Daftarkan Foto Master Wajah" icon="scan-outline" size="lg" onPress={() => router.push('/attendance/master-face')} />
      ) : !today.has_checked_in ? (
        <Button title="Absen Masuk" icon="log-in-outline" size="lg" onPress={() => router.push({ pathname: '/attendance/check', params: { mode: 'in' } })} />
      ) : (
        <Button
          title="Absen Pulang"
          icon="log-out-outline"
          size="lg"
          variant="inverse"
          onPress={() => router.push({ pathname: '/attendance/check', params: { mode: 'out' } })}
        />
      )}
    </Group>
  );
}

/** One half of the Masuk | Pulang overview: a small label and a large tabular time. */
function TimeHalf({ label, value, icon }: { label: string; value: string | null | undefined; icon: 'log-in-outline' | 'log-out-outline' }) {
  const styles = useStyles();
  const { colors } = useTheme();
  return (
    <View style={styles.half}>
      <View style={styles.halfLabelRow}>
        <Ionicons name={icon} size={16} color={colors.textMuted} />
        <Text style={styles.halfLabel}>{label}</Text>
      </View>
      <Text style={[styles.halfValue, !value && styles.halfValueEmpty]} numberOfLines={1} adjustsFontSizeToFit>
        {formatTime(value)}
      </Text>
    </View>
  );
}

const useStyles = makeStyles((c) => ({
  card: { gap: spacing.lg },
  header: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.sm },
  headerText: { flex: 1, gap: 4 },
  date: { fontSize: fontSize.lg, fontWeight: '600', color: c.text },
  scheduleRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  schedule: { flexShrink: 1, fontSize: fontSize.sm, color: c.textMuted },
  times: {
    flexDirection: 'row',
    alignItems: 'stretch',
    paddingVertical: spacing.md,
    borderTopWidth: 1,
    borderBottomWidth: 1,
    borderColor: c.separator,
  },
  /** Nothing follows the times once the day is done, so no closing line. */
  timesLast: { borderBottomWidth: 0, paddingBottom: 0 },
  half: { flex: 1, gap: 6, paddingHorizontal: spacing.xs },
  halfLabelRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  halfLabel: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500' },
  halfValue: { fontSize: 34, fontWeight: '700', color: c.text, letterSpacing: -0.6, fontVariant: ['tabular-nums'] },
  halfValueEmpty: { color: c.textSubtle },
  divider: { width: 1, backgroundColor: c.separator, marginHorizontal: spacing.lg },
}));
