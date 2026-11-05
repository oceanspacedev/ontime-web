import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { AttendanceRow } from '@/components/AttendanceRow';
import { currentMonth, MonthSwitcher, monthRange } from '@/components/MonthSwitcher';
import { Button, EmptyState, ErrorBanner, Group, LoadingView, Screen, Text } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { attendanceApi, errorMessage, reportsApi } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import { fontSize, makeStyles, spacing, useTheme } from '@/theme';

export default function HistoryScreen() {
  const access = useAccess();
  const styles = useStyles();
  const { colors } = useTheme();
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
    <Screen title="Riwayat" onRefresh={() => Promise.all([attendances.refetch(), report.refetch()])}>
      <MonthSwitcher value={month} onChange={setMonth} />

      {summary ? (
        <Group title="Ringkasan" separated={false}>
          <View style={styles.summary}>
            <SummaryColumn label="Hadir" value={summary.total_present} color={colors.success} />
            <View style={styles.divider} />
            <SummaryColumn label="Terlambat" value={summary.total_late} color={colors.warning} />
            <View style={styles.divider} />
            <SummaryColumn label="Cuti" value={summary.total_leave} color={colors.info} />
            <View style={styles.divider} />
            <SummaryColumn label="Absen" value={summary.total_absent} color={colors.danger} />
          </View>
        </Group>
      ) : null}

      {attendances.isPending ? (
        <LoadingView />
      ) : attendances.isError ? (
        <ErrorBanner message={errorMessage(attendances.error)} onRetry={() => attendances.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState icon="calendar-outline" title="Belum ada presensi" message="Tidak ada catatan presensi di bulan ini." />
      ) : (
        <>
          <Group title="Presensi">
            {rows.map((attendance) => (
              <AttendanceRow key={attendance.id} attendance={attendance} showWorker={showWorker} />
            ))}
          </Group>
          {attendances.hasNextPage ? (
            <Button title="Muat lebih banyak" variant="secondary" onPress={() => attendances.fetchNextPage()} loading={attendances.isFetchingNextPage} />
          ) : null}
        </>
      )}
    </Screen>
  );
}

function SummaryColumn({ label, value, color }: { label: string; value: number; color: string }) {
  const styles = useStyles();
  return (
    <View style={styles.column}>
      <Text style={[styles.columnValue, { color }]}>{value}</Text>
      <Text style={styles.columnLabel} numberOfLines={1}>
        {label}
      </Text>
    </View>
  );
}

const useStyles = makeStyles((c) => ({
  summary: { flexDirection: 'row', alignItems: 'stretch', paddingVertical: 18 },
  column: { flex: 1, alignItems: 'center', gap: 4, paddingHorizontal: spacing.xs },
  columnValue: { fontSize: fontSize.xxl, fontWeight: '700', letterSpacing: -0.4, fontVariant: ['tabular-nums'] },
  columnLabel: { fontSize: fontSize.xs, color: c.textMuted, fontWeight: '500' },
  divider: { width: StyleSheet.hairlineWidth, backgroundColor: c.separator, marginVertical: spacing.xs },
}));
