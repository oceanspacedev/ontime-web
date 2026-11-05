import { Ionicons } from '@expo/vector-icons';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { colors, fontSize, radius, spacing } from '@/theme';

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

export function MonthSwitcher({ value, onChange }: { value: MonthValue; onChange: (value: MonthValue) => void }) {
  const now = currentMonth();
  const isCurrent = value.month === now.month && value.year === now.year;
  return (
    <View style={styles.row}>
      <Pressable onPress={() => onChange(shiftMonth(value, -1))} style={styles.arrow} hitSlop={8}>
        <Ionicons name="chevron-back" size={20} color={colors.primary} />
      </Pressable>
      <Text style={styles.label}>{monthLabel(value)}</Text>
      <Pressable onPress={() => onChange(shiftMonth(value, 1))} style={[styles.arrow, isCurrent && styles.arrowDisabled]} hitSlop={8} disabled={isCurrent}>
        <Ionicons name="chevron-forward" size={20} color={colors.primary} />
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
  },
  arrow: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center' },
  arrowDisabled: { opacity: 0.3 },
  label: { fontSize: fontSize.md, fontWeight: '600', color: colors.text, textTransform: 'capitalize' },
});
