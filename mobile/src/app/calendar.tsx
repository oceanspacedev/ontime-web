import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { currentMonth, MonthSwitcher } from '@/components/MonthSwitcher';
import { Avatar, Badge, EmptyState, ErrorBanner, LoadingView, Screen } from '@/components/ui';
import { errorMessage, reportsApi } from '@/lib/api';
import { formatDateRange, requestStatusLabel, requestStatusTone } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { colors, fontSize, radius, spacing } from '@/theme';

const TYPE_LABEL: Record<string, string> = {
  annual_leave: 'Cuti Tahunan',
  permission: 'Izin',
  sick: 'Sakit',
  maternity: 'Cuti Melahirkan',
  unpaid: 'Cuti Tanpa Gaji',
};

export default function LeaveCalendarScreen() {
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
        <View style={styles.list}>
          {entries.map((entry) => (
            <View key={entry.id} style={styles.row}>
              <Avatar name={entry.person_name} size={40} />
              <View style={styles.body}>
                <Text style={styles.name}>{entry.person_name}</Text>
                <Text style={styles.meta}>{[entry.division_name ?? entry.department, entry.company_name].filter(Boolean).join(' · ')}</Text>
                <Text style={styles.dates}>
                  {TYPE_LABEL[entry.leave_type] ?? entry.leave_type} · {formatDateRange(entry.start_date, entry.end_date)} · {entry.days_count} hari
                </Text>
              </View>
              <Badge label={requestStatusLabel(entry.status)} tone={requestStatusTone(entry.status)} />
            </View>
          ))}
        </View>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  list: { gap: spacing.sm },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
  },
  body: { flex: 1, gap: 2 },
  name: { fontSize: fontSize.md, fontWeight: '600', color: colors.text },
  meta: { fontSize: fontSize.xs, color: colors.textMuted },
  dates: { fontSize: fontSize.sm, color: colors.text },
});
