import { useMutation, useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Alert, Linking } from 'react-native';

import { currentMonth, MonthSwitcher, type MonthValue } from '@/components/MonthSwitcher';
import { Button, EmptyState, ErrorBanner, Group, LoadingView, Row } from '@/components/ui';
import { errorMessage, teamApi, type TeamRecapRow } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import { recapLine } from '@/lib/team';

function monthKey(value: MonthValue): string {
  return `${value.year}-${String(value.month).padStart(2, '0')}`;
}

/** The month per person per outlet, with an Excel download for payroll or HR. */
export function TeamRecapView() {
  const [month, setMonth] = useState<MonthValue>(currentMonth());
  const key = monthKey(month);
  const recap = useQuery({ queryKey: queryKeys.teamRecap(key), queryFn: () => teamApi.recap(key) });

  const download = useMutation({
    mutationFn: () => teamApi.recapLink(key),
    onSuccess: ({ url }) => void Linking.openURL(url),
    onError: (error) => Alert.alert('Gagal', errorMessage(error)),
  });

  const groups = (recap.data?.data ?? []).reduce<Record<string, TeamRecapRow[]>>((byOutlet, row) => {
    const outlet = row.outlet ?? 'Libur / tanpa outlet';
    (byOutlet[outlet] ??= []).push(row);
    return byOutlet;
  }, {});

  return (
    <>
      <MonthSwitcher value={month} onChange={setMonth} />
      <Button
        title="Unduh Excel"
        icon="download-outline"
        variant="secondary"
        loading={download.isPending}
        disabled={!recap.data || recap.data.data.length === 0}
        onPress={() => download.mutate()}
      />

      {recap.isPending ? (
        <LoadingView />
      ) : recap.isError ? (
        <ErrorBanner message={errorMessage(recap.error)} onRetry={() => recap.refetch()} />
      ) : recap.data.data.length === 0 ? (
        <EmptyState icon="stats-chart-outline" title="Belum ada data bulan ini" />
      ) : (
        Object.entries(groups).map(([outlet, rows]) => (
          <Group key={outlet} title={`${outlet} · ${rows.length}`}>
            {rows.map((row) => (
              <Row key={`${row.worker.type}:${row.worker.id}`} title={row.worker.name} subtitle={recapLine(row)} />
            ))}
          </Group>
        ))
      )}
    </>
  );
}
