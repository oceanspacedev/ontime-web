import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert } from 'react-native';

import { Badge, Button, EmptyState, ErrorBanner, Field, Group, LoadingView, Row, Screen, Segmented } from '@/components/ui';
import { errorMessage, teamApi, type TeamFreelancer } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';

type Status = 'active' | 'ended';

const STATUSES: { value: Status; label: string }[] = [
  { value: 'active', label: 'Aktif' },
  { value: 'ended', label: 'Sudah keluar' },
];

export default function FreelancersScreen() {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<Status>('active');
  const [search, setSearch] = useState('');
  const list = useQuery({ queryKey: queryKeys.teamFreelancers(status, search.trim()), queryFn: () => teamApi.freelancers(status, search.trim()) });

  const change = useMutation({
    mutationFn: (freelancer: TeamFreelancer) => (freelancer.is_active ? teamApi.endFreelancer(freelancer.id) : teamApi.reactivateFreelancer(freelancer.id)),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.team });
      Alert.alert(response.message);
    },
    onError: (error) => Alert.alert('Gagal', errorMessage(error)),
  });

  const confirm = (freelancer: TeamFreelancer) =>
    Alert.alert(
      freelancer.name,
      freelancer.is_active ? 'Akhiri kontrak? Akunnya dinonaktifkan dan jadwal setelah hari ini dihapus.' : 'Aktifkan lagi dengan akun yang sama?',
      [
        { text: 'Batal', style: 'cancel' },
        {
          text: freelancer.is_active ? 'Akhiri kontrak' : 'Aktifkan lagi',
          style: freelancer.is_active ? 'destructive' : 'default',
          onPress: () => change.mutate(freelancer),
        },
      ],
    );

  return (
    <Screen
      onRefresh={() => list.refetch()}
      footer={<Button title="Tambah freelancer" icon="person-add-outline" size="lg" onPress={() => router.push('/team/freelancer/new')} />}>
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
              right={freelancer.has_account ? undefined : <Badge label="Tanpa akun" tone="warning" />}
              onPress={() => confirm(freelancer)}
            />
          ))}
        </Group>
      )}
    </Screen>
  );
}
