import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { Pressable, View } from 'react-native';

import { Badge, Text } from '@/components/ui';
import type { Attendance } from '@/lib/api';
import { attendanceStatusLabel, attendanceStatusTone, formatTime } from '@/lib/format';
import { fontSize, makeStyles, spacing, useTheme } from '@/theme';

const LOCALE = 'id-ID';

export function workerName(attendance: Pick<Attendance, 'employee' | 'intern' | 'freelancer' | 'user'>): string {
  return attendance.employee?.full_name ?? attendance.intern?.full_name ?? attendance.freelancer?.full_name ?? attendance.user?.name ?? '-';
}

/** Splits a `YYYY-MM-DD` (or ISO) date into the pieces shown on the date tile. */
function dateTileParts(value: string): { weekday: string; day: string; month: string } | null {
  const plain = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  const date = plain ? new Date(Number(plain[1]), Number(plain[2]) - 1, Number(plain[3])) : new Date(value);
  if (Number.isNaN(date.getTime())) {
    return null;
  }
  return {
    weekday: date.toLocaleDateString(LOCALE, { weekday: 'short' }),
    day: String(date.getDate()),
    month: date.toLocaleDateString(LOCALE, { month: 'short' }),
  };
}

/**
 * One attendance day as a row, meant to sit inside a Group card: a date tile, the check-in and
 * check-out times, the status badge with any notes under them, and a chevron. The badge sits
 * under the times rather than on the right so the times line is never squeezed on narrow phones.
 * Callers wrap a list in Group.
 */
export function AttendanceRow({ attendance, showWorker, onPress }: { attendance: Attendance; showWorker?: boolean; onPress?: () => void }) {
  const styles = useStyles();
  const { colors } = useTheme();
  const tile = dateTileParts(attendance.date);

  return (
    <Pressable
      style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
      accessibilityRole="button"
      onPress={onPress ?? (() => router.push({ pathname: '/attendance/[id]', params: { id: String(attendance.id) } }))}>
      <View style={styles.tile}>
        {tile ? (
          <>
            <Text style={styles.tileSmall}>{tile.weekday}</Text>
            <Text style={styles.tileDay}>{tile.day}</Text>
            <Text style={styles.tileSmall}>{tile.month}</Text>
          </>
        ) : (
          <Text style={styles.tileDay}>-</Text>
        )}
      </View>

      <View style={styles.body}>
        {showWorker ? (
          <Text style={styles.worker} numberOfLines={1}>
            {workerName(attendance)}
          </Text>
        ) : null}
        <Text style={styles.times} numberOfLines={2}>
          Masuk <Text style={styles.timeValue}>{formatTime(attendance.check_in)}</Text>
          {' · '}
          Pulang <Text style={styles.timeValue}>{formatTime(attendance.check_out)}</Text>
        </Text>
        <View style={styles.meta}>
          <Badge label={attendanceStatusLabel(attendance.status)} tone={attendanceStatusTone(attendance.status)} />
          {attendance.late_minutes ? <Text style={styles.late}>Terlambat {attendance.late_minutes} menit</Text> : null}
          {attendance.is_corrected ? <Text style={styles.note}>Sudah dikoreksi</Text> : null}
        </View>
      </View>

      <Ionicons name="chevron-forward" size={20} color={colors.textSubtle} />
    </Pressable>
  );
}

const useStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingHorizontal: 16, paddingVertical: 14 },
  rowPressed: { backgroundColor: c.fill },
  tile: {
    width: 56,
    paddingVertical: 8,
    borderRadius: 18,
    backgroundColor: c.fill,
    alignItems: 'center',
    justifyContent: 'center',
  },
  tileSmall: { fontSize: 11, color: c.textMuted, fontWeight: '500', textTransform: 'capitalize' },
  tileDay: { fontSize: fontSize.xl, color: c.text, fontWeight: '700', lineHeight: 24, fontVariant: ['tabular-nums'] },
  body: { flex: 1, gap: 6 },
  meta: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', columnGap: spacing.sm, rowGap: 4 },
  worker: { fontSize: fontSize.lg, color: c.text, fontWeight: '500' },
  times: { fontSize: fontSize.sm, color: c.textMuted },
  timeValue: { fontSize: fontSize.md, color: c.text, fontWeight: '600', fontVariant: ['tabular-nums'] },
  late: { fontSize: fontSize.xs, color: c.warning, fontWeight: '500' },
  note: { fontSize: fontSize.xs, color: c.textMuted },
}));
