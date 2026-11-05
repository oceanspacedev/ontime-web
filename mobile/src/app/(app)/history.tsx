import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { AttendanceRow } from '@/components/AttendanceRow';
import { currentMonth, MonthSwitcher, monthRange } from '@/components/MonthSwitcher';
import { Button, EmptyState, ErrorBanner, LoadingView, Screen } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { attendanceApi, errorMessage, reportsApi } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import { colors, fontSize, radius, spacing } from '@/theme';

export default function HistoryScreen() {
  const access = useAccess();
  const [month, setMonth] = useState(currentMonth());
  const range = monthRange(month);

  const report = useQuery({
    queryKey: queryKeys.report(range.start, range.end),
    queryFn: () => reportsApi.attendanceReport(range.start, range.end),
  });

  const attendances = useInfiniteQuery({
    queryKey: queryKeys.attendances({ month: month.month, year: month.year }),
    queryFn: ({ pageParam }) => attendanceApi.list({ month: month.month, year: month.year, page: pageParam }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.pagination.current_page < last.pagination.last_page ? last.pagination.current_page + 1 : undefined),
  });

  const rows = attendances.data?.pages.flatMap((page) => page.data) ?? [];
  // Approvers and admins also see their team here; show whose row it is when it is not the user's own.
  const showWorker = access.canApprove || !access.canUseAttendance;
  const summary = report.data?.summary;

  return (
    <Screen
      onRefresh={() => {
        void attendances.refetch();
        void report.refetch();
      }}>
      <MonthSwitcher value={month} onChange={setMonth} />

      {summary ? (
        <View style={styles.summary}>
          <SummaryTile label="Hadir" value={summary.total_present} color={colors.success} />
          <SummaryTile label="Terlambat" value={summary.total_late} color={colors.warning} />
          <SummaryTile label="Cuti" value={summary.total_leave} color={colors.info} />
          <SummaryTile label="Absen" value={summary.total_absent} color={colors.danger} />
        </View>
      ) : null}

      {attendances.isPending ? (
        <LoadingView />
      ) : attendances.isError ? (
        <ErrorBanner message={errorMessage(attendances.error)} onRetry={() => attendances.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState icon="calendar-outline" title="Belum ada presensi" message="Tidak ada catatan presensi di bulan ini." />
      ) : (
        <View style={styles.list}>
          {rows.map((attendance) => (
            <AttendanceRow key={attendance.id} attendance={attendance} showWorker={showWorker} />
          ))}
          {attendances.hasNextPage ? (
            <Button title="Muat lebih banyak" variant="secondary" onPress={() => attendances.fetchNextPage()} loading={attendances.isFetchingNextPage} />
          ) : null}
        </View>
      )}
    </Screen>
  );
}

function SummaryTile({ label, value, color }: { label: string; value: number; color: string }) {
  return (
    <View style={styles.tile}>
      <Text style={[styles.tileValue, { color }]}>{value}</Text>
      <Text style={styles.tileLabel}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  summary: { flexDirection: 'row', gap: spacing.sm },
  tile: {
    flex: 1,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    paddingVertical: spacing.md,
    alignItems: 'center',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
  },
  tileValue: { fontSize: fontSize.xl, fontWeight: '700' },
  tileLabel: { fontSize: fontSize.xs, color: colors.textMuted, marginTop: 2 },
  list: { gap: spacing.sm },
});
