import { Pressable, View } from 'react-native';

import { IconButton, Text } from '@/components/ui';
import { toDateString } from '@/lib/format';
import { shiftDate } from '@/lib/team';
import { fontSize, makeStyles, radius, spacing } from '@/theme';

function dayLabel(date: string, today: string): string {
  const [y, m, d] = date.split('-').map(Number);
  const label = new Date(y, m - 1, d).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short' });
  if (date === today) {
    return `Hari ini · ${label}`;
  }
  if (date === shiftDate(today, 1)) {
    return `Besok · ${label}`;
  }
  if (date === shiftDate(today, -1)) {
    return `Kemarin · ${label}`;
  }
  return label;
}

/** Back, the day in a pill (tap to return to today), forward. */
export function DaySwitcher({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const styles = useStyles();
  const today = toDateString(new Date());
  return (
    <View style={styles.row}>
      <IconButton icon="chevron-back" onPress={() => onChange(shiftDate(value, -1))} accessibilityLabel="Hari sebelumnya" />
      <Pressable
        style={styles.pill}
        onPress={() => onChange(today)}
        disabled={value === today}
        accessibilityRole="button"
        accessibilityLabel="Kembali ke hari ini">
        <Text style={styles.label} numberOfLines={1}>
          {dayLabel(value, today)}
        </Text>
      </Pressable>
      <IconButton icon="chevron-forward" onPress={() => onChange(shiftDate(value, 1))} accessibilityLabel="Hari berikutnya" />
    </View>
  );
}

const useStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  pill: { flex: 1, minHeight: 46, borderRadius: radius.pill, backgroundColor: c.fill, alignItems: 'center', justifyContent: 'center', paddingHorizontal: spacing.md },
  label: { fontSize: fontSize.md, fontWeight: '600', color: c.text },
}));
