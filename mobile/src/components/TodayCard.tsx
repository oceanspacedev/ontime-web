import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';

import { Badge, Button, Card, Notice } from '@/components/ui';
import type { Access } from '@/lib/access';
import type { TodayAttendance } from '@/lib/api';
import { attendanceStatusLabel, attendanceStatusTone, formatDate, formatTime } from '@/lib/format';
import { colors, fontSize, spacing } from '@/theme';

interface Props {
  today: TodayAttendance;
  access: Access;
}

export function TodayCard({ today, access }: Props) {
  const attendance = today.attendance;
  const policy = access.company?.policy ?? null;
  const needsMasterFace = policy?.require_face_recognition && !access.masterFacePhoto;
  const done = today.has_checked_in && today.has_checked_out;
  const schedule = today.schedule ?? null;
  const workStart = schedule?.start_time ?? policy?.work_start_time ?? null;
  const workEnd = schedule?.end_time ?? policy?.work_end_time ?? null;

  return (
    <Card style={styles.card}>
      <View style={styles.header}>
        <Text style={styles.date}>{formatDate(today.date, 'weekday')}</Text>
        {attendance ? <Badge label={attendanceStatusLabel(attendance.status)} tone={attendanceStatusTone(attendance.status)} /> : null}
      </View>

      {schedule?.is_day_off ? (
        <View style={styles.scheduleRow}>
          <Ionicons name="calendar-outline" size={14} color={colors.textMuted} />
          <Text style={styles.schedule}>Hari ini dijadwalkan libur</Text>
        </View>
      ) : workStart ? (
        <View style={styles.scheduleRow}>
          <Ionicons name="time-outline" size={14} color={colors.textMuted} />
          <Text style={styles.schedule}>
            {schedule?.shift ? `Shift ${schedule.shift.name} · ` : 'Jam kerja '}
            {formatTime(workStart)}
            {workEnd ? ` – ${formatTime(workEnd)}` : ''}
          </Text>
        </View>
      ) : null}

      <View style={styles.times}>
        <TimeBox label="Masuk" value={formatTime(attendance?.check_in)} icon="log-in-outline" />
        <View style={styles.divider} />
        <TimeBox label="Pulang" value={formatTime(attendance?.check_out)} icon="log-out-outline" />
      </View>

      {attendance?.late_minutes ? <Text style={styles.late}>Terlambat {attendance.late_minutes} menit</Text> : null}
      {attendance?.status === 'pending_approval' ? (
        <Notice tone="warning" message="Presensi hari ini menunggu persetujuan atasan karena di luar area kantor atau verifikasi wajah tidak cocok." />
      ) : null}

      {needsMasterFace ? (
        <Notice tone="warning" icon="scan-outline" message="Perusahaan mewajibkan verifikasi wajah. Daftarkan foto master wajah dulu sebelum absen." />
      ) : null}

      {done ? (
        <Notice tone="success" message="Presensi hari ini sudah lengkap. Sampai jumpa besok!" />
      ) : needsMasterFace ? (
        <Button title="Daftarkan Foto Master Wajah" icon="scan-outline" size="lg" onPress={() => router.push('/attendance/master-face')} />
      ) : !today.has_checked_in ? (
        <Button title="Absen Masuk" icon="log-in-outline" size="lg" onPress={() => router.push({ pathname: '/attendance/check', params: { mode: 'in' } })} />
      ) : (
        <Button title="Absen Pulang" icon="log-out-outline" size="lg" variant="secondary" onPress={() => router.push({ pathname: '/attendance/check', params: { mode: 'out' } })} />
      )}
    </Card>
  );
}

function TimeBox({ label, value, icon }: { label: string; value: string; icon: 'log-in-outline' | 'log-out-outline' }) {
  return (
    <View style={styles.timeBox}>
      <View style={styles.timeLabelRow}>
        <Ionicons name={icon} size={14} color={colors.textMuted} />
        <Text style={styles.timeLabel}>{label}</Text>
      </View>
      <Text style={styles.timeValue}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { gap: spacing.md },
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: spacing.sm },
  date: { fontSize: fontSize.sm, color: colors.textMuted, flex: 1 },
  scheduleRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  schedule: { fontSize: fontSize.sm, color: colors.textMuted },
  times: { flexDirection: 'row', alignItems: 'center' },
  timeBox: { flex: 1, alignItems: 'center', gap: 4 },
  timeLabelRow: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  timeLabel: { fontSize: fontSize.xs, color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.5 },
  timeValue: { fontSize: fontSize.xxl, fontWeight: '700', color: colors.text, fontVariant: ['tabular-nums'] },
  divider: { width: 1, height: 40, backgroundColor: colors.border },
  late: { fontSize: fontSize.sm, color: colors.warning, textAlign: 'center', fontWeight: '600' },
});
