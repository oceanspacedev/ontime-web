import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Badge } from '@/components/ui';
import type { Attendance } from '@/lib/api';
import { attendanceStatusLabel, attendanceStatusTone, formatDate, formatTime } from '@/lib/format';
import { colors, fontSize, radius, spacing } from '@/theme';

export function workerName(attendance: Pick<Attendance, 'employee' | 'intern' | 'freelancer' | 'user'>): string {
  return attendance.employee?.full_name ?? attendance.intern?.full_name ?? attendance.freelancer?.full_name ?? attendance.user?.name ?? '-';
}

export function AttendanceRow({ attendance, showWorker, onPress }: { attendance: Attendance; showWorker?: boolean; onPress?: () => void }) {
  return (
    <Pressable
      style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
      onPress={onPress ?? (() => router.push({ pathname: '/attendance/[id]', params: { id: String(attendance.id) } }))}>
      <Text style={styles.rowDate}>{formatDate(attendance.date, 'weekday')}</Text>
      {showWorker ? <Text style={styles.worker}>{workerName(attendance)}</Text> : null}
      <View style={styles.rowBody}>
        <View style={styles.times}>
          <Text style={styles.time}>
            Masuk <Text style={styles.timeValue}>{formatTime(attendance.check_in)}</Text>
          </Text>
          <Text style={styles.time}>
            Pulang <Text style={styles.timeValue}>{formatTime(attendance.check_out)}</Text>
          </Text>
        </View>
        <Badge label={attendanceStatusLabel(attendance.status)} tone={attendanceStatusTone(attendance.status)} />
      </View>
      {attendance.late_minutes ? <Text style={styles.late}>Terlambat {attendance.late_minutes} menit</Text> : null}
      {attendance.is_corrected ? <Text style={styles.note}>Sudah dikoreksi</Text> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    gap: spacing.sm,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
  },
  rowPressed: { opacity: 0.8 },
  rowDate: { fontSize: fontSize.sm, fontWeight: '600', color: colors.text },
  worker: { fontSize: fontSize.sm, color: colors.primary, fontWeight: '600' },
  rowBody: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: spacing.sm },
  times: { flexDirection: 'row', gap: spacing.lg },
  time: { fontSize: fontSize.sm, color: colors.textMuted },
  timeValue: { color: colors.text, fontWeight: '600', fontVariant: ['tabular-nums'] },
  late: { fontSize: fontSize.xs, color: colors.warning },
  note: { fontSize: fontSize.xs, color: colors.textMuted },
});
