import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { ScrollView } from 'react-native';

import { AttendanceRow } from '@/components/AttendanceRow';
import { type AnyRequest, RequestRow } from '@/components/RequestRow';
import { Choice, EmptyState, ErrorBanner, Group, LoadingView, Screen } from '@/components/ui';
import { approvalsApi, errorMessage, type ApprovalType } from '@/lib/api';
import { countPending } from '@/lib/approvals';
import { queryKeys } from '@/lib/queryClient';
import { makeStyles, spacing } from '@/theme';

type Filter = 'all' | ApprovalType;

const FILTERS: { value: Filter; label: string }[] = [
  { value: 'all', label: 'Semua' },
  { value: 'leave', label: 'Cuti / Izin' },
  { value: 'overtime', label: 'Lembur' },
  { value: 'correction', label: 'Koreksi' },
  { value: 'geofence', label: 'Luar Area' },
  { value: 'resignation', label: 'Resign' },
];

const TITLE = 'Persetujuan';

function pendingSubtitle(total: number): string {
  return total === 0 ? 'Tidak ada yang menunggu' : `${total} pengajuan menunggu keputusan`;
}

export default function ApprovalsScreen() {
  const styles = useStyles();
  const [filter, setFilter] = useState<Filter>('all');
  const pending = useQuery({ queryKey: queryKeys.approvals, queryFn: approvalsApi.pending });

  if (pending.isPending) {
    return (
      <Screen title={TITLE} scroll={false} style={styles.loading}>
        <LoadingView />
      </Screen>
    );
  }
  if (pending.isError) {
    return (
      <Screen title={TITLE} onRefresh={() => pending.refetch()}>
        <ErrorBanner message={errorMessage(pending.error)} onRetry={() => pending.refetch()} />
      </Screen>
    );
  }

  const data = pending.data;
  const show = (type: ApprovalType) => filter === 'all' || filter === type;
  const sections: { type: ApprovalType; title: string; items: AnyRequest[] }[] = [
    { type: 'leave', title: 'Cuti / Izin', items: data.leave_requests.map((item) => ({ kind: 'leave', data: item })) },
    { type: 'overtime', title: 'Lembur', items: data.overtime_requests.map((item) => ({ kind: 'overtime', data: item })) },
    { type: 'correction', title: 'Koreksi Absensi', items: data.attendance_corrections.map((item) => ({ kind: 'correction', data: item })) },
    { type: 'resignation', title: 'Pengunduran Diri', items: data.resignations.map((item) => ({ kind: 'resignation', data: item })) },
  ];
  const total = countPending(data);
  const visibleCount =
    sections.filter((section) => show(section.type)).reduce((sum, section) => sum + section.items.length, 0) +
    (show('geofence') ? data.geofence_attendances.length : 0);

  return (
    <Screen title={TITLE} subtitle={pendingSubtitle(total)} onRefresh={() => pending.refetch()}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.filters} contentContainerStyle={styles.filtersContent}>
        <Choice options={FILTERS} value={filter} onChange={setFilter} />
      </ScrollView>

      {total === 0 ? (
        <EmptyState icon="checkmark-done-circle-outline" title="Tidak ada antrean" message="Semua pengajuan yang menjadi wewenang Anda sudah diproses." />
      ) : visibleCount === 0 ? (
        <EmptyState title="Kosong" message="Tidak ada pengajuan jenis ini yang menunggu." />
      ) : null}

      {sections.map((section) =>
        show(section.type) && section.items.length > 0 ? (
          <Group key={section.type} title={`${section.title} · ${section.items.length}`}>
            {section.items.map((item) => (
              <RequestRow key={item.data.id} item={item} showWorker href={{ pathname: '/approval/[type]/[id]' }} />
            ))}
          </Group>
        ) : null,
      )}

      {show('geofence') && data.geofence_attendances.length > 0 ? (
        <Group title={`Presensi Luar Area · ${data.geofence_attendances.length}`}>
          {data.geofence_attendances.map((attendance) => (
            <AttendanceRow
              key={attendance.id}
              attendance={attendance}
              showWorker
              onPress={() => router.push({ pathname: '/approval/[type]/[id]', params: { type: 'geofence', id: String(attendance.id) } })}
            />
          ))}
        </Group>
      ) : null}
    </Screen>
  );
}

const useStyles = makeStyles(() => ({
  /** Lets the spinner centre in the space under the large title. */
  loading: { flex: 1 },
  /** Bleeds the chip strip to the screen edges so chips scroll under the padding. */
  filters: { marginHorizontal: -spacing.lg, flexGrow: 0 },
  filtersContent: { paddingHorizontal: spacing.lg },
}));
