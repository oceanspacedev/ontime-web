import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { Alert } from 'react-native';

import { RequestDetail } from '@/components/RequestDetail';
import type { AnyRequest, RequestKind } from '@/components/RequestRow';
import { Button, ErrorBanner, LoadingView, Screen } from '@/components/ui';
import { errorMessage, requestsApi } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import { useSession } from '@/lib/session';

const KINDS: RequestKind[] = ['leave', 'overtime', 'correction', 'resignation'];

async function load(kind: RequestKind, id: number): Promise<AnyRequest> {
  switch (kind) {
    case 'leave':
      return { kind, data: await requestsApi.leave.show(id) };
    case 'overtime':
      return { kind, data: await requestsApi.overtime.show(id) };
    case 'correction':
      return { kind, data: await requestsApi.correction.show(id) };
    case 'resignation':
      return { kind, data: await requestsApi.resignation.show(id) };
  }
}

export default function RequestDetailScreen() {
  const params = useLocalSearchParams<{ type: string; id: string }>();
  const kind = KINDS.find((value) => value === params.type) ?? null;
  const id = Number(params.id);
  const user = useSession((state) => state.user);
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: queryKeys.requestDetail(kind ?? 'unknown', id),
    queryFn: () => load(kind as RequestKind, id),
    enabled: kind !== null && Number.isFinite(id),
  });

  const cancel = useMutation({
    mutationFn: () => requestsApi[kind as RequestKind].cancel(id),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['requests'] });
      Alert.alert('Dibatalkan', response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => Alert.alert('Gagal', errorMessage(error)),
  });

  if (!kind) {
    return (
      <Screen>
        <ErrorBanner message="Jenis pengajuan tidak dikenal." />
      </Screen>
    );
  }
  if (query.isPending) {
    return (
      <Screen scroll={false} padded={false}>
        <LoadingView />
      </Screen>
    );
  }
  if (query.isError) {
    return (
      <Screen>
        <ErrorBanner message={errorMessage(query.error)} onRetry={() => query.refetch()} />
      </Screen>
    );
  }

  const item = query.data;
  const isOwn =
    (item.data.employee_id !== null && item.data.employee_id === (user?.employee?.id ?? null)) ||
    (item.data.intern_id !== null && item.data.intern_id === (user?.intern?.id ?? null)) ||
    (item.data.freelancer_id !== null && item.data.freelancer_id === (user?.freelancer?.id ?? null));
  const canCancel = isOwn && item.data.status === 'pending';

  const confirmCancel = () => {
    Alert.alert('Batalkan pengajuan?', 'Pengajuan yang dibatalkan tidak bisa dikembalikan.', [
      { text: 'Tidak', style: 'cancel' },
      { text: 'Batalkan', style: 'destructive', onPress: () => cancel.mutate() },
    ]);
  };

  return (
    <Screen
      onRefresh={() => query.refetch()}
      footer={
        canCancel ? (
          <Button title="Batalkan pengajuan" variant="danger" size="lg" icon="close-circle-outline" onPress={confirmCancel} loading={cancel.isPending} />
        ) : undefined
      }>
      <RequestDetail item={item} />
    </Screen>
  );
}
