import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { AttendanceRow } from '@/components/AttendanceRow';
import { type AnyRequest, RequestRow } from '@/components/RequestRow';
import { Choice, EmptyState, ErrorBanner, LoadingView, Screen, SectionTitle } from '@/components/ui';
import { approvalsApi, errorMessage, type ApprovalType } from '@/lib/api';
import { countPending } from '@/lib/approvals';
import { queryKeys } from '@/lib/queryClient';
import { spacing } from '@/theme';

type Filter = 'all' | ApprovalType;

const FILTERS: { value: Filter; label: string }[] = [
  { value: 'all', label: 'Semua' },
  { value: 'leave', label: 'Cuti / Izin' },
  { value: 'overtime', label: 'Lembur' },
  { value: 'correction', label: 'Koreksi' },
  { value: 'geofence', label: 'Luar Area' },
  { value: 'resignation', label: 'Resign' },
];

export default function ApprovalsScreen() {
  const [filter, setFilter] = useState<Filter>('all');
  const pending = useQuery({ queryKey: queryKeys.approvals, queryFn: approvalsApi.pending });

  if (pending.isPending) {
    return <LoadingView />;
  }
  if (pending.isError) {
    return (
      <Screen>
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
    <Screen onRefresh={() => pending.refetch()}>
      <Choice options={FILTERS} value={filter} onChange={setFilter} />
      {total === 0 ? (
        <EmptyState icon="checkmark-done-circle-outline" title="Tidak ada antrean" message="Semua pengajuan yang menjadi wewenang Anda sudah diproses." />
      ) : visibleCount === 0 ? (
        <EmptyState title="Kosong" message="Tidak ada pengajuan jenis ini yang menunggu." />
      ) : null}

      {sections.map((section) =>
        show(section.type) && section.items.length > 0 ? (
          <View key={section.type} style={styles.section}>
            <SectionTitle title={`${section.title} (${section.items.length})`} />
            {section.items.map((item) => (
              <RequestRow key={item.data.id} item={item} showWorker href={{ pathname: '/approval/[type]/[id]' }} />
            ))}
          </View>
        ) : null,
      )}

      {show('geofence') && data.geofence_attendances.length > 0 ? (
        <View style={styles.section}>
          <SectionTitle title={`Presensi Luar Area (${data.geofence_attendances.length})`} />
          {data.geofence_attendances.map((attendance) => (
            <AttendanceRow
              key={attendance.id}
              attendance={attendance}
              showWorker
              onPress={() => router.push({ pathname: '/approval/[type]/[id]', params: { type: 'geofence', id: String(attendance.id) } })}
            />
          ))}
        </View>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  section: { gap: spacing.sm },
});
