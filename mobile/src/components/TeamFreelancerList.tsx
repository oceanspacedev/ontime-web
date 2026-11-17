import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Alert } from 'react-native';

import { Badge, Button, EmptyState, ErrorBanner, Field, Group, LoadingView, Row, Segmented } from '@/components/ui';
import { showNewAccount } from '@/lib/accountNotice';
import { errorMessage, teamApi, type TeamFreelancer } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { nextPage } from '@/lib/team';

type Status = 'active' | 'ended';

const STATUSES: { value: Status; label: string }[] = [
  { value: 'active', label: 'Aktif' },
  { value: 'ended', label: 'Sudah keluar' },
];

/**
 * Freelancers of the coordinator's brands. Tapping one offers what fits: schedule, open an account,
 * end the contract, or take them back.
 */
export function TeamFreelancerList({ onSchedule }: { onSchedule: (freelancer: TeamFreelancer) => void }) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<Status>('active');
  const [search, setSearch] = useState('');
  // Thirty per page; "Muat lebih banyak" brings the next page so everyone in the total can be reached.
  const list = useInfiniteQuery({
    queryKey: queryKeys.teamFreelancers(status, search.trim()),
    queryFn: ({ pageParam }) => teamApi.freelancers(status, search.trim(), pageParam),
    initialPageParam: 1,
    getNextPageParam: (last) => nextPage(last.pagination),
  });
  const freelancers = list.data?.pages.flatMap((page) => page.data) ?? [];
  const total = list.data?.pages[0]?.pagination.total ?? 0;
  const refresh = () => queryClient.invalidateQueries({ queryKey: queryKeys.team });

  const change = useMutation({
    mutationFn: (freelancer: TeamFreelancer) => (freelancer.is_active ? teamApi.endFreelancer(freelancer.id) : teamApi.reactivateFreelancer(freelancer.id)),
    onSuccess: async (response) => {
      await refresh();
      Alert.alert(response.message);
    },
    onError: (error) => Alert.alert('Gagal', errorMessage(error)),
  });

  const openAccount = useMutation({
    mutationFn: (freelancer: TeamFreelancer) => teamApi.createAccount(freelancer.id),
    onSuccess: async (response) => {
      await refresh();
      showNewAccount(response.data.name, response.account, response.message, () => undefined);
    },
    onError: (error) => Alert.alert('Gagal', errorMessage(error)),
  });

  const confirmEnd = (freelancer: TeamFreelancer) =>
    Alert.alert(freelancer.name, 'Akhiri kontrak? Akunnya dinonaktifkan dan jadwal setelah hari ini dihapus.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Akhiri kontrak', style: 'destructive', onPress: () => change.mutate(freelancer) },
    ]);

  const choose = (freelancer: TeamFreelancer) =>
    freelancer.is_active
      ? Alert.alert(freelancer.name, undefined, [
          { text: 'Atur jadwal', onPress: () => onSchedule(freelancer) },
          ...(freelancer.has_account ? [] : [{ text: 'Buat akun', onPress: () => openAccount.mutate(freelancer) }]),
          { text: 'Akhiri kontrak', style: 'destructive' as const, onPress: () => confirmEnd(freelancer) },
          { text: 'Batal', style: 'cancel' as const },
        ])
      : Alert.alert(freelancer.name, 'Aktifkan lagi dengan akun yang sama?', [
          { text: 'Batal', style: 'cancel' },
          { text: 'Aktifkan lagi', onPress: () => change.mutate(freelancer) },
        ]);

  return (
    <>
      <Segmented options={STATUSES} value={status} onChange={setStatus} />
      <Field label="Cari" value={search} onChangeText={setSearch} placeholder="Nama, nomor, atau HP" autoCorrect={false} />

      {list.isPending ? (
        <LoadingView />
      ) : list.isError ? (
        <ErrorBanner error={list.error} onRetry={() => list.refetch()} />
      ) : freelancers.length === 0 ? (
        <EmptyState icon="people-outline" title={status === 'active' ? 'Belum ada freelancer aktif' : 'Belum ada yang keluar'} />
      ) : (
        <>
          <Group title={`${total} freelancer`}>
            {freelancers.map((freelancer) => (
              <Row
                key={freelancer.id}
                title={freelancer.name}
                subtitle={[freelancer.number, freelancer.brand, freelancer.end_date ? `selesai ${formatDate(freelancer.end_date, 'short')}` : null].filter(Boolean).join(' · ')}
                right={
                  freelancer.is_active && !freelancer.has_account ? (
                    <Badge label="Tanpa akun" tone="warning" />
                  ) : freelancer.contract_state === 'not_started' ? (
                    <Badge label={freelancer.contract_state_label ?? 'Belum mulai'} tone="info" />
                  ) : undefined
                }
                onPress={() => choose(freelancer)}
              />
            ))}
          </Group>
          {list.hasNextPage ? <Button title="Muat lebih banyak" variant="secondary" onPress={() => list.fetchNextPage()} loading={list.isFetchingNextPage} /> : null}
        </>
      )}
    </>
  );
}
