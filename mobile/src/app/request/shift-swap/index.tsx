import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type Href, router, Stack } from 'expo-router';
import { useState } from 'react';
import { Alert, View } from 'react-native';

import { ShiftSwapCard } from '@/components/ShiftSwapCard';
import { Button, EmptyState, ErrorBanner, Group, LoadingView, Notice, Screen, Segmented } from '@/components/ui';
import { errorMessage } from '@/lib/api/client';
import * as shiftSwapApi from '@/lib/api/shiftSwap';
import type { ShiftSwapBox } from '@/lib/api/shiftSwap';
import type { ShiftSwapRequest } from '@/lib/api/types';
import { SHIFT_SWAP_QUERY_ROOT, shiftSwapQueryKey } from '@/lib/shiftSwap';
import { makeStyles, spacing } from '@/theme';

const BOXES: { value: ShiftSwapBox; label: string }[] = [
  { value: 'all', label: 'Semua' },
  { value: 'received', label: 'Masuk' },
  { value: 'sent', label: 'Terkirim' },
];

/**
 * Tukar Shift: the requests the worker sent and the ones colleagues sent them. The colleague accepts or
 * declines here; the requester can cancel while the request is still open.
 */
export default function ShiftSwapListScreen() {
  const styles = useStyles();
  const queryClient = useQueryClient();
  const [box, setBox] = useState<ShiftSwapBox>('all');
  const list = useQuery({ queryKey: shiftSwapQueryKey(box), queryFn: () => shiftSwapApi.list(box) });

  const refresh = () => queryClient.invalidateQueries({ queryKey: SHIFT_SWAP_QUERY_ROOT });

  const action = useMutation({
    mutationFn: ({ kind, id }: { kind: 'accept' | 'decline' | 'cancel'; id: number }) =>
      kind === 'accept' ? shiftSwapApi.accept(id) : kind === 'decline' ? shiftSwapApi.decline(id) : shiftSwapApi.cancel(id),
    onSuccess: async (response) => {
      await refresh();
      Alert.alert('Selesai', response.message);
    },
    onError: async (error) => {
      // The request may have changed meanwhile (cancelled, expired, schedule changed): reload so the card shows why.
      await refresh();
      Alert.alert('Gagal', errorMessage(error));
    },
  });

  const confirm = (swap: ShiftSwapRequest, kind: 'accept' | 'decline' | 'cancel') => {
    const texts = {
      accept: ['Terima tukar shift?', 'Setelah Anda terima, pengajuan diteruskan ke atasan untuk disetujui.', 'Terima'],
      decline: ['Tolak ajakan ini?', 'Pengajuan tukar shift langsung berakhir.', 'Tolak'],
      cancel: ['Batalkan pengajuan?', 'Rekan dan atasan tidak perlu memprosesnya lagi.', 'Batalkan'],
    }[kind];
    Alert.alert(texts[0], texts[1], [
      { text: 'Kembali', style: 'cancel' },
      { text: texts[2], style: kind === 'accept' ? 'default' : 'destructive', onPress: () => action.mutate({ kind, id: swap.id }) },
    ]);
  };

  const actionsFor = (swap: ShiftSwapRequest) => {
    if (swap.can_respond) {
      return (
        <View style={styles.actions}>
          <Button title="Tolak" variant="danger" icon="close" onPress={() => confirm(swap, 'decline')} disabled={action.isPending} style={styles.action} />
          <Button title="Terima" icon="checkmark" onPress={() => confirm(swap, 'accept')} loading={action.isPending} style={styles.action} />
        </View>
      );
    }
    if (swap.can_cancel) {
      return <Button title="Batalkan pengajuan" variant="secondary" icon="close-circle-outline" onPress={() => confirm(swap, 'cancel')} disabled={action.isPending} />;
    }
    return null;
  };

  return (
    <Screen onRefresh={() => list.refetch()}>
      <Stack.Screen options={{ title: 'Tukar Shift' }} />
      <Segmented options={BOXES} value={box} onChange={setBox} />
      <Button title="Ajukan Tukar Shift" icon="add" size="lg" onPress={() => router.push('/request/shift-swap/new' as Href)} />

      {list.data && list.data.awaiting_my_answer > 0 ? (
        <Notice tone="warning" message={`${list.data.awaiting_my_answer} ajakan tukar shift menunggu jawaban Anda.`} />
      ) : null}

      {list.isPending ? (
        <LoadingView />
      ) : list.isError ? (
        <ErrorBanner error={list.error} onRetry={() => list.refetch()} />
      ) : list.data.data.length === 0 ? (
        <Group title="Riwayat">
          <EmptyState icon="swap-horizontal" title="Belum ada tukar shift" message="Ajakan tukar shift yang Anda kirim atau terima akan tampil di sini." />
        </Group>
      ) : (
        <Group title="Riwayat">
          {list.data.data.map((swap) => (
            <ShiftSwapCard key={swap.id} swap={swap} footer={actionsFor(swap)} />
          ))}
        </Group>
      )}
    </Screen>
  );
}

const useStyles = makeStyles(() => ({
  actions: { flexDirection: 'row', gap: spacing.sm },
  action: { flex: 1 },
}));
