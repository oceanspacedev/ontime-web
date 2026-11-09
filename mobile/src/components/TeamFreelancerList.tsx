import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Alert } from 'react-native';

import { Badge, EmptyState, ErrorBanner, Field, Group, LoadingView, Row, Segmented } from '@/components/ui';
import { showNewAccount } from '@/lib/accountNotice';
import { errorMessage, teamApi, type TeamFreelancer } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';

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
  const list = useQuery({ queryKey: queryKeys.teamFreelancers(status, search.trim()), queryFn: () => teamApi.freelancers(status, search.trim()) });
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
        <ErrorBanner message={errorMessage(list.error)} onRetry={() => list.refetch()} />
      ) : list.data.data.length === 0 ? (
        <EmptyState icon="people-outline" title={status === 'active' ? 'Belum ada freelancer aktif' : 'Belum ada yang keluar'} />
      ) : (
        <Group title={`${list.data.pagination.total} freelancer`}>
          {list.data.data.map((freelancer) => (
            <Row
              key={freelancer.id}
              title={freelancer.name}
              subtitle={[freelancer.number, freelancer.brand, freelancer.end_date ? `selesai ${formatDate(freelancer.end_date, 'short')}` : null].filter(Boolean).join(' · ')}
              right={freelancer.is_active && !freelancer.has_account ? <Badge label="Tanpa akun" tone="warning" /> : undefined}
              onPress={() => choose(freelancer)}
            />
          ))}
        </Group>
      )}
    </>
  );
}
