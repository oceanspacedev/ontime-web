import { Ionicons } from '@expo/vector-icons';
import { useQuery } from '@tanstack/react-query';
import { router, type Href } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { type AnyRequest, REQUEST_KIND_LABEL, type RequestKind, RequestRow } from '@/components/RequestRow';
import { Button, Choice, EmptyState, ErrorBanner, LoadingView, Notice, Screen } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { errorMessage, requestsApi } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import { colors, fontSize, spacing } from '@/theme';

const NEW_ROUTE: Record<RequestKind, Href> = {
  leave: '/request/leave/new',
  overtime: '/request/overtime/new',
  correction: '/request/correction/new',
  resignation: '/request/resignation/new',
};

export default function RequestsScreen() {
  const access = useAccess();
  const kinds: RequestKind[] = [
    ...(access.canRequestLeave ? (['leave'] as RequestKind[]) : []),
    ...(access.canRequestOvertime ? (['overtime'] as RequestKind[]) : []),
    ...(access.canRequestCorrection ? (['correction'] as RequestKind[]) : []),
    ...(access.canResign ? (['resignation'] as RequestKind[]) : []),
  ];
  const [kind, setKind] = useState<RequestKind | null>(kinds[0] ?? null);
  const activeKind = kind && kinds.includes(kind) ? kind : kinds[0] ?? null;

  const leaves = useQuery({ queryKey: queryKeys.leaves, queryFn: () => requestsApi.leave.list(), enabled: activeKind === 'leave' });
  const overtimes = useQuery({ queryKey: queryKeys.overtimes, queryFn: () => requestsApi.overtime.list(), enabled: activeKind === 'overtime' });
  const corrections = useQuery({ queryKey: queryKeys.corrections, queryFn: () => requestsApi.correction.list(), enabled: activeKind === 'correction' });
  const resignations = useQuery({ queryKey: queryKeys.resignations, queryFn: () => requestsApi.resignation.list(), enabled: activeKind === 'resignation' });

  if (!activeKind) {
    return (
      <Screen>
        <Notice tone="info" message="Akun ini tidak punya menu pengajuan. Pengajuan hanya tersedia untuk akun yang terhubung ke profil pekerja." />
      </Screen>
    );
  }

  const active = { leave: leaves, overtime: overtimes, correction: corrections, resignation: resignations }[activeKind];
  const items: AnyRequest[] = (active.data?.data ?? []).map((data) => ({ kind: activeKind, data }) as AnyRequest);
  const quota = activeKind === 'leave' ? leaves.data?.quota_summary : null;

  return (
    <Screen onRefresh={() => active.refetch()}>
      <Choice options={kinds.map((value) => ({ value, label: REQUEST_KIND_LABEL[value] }))} value={activeKind} onChange={setKind} />

      {quota && access.profileKind !== 'freelancer' ? (
        <View style={styles.quota}>
          <Ionicons name="sunny-outline" size={18} color={colors.primary} />
          <Text style={styles.quotaText}>
            Sisa cuti tahunan <Text style={styles.quotaStrong}>{quota.remaining_days}</Text> dari {quota.annual_leave_quota} hari
          </Text>
        </View>
      ) : null}

      <Button title={`Ajukan ${REQUEST_KIND_LABEL[activeKind]}`} icon="add" onPress={() => router.push(NEW_ROUTE[activeKind])} />

      {active.isPending ? (
        <LoadingView />
      ) : active.isError ? (
        <ErrorBanner message={errorMessage(active.error)} onRetry={() => active.refetch()} />
      ) : items.length === 0 ? (
        <EmptyState title="Belum ada pengajuan" message={`Riwayat ${REQUEST_KIND_LABEL[activeKind].toLowerCase()} Anda akan tampil di sini.`} />
      ) : (
        <View style={styles.list}>
          {items.map((item) => (
            <RequestRow key={item.data.id} item={item} showWorker={activeKind === 'resignation' && access.canApprove} />
          ))}
          {active.data && active.data.pagination.last_page > 1 ? (
            <Pressable onPress={() => {}} disabled>
              <Text style={styles.more}>Menampilkan {active.data.data.length} dari {active.data.pagination.total} pengajuan terbaru.</Text>
            </Pressable>
          ) : null}
        </View>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  quota: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  quotaText: { fontSize: fontSize.sm, color: colors.text },
  quotaStrong: { fontWeight: '700', color: colors.primary },
  list: { gap: spacing.sm },
  more: { fontSize: fontSize.xs, color: colors.textMuted, textAlign: 'center', paddingVertical: spacing.sm },
});
