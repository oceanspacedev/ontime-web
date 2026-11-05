import { useQuery } from '@tanstack/react-query';
import { router, type Href } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { type AnyRequest, REQUEST_KIND_LABEL, type RequestKind, RequestRow } from '@/components/RequestRow';
import { Button, Choice, EmptyState, ErrorBanner, Group, LoadingView, Notice, ProgressBar, Screen, Segmented, Text } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { errorMessage, type QuotaSummary, requestsApi } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import { fontSize, makeStyles, spacing } from '@/theme';

const NEW_ROUTE: Record<RequestKind, Href> = {
  leave: '/request/leave/new',
  overtime: '/request/overtime/new',
  correction: '/request/correction/new',
  resignation: '/request/resignation/new',
};

/**
 * Most kinds a Segmented control can hold. Labels like "Koreksi Absensi" and "Pengunduran Diri"
 * get cut off in a third of a phone width, so three or more kinds use wrapping chips instead.
 */
const MAX_SEGMENTS = 2;

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
      <Screen title="Pengajuan">
        <Notice tone="info" message="Akun ini tidak punya menu pengajuan. Pengajuan hanya tersedia untuk akun yang terhubung ke profil pekerja." />
      </Screen>
    );
  }

  const active = { leave: leaves, overtime: overtimes, correction: corrections, resignation: resignations }[activeKind];
  const items: AnyRequest[] = (active.data?.data ?? []).map((data) => ({ kind: activeKind, data }) as AnyRequest);
  const quota = activeKind === 'leave' ? leaves.data?.quota_summary : null;
  const kindOptions = kinds.map((value) => ({ value, label: REQUEST_KIND_LABEL[value] }));
  const pagination = active.data && active.data.pagination.last_page > 1 ? active.data : null;

  return (
    <Screen title="Pengajuan" onRefresh={() => active.refetch()}>
      {kinds.length > 1 ? (
        kinds.length <= MAX_SEGMENTS ? (
          <Segmented options={kindOptions} value={activeKind} onChange={setKind} />
        ) : (
          <Choice options={kindOptions} value={activeKind} onChange={setKind} />
        )
      ) : null}

      {quota && access.hasAnnualLeave && quota.eligible !== false ? <LeaveQuota quota={quota} /> : null}

      <Button title={`Ajukan ${REQUEST_KIND_LABEL[activeKind]}`} icon="add" size="lg" onPress={() => router.push(NEW_ROUTE[activeKind])} />

      {active.isPending ? (
        <LoadingView />
      ) : active.isError ? (
        <ErrorBanner message={errorMessage(active.error)} onRetry={() => active.refetch()} />
      ) : items.length === 0 ? (
        <Group title="Riwayat">
          <EmptyState title="Belum ada pengajuan" message={`Riwayat ${REQUEST_KIND_LABEL[activeKind].toLowerCase()} Anda akan tampil di sini.`} />
        </Group>
      ) : (
        <Group
          title="Riwayat"
          footer={pagination ? `Menampilkan ${pagination.data.length} dari ${pagination.pagination.total} pengajuan terbaru.` : undefined}>
          {items.map((item) => (
            <RequestRow key={item.data.id} item={item} showWorker={activeKind === 'resignation' && access.canApprove} />
          ))}
        </Group>
      )}
    </Screen>
  );
}

/** Remaining annual leave as a big number with a red bar showing how much is left. */
function LeaveQuota({ quota }: { quota: QuotaSummary }) {
  const styles = useStyles();
  const total = quota.annual_leave_quota;
  const remainingRatio = total > 0 ? quota.remaining_days / total : 0;

  return (
    <Group title="Cuti Tahunan" padded>
      <View
        accessible
        accessibilityLabel={`Sisa cuti tahunan ${quota.remaining_days} dari ${total} hari`}
        style={styles.quotaBody}>
        <Text style={styles.quotaLabel}>Sisa cuti tahunan</Text>
        <View style={styles.quotaFigure}>
          <Text style={styles.quotaNumber}>{quota.remaining_days}</Text>
          <Text style={styles.quotaTotal}>dari {total} hari</Text>
        </View>
        <ProgressBar progress={remainingRatio} height={10} />
        <Text style={styles.quotaCaption}>Terpakai {quota.used_days} hari</Text>
      </View>
    </Group>
  );
}

const useStyles = makeStyles((c) => ({
  quotaBody: { gap: spacing.sm },
  quotaLabel: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500' },
  quotaFigure: { flexDirection: 'row', alignItems: 'baseline', gap: spacing.sm },
  quotaNumber: { fontSize: fontSize.hero, fontWeight: '700', color: c.text, letterSpacing: -1, fontVariant: ['tabular-nums'] },
  quotaTotal: { fontSize: fontSize.md, color: c.textMuted, fontWeight: '500' },
  quotaCaption: { fontSize: fontSize.xs, color: c.textMuted },
}));
