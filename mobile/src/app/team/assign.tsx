import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';

import { ScheduleSheet, type ScheduleTarget } from '@/components/ScheduleSheet';
import { EmptyState, ErrorBanner, Field, Group, LoadingView, Row, Screen } from '@/components/ui';
import { errorMessage, teamApi } from '@/lib/api';
import { formatDate, toDateString } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';

const TYPE_LABELS = { employee: 'Karyawan', intern: 'Magang', freelancer: 'Freelancer' } as const;

/** Pick someone and set their schedule for the chosen day. */
export default function AssignScreen() {
  const params = useLocalSearchParams<{ date?: string; search?: string }>();
  const date = params.date ?? toDateString(new Date());
  const [search, setSearch] = useState(params.search ?? '');
  const [target, setTarget] = useState<ScheduleTarget | null>(null);
  const workers = useQuery({ queryKey: queryKeys.teamWorkers(search.trim()), queryFn: () => teamApi.workers(search.trim()) });

  return (
    <Screen>
      <Field
        label="Cari pekerja"
        value={search}
        onChangeText={setSearch}
        placeholder="Nama atau nomor"
        autoCorrect={false}
        hint={`Jadwal untuk ${formatDate(date, 'weekday')}`}
      />

      {workers.isPending ? (
        <LoadingView />
      ) : workers.isError ? (
        <ErrorBanner message={errorMessage(workers.error)} onRetry={() => workers.refetch()} />
      ) : workers.data.data.length === 0 ? (
        <EmptyState icon="search-outline" title="Tidak ditemukan" />
      ) : (
        <Group>
          {workers.data.data.map((worker) => (
            <Row
              key={`${worker.type}:${worker.id}`}
              title={worker.name}
              subtitle={`${worker.number} · ${TYPE_LABELS[worker.type]}`}
              onPress={() => setTarget({ worker, date })}
            />
          ))}
        </Group>
      )}

      <ScheduleSheet target={target} onClose={() => setTarget(null)} onSaved={() => router.back()} />
    </Screen>
  );
}
