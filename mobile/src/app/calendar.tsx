import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { View } from 'react-native';

import { currentMonth, MonthSwitcher } from '@/components/MonthSwitcher';
import { Avatar, Badge, EmptyState, ErrorBanner, Group, LoadingView, Screen, Text } from '@/components/ui';
import { errorMessage, reportsApi } from '@/lib/api';
import { formatDateRange, requestStatusLabel, requestStatusTone } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { fontSize, makeStyles, spacing } from '@/theme';

const TYPE_LABEL: Record<string, string> = {
  annual_leave: 'Cuti Tahunan',
  permission: 'Izin',
  sick: 'Sakit',
  maternity: 'Cuti Melahirkan',
  unpaid: 'Cuti Tanpa Gaji',
};

export default function LeaveCalendarScreen() {
  const styles = useStyles();
  const [month, setMonth] = useState(currentMonth());
  const calendar = useQuery({
    queryKey: queryKeys.calendar(month.month, month.year),
    queryFn: () => reportsApi.leaveCalendar(month.month, month.year),
  });

  const entries = [...(calendar.data?.data ?? [])].sort((a, b) => a.start_date.localeCompare(b.start_date));

  return (
    <Screen onRefresh={() => calendar.refetch()}>
      <MonthSwitcher value={month} onChange={setMonth} />
      {calendar.isPending ? (
        <LoadingView />
      ) : calendar.isError ? (
        <ErrorBanner message={errorMessage(calendar.error)} onRetry={() => calendar.refetch()} />
      ) : entries.length === 0 ? (
        <EmptyState icon="people-outline" title="Tidak ada cuti" message="Belum ada rekan yang cuti atau izin di bulan ini." />
      ) : (
        <Group title="Cuti bulan ini">
          {entries.map((entry) => (
            <View key={entry.id} style={styles.row}>
              <Avatar name={entry.person_name} size={40} shape="rounded" />
              <View style={styles.body}>
                <Text style={styles.name} numberOfLines={1}>
                  {entry.person_name}
                </Text>
                <Text style={styles.meta} numberOfLines={1}>
                  {[entry.division_name ?? entry.department, entry.company_name].filter(Boolean).join(' · ')}
                </Text>
                <Text style={styles.dates}>
                  {TYPE_LABEL[entry.leave_type] ?? entry.leave_type} · {formatDateRange(entry.start_date, entry.end_date)} · {entry.days_count} hari
                </Text>
              </View>
              <Badge label={requestStatusLabel(entry.status)} tone={requestStatusTone(entry.status)} style={styles.badge} />
            </View>
          ))}
        </Group>
      )}
    </Screen>
  );
}

const useStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingHorizontal: 16, paddingVertical: 14 },
  body: { flex: 1, gap: 2 },
  name: { fontSize: fontSize.lg, fontWeight: '500', color: c.text },
  meta: { fontSize: fontSize.xs, color: c.textMuted },
  dates: { fontSize: fontSize.sm, color: c.text, marginTop: 2 },
  badge: { alignSelf: 'center' },
}));
