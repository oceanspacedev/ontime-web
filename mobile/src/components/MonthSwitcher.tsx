import { View } from 'react-native';

import { IconButton, Text } from '@/components/ui';
import { fontSize, makeStyles, radius, spacing } from '@/theme';

export interface MonthValue {
  month: number;
  year: number;
}

export function currentMonth(): MonthValue {
  const now = new Date();
  return { month: now.getMonth() + 1, year: now.getFullYear() };
}

export function shiftMonth(value: MonthValue, delta: number): MonthValue {
  const date = new Date(value.year, value.month - 1 + delta, 1);
  return { month: date.getMonth() + 1, year: date.getFullYear() };
}

export function monthLabel(value: MonthValue): string {
  return new Date(value.year, value.month - 1, 1).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
}

export function monthRange(value: MonthValue): { start: string; end: string } {
  const pad = (n: number) => String(n).padStart(2, '0');
  const lastDay = new Date(value.year, value.month, 0).getDate();
  return {
    start: `${value.year}-${pad(value.month)}-01`,
    end: `${value.year}-${pad(value.month)}-${pad(lastDay)}`,
  };
}

/** Pill row for stepping through months: back, the month name in a pill, forward (stops at this month). */
export function MonthSwitcher({ value, onChange }: { value: MonthValue; onChange: (value: MonthValue) => void }) {
  const styles = useStyles();
  const now = currentMonth();
  const isCurrent = value.month === now.month && value.year === now.year;
  return (
    <View style={styles.row}>
      <IconButton icon="chevron-back" onPress={() => onChange(shiftMonth(value, -1))} accessibilityLabel="Bulan sebelumnya" />
      <View style={styles.labelPill}>
        <Text style={styles.label} numberOfLines={1}>
          {monthLabel(value)}
        </Text>
      </View>
      <IconButton
        icon="chevron-forward"
        onPress={() => onChange(shiftMonth(value, 1))}
        disabled={isCurrent}
        accessibilityLabel="Bulan berikutnya"
      />
    </View>
  );
}

const useStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  labelPill: {
    flex: 1,
    height: 46,
    borderRadius: radius.pill,
    backgroundColor: c.fill,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.lg,
  },
  label: { fontSize: fontSize.md, fontWeight: '600', color: c.text, textTransform: 'capitalize' },
}));
