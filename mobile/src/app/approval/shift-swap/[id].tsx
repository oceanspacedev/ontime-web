import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Alert, View } from 'react-native';

import { ShiftSwapCard } from '@/components/ShiftSwapCard';
import { Button, ErrorBanner, Field, Group, InfoRow, LoadingView, Notice, Screen } from '@/components/ui';
import { ApiError, errorMessage } from '@/lib/api/client';
import * as shiftSwapApi from '@/lib/api/shiftSwap';
import { queryKeys } from '@/lib/queryClient';
import { SHIFT_SWAP_QUERY_ROOT, shiftSwapDetailKey } from '@/lib/shiftSwap';
import { makeStyles, spacing } from '@/theme';

/** Messages of a decision refused because the request changed after the screen loaded. */
const STALE_MESSAGES = ['Pengajuan ini sudah diproses.', 'Pengajuan ini sudah tidak berstatus pending.'];

/**
 * Approver's view of a Tukar Shift: both workers' schedules before and after, then approve or reject. A final
 * approval swaps the schedules at once; the server refuses (and closes the request) when a schedule changed or
 * someone already checked in.
 */
export default function ShiftSwapApprovalScreen() {
  const styles = useStyles();
  const params = useLocalSearchParams<{ id: string }>();
  const id = Number(params.id);
  const queryClient = useQueryClient();
  const [note, setNote] = useState('');
  const [noteError, setNoteError] = useState<string | null>(null);

  const query = useQuery({
    queryKey: shiftSwapDetailKey(id),
    queryFn: () => shiftSwapApi.show(id),
    enabled: Number.isFinite(id),
  });

  const reload = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: queryKeys.approvals }),
      queryClient.invalidateQueries({ queryKey: SHIFT_SWAP_QUERY_ROOT }),
      // A final approval swaps both schedules: the mounted Tim tab and today's card must show it at once.
      queryClient.invalidateQueries({ queryKey: queryKeys.team }),
      queryClient.invalidateQueries({ queryKey: queryKeys.today }),
    ]);

  const process = useMutation({
    mutationFn: (action: 'approved' | 'rejected') => shiftSwapApi.process(id, action, note.trim() || null, query.data?.current_step ?? null),
    onSuccess: async (response) => {
      await reload();
      Alert.alert('Selesai', response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: async (error) => {
      await reload();
      const stale = error instanceof ApiError && (error.status === 404 || (error.status === 422 && STALE_MESSAGES.includes(error.message)));
      Alert.alert(stale ? 'Pengajuan sudah berubah' : 'Tidak bisa diproses', errorMessage(error), stale ? [{ text: 'OK', onPress: () => router.back() }] : undefined);
    },
  });

  const approve = () => {
    Alert.alert('Setujui tukar shift?', 'Bila ini tahap terakhir, jadwal kedua pekerja langsung ditukar.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Setujui', onPress: () => process.mutate('approved') },
    ]);
  };

  const reject = () => {
    if (!note.trim()) {
      setNoteError('Alasan penolakan wajib diisi.');
      return;
    }
    setNoteError(null);
    Alert.alert('Tolak tukar shift?', 'Penolakan langsung mengakhiri pengajuan ini.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Tolak', style: 'destructive', onPress: () => process.mutate('rejected') },
    ]);
  };

  if (query.isPending) {
    return <LoadingView />;
  }
  if (query.isError) {
    return (
      <Screen>
        <ErrorBanner error={query.error} onRetry={() => query.refetch()} />
      </Screen>
    );
  }

  const swap = query.data;
  const isPending = swap.status === 'pending';

  return (
    <Screen
      onRefresh={() => query.refetch()}
      footer={
        isPending ? (
          <View style={styles.actions}>
            <Button title="Tolak" variant="danger" icon="close" size="lg" onPress={reject} disabled={process.isPending} style={styles.action} />
            <Button title="Setujui" icon="checkmark" size="lg" onPress={approve} loading={process.isPending} style={styles.action} />
          </View>
        ) : undefined
      }>
      <Stack.Screen options={{ title: 'Persetujuan Tukar Shift' }} />
      <Group title="Tukar Shift">
        <ShiftSwapCard swap={swap} />
      </Group>

      {swap.approval_steps.length > 0 ? (
        <Group title="Tahap persetujuan" padded>
          {swap.approval_steps.map((step) => (
            <InfoRow key={step.step_order} label={`${step.step_order}. ${step.step_name}`} value={step.status === 'approved' ? 'Disetujui' : step.status === 'rejected' ? 'Ditolak' : 'Menunggu'} />
          ))}
        </Group>
      ) : null}

      {isPending ? (
        <Group title="Keputusan" padded>
          <Field
            label="Catatan"
            value={note}
            onChangeText={(value) => {
              setNote(value);
              if (value.trim()) {
                setNoteError(null);
              }
            }}
            multiline
            placeholder="Wajib diisi bila menolak, opsional bila menyetujui"
            error={noteError}
            maxLength={1000}
          />
        </Group>
      ) : (
        <Notice tone="info" message="Pengajuan ini sudah tidak menunggu persetujuan." />
      )}
    </Screen>
  );
}

const useStyles = makeStyles(() => ({
  actions: { flexDirection: 'row', gap: spacing.sm },
  action: { flex: 1 },
}));
