import { Ionicons } from '@expo/vector-icons';
import { Pressable, View } from 'react-native';

import { Badge, Text } from '@/components/ui';
import type { ShiftSwapDay, ShiftSwapRequest } from '@/lib/api/types';
import { formatDate } from '@/lib/format';
import { shiftSwapHeadline, shiftSwapOutcomeNote, shiftSwapStatusLabel, shiftSwapStatusTone } from '@/lib/shiftSwap';
import { fontSize, makeStyles, radius, spacing, useTheme } from '@/theme';

/** One Tukar Shift request: who, the status, and per date what each worker has before and after. */
export function ShiftSwapCard({ swap, onPress, footer }: { swap: ShiftSwapRequest; onPress?: () => void; footer?: React.ReactNode }) {
  const styles = useStyles();
  const { colors } = useTheme();
  const note = shiftSwapOutcomeNote(swap);

  const body = (
    <View style={styles.body}>
      <View style={styles.header}>
        <View style={styles.tile}>
          <Ionicons name="swap-horizontal" size={22} color={colors.primary} />
        </View>
        <View style={styles.headerText}>
          <Text style={styles.title}>{shiftSwapHeadline(swap)}</Text>
          {swap.reason ? (
            <Text style={styles.reason} numberOfLines={2}>
              {swap.reason}
            </Text>
          ) : null}
        </View>
        <Badge label={shiftSwapStatusLabel(swap.status)} tone={shiftSwapStatusTone(swap.status)} />
      </View>

      {swap.days.map((day) => (
        <ShiftSwapDayRow key={day.date} day={day} requester={swap.requester?.full_name ?? 'Pengaju'} counterpart={swap.counterpart?.full_name ?? 'Rekan'} />
      ))}

      {note ? <Text style={styles.note}>{note}</Text> : null}
      {footer}
    </View>
  );

  if (!onPress) {
    return body;
  }

  return (
    <Pressable onPress={onPress} accessibilityRole="button" style={({ pressed }) => [pressed && styles.pressed]}>
      {body}
    </Pressable>
  );
}

function ShiftSwapDayRow({ day, requester, counterpart }: { day: ShiftSwapDay; requester: string; counterpart: string }) {
  const styles = useStyles();

  return (
    <View style={styles.day} accessible accessibilityLabel={`${formatDate(day.date, 'weekday')}: ${requester} ${day.requester_after}, ${counterpart} ${day.counterpart_after}`}>
      <Text style={styles.date}>{formatDate(day.date, 'weekday')}</Text>
      <Text style={styles.line}>
        <Text style={styles.name}>{requester}: </Text>
        {day.requester_before} → {day.requester_after}
      </Text>
      <Text style={styles.line}>
        <Text style={styles.name}>{counterpart}: </Text>
        {day.counterpart_before} → {day.counterpart_after}
      </Text>
    </View>
  );
}

const useStyles = makeStyles((c) => ({
  body: { gap: spacing.sm, paddingHorizontal: 16, paddingVertical: 14 },
  pressed: { backgroundColor: c.fill },
  header: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.md },
  tile: { width: 42, height: 42, borderRadius: radius.md, backgroundColor: c.fill, alignItems: 'center', justifyContent: 'center' },
  headerText: { flex: 1, gap: 2 },
  title: { fontSize: fontSize.md, fontWeight: '600', color: c.text },
  reason: { fontSize: fontSize.sm, color: c.textMuted },
  day: { gap: 2, padding: spacing.sm, borderRadius: radius.md, backgroundColor: c.fill },
  date: { fontSize: fontSize.sm, fontWeight: '600', color: c.text },
  line: { fontSize: fontSize.sm, color: c.text },
  name: { fontWeight: '600', color: c.textMuted },
  note: { fontSize: fontSize.sm, color: c.danger },
}));
