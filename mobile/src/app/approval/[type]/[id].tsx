import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Alert, View } from 'react-native';

import { AttendanceDetail } from '@/components/AttendanceDetail';
import { RequestDetail } from '@/components/RequestDetail';
import type { AnyRequest } from '@/components/RequestRow';
import { Button, ErrorBanner, Field, Group, LoadingView, Notice, Screen } from '@/components/ui';
import { ApiError, approvalsApi, attendanceApi, errorMessage, requestsApi, type ApprovalType, type Attendance } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import { makeStyles, spacing } from '@/theme';

const TYPES: ApprovalType[] = ['leave', 'overtime', 'correction', 'geofence', 'resignation'];

type Loaded = { kind: 'geofence'; data: Attendance } | AnyRequest;

async function load(type: ApprovalType, id: number): Promise<Loaded> {
  switch (type) {
    case 'geofence':
      return { kind: 'geofence', data: await attendanceApi.show(id) };
    case 'leave':
      return { kind: 'leave', data: await requestsApi.leave.show(id) };
    case 'overtime':
      return { kind: 'overtime', data: await requestsApi.overtime.show(id) };
    case 'correction':
      return { kind: 'correction', data: await requestsApi.correction.show(id) };
    case 'resignation':
      return { kind: 'resignation', data: await requestsApi.resignation.show(id) };
  }
}

/** Messages of a decision refused because the request is no longer at the stage the approver saw. */
const STALE_MESSAGES = ['Pengajuan ini sudah diproses.', 'Pengajuan ini sudah tidak berstatus pending.'];

/** Whether processing failed because the request was decided, moved on or cancelled after the screen loaded. */
function isStaleDecision(error: unknown): boolean {
  if (!(error instanceof ApiError)) {
    return false;
  }
  return error.status === 404 || (error.status === 422 && STALE_MESSAGES.includes(error.message));
}

export default function ApprovalDetailScreen() {
  const styles = useStyles();
  const params = useLocalSearchParams<{ type: string; id: string }>();
  const type = TYPES.find((value) => value === params.type) ?? null;
  const id = Number(params.id);
  const queryClient = useQueryClient();
  const [note, setNote] = useState('');
  const [noteError, setNoteError] = useState<string | null>(null);

  const query = useQuery({
    queryKey: queryKeys.requestDetail(type ?? 'unknown', id),
    queryFn: () => load(type as ApprovalType, id),
    enabled: type !== null && Number.isFinite(id),
  });

  const process = useMutation({
    mutationFn: (action: 'approved' | 'rejected') =>
      approvalsApi.process(type as ApprovalType, id, action, note.trim() || null, query.data?.data.current_step ?? null),
    onSuccess: async (response) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.approvals }),
        queryClient.invalidateQueries({ queryKey: ['requests'] }),
        queryClient.invalidateQueries({ queryKey: ['attendance'] }),
        // An approved backup check-in rosters the backup and can give the replaced colleague a day off.
        queryClient.invalidateQueries({ queryKey: queryKeys.team }),
      ]);
      Alert.alert('Selesai', response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => {
      // Decided or cancelled meanwhile (or moved to another stage): this screen is stale, so the lists are
      // reloaded and the approver goes back instead of retrying the same tap.
      if (isStaleDecision(error)) {
        void queryClient.invalidateQueries({ queryKey: queryKeys.approvals });
        void queryClient.invalidateQueries({ queryKey: queryKeys.requestDetail(type ?? 'unknown', id) });
        Alert.alert('Pengajuan sudah berubah', errorMessage(error), [{ text: 'OK', onPress: () => router.back() }]);
        return;
      }
      Alert.alert('Gagal', errorMessage(error));
    },
  });

  const approve = () => {
    Alert.alert('Setujui pengajuan?', 'Pengajuan akan lanjut ke tahap berikutnya atau selesai bila ini tahap terakhir.', [
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
    Alert.alert('Tolak pengajuan?', 'Penolakan langsung mengakhiri proses pengajuan ini.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Tolak', style: 'destructive', onPress: () => process.mutate('rejected') },
    ]);
  };

  if (!type) {
    return (
      <Screen>
        <ErrorBanner message="Jenis persetujuan tidak dikenal." />
      </Screen>
    );
  }
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

  const item = query.data;
  const status = item.data.status;
  const isPending = status === 'pending' || status === 'pending_approval';

  const decisionActions = isPending ? (
    <View style={styles.actions}>
      <Button title="Tolak" variant="danger" icon="close" size="lg" onPress={reject} disabled={process.isPending} style={styles.action} />
      <Button title="Setujui" icon="checkmark" size="lg" onPress={approve} loading={process.isPending} style={styles.action} />
    </View>
  ) : undefined;

  return (
    <Screen onRefresh={() => query.refetch()} footer={decisionActions}>
      {item.kind === 'geofence' ? (
        <>
          <Notice tone="warning" message={attendanceApi.approvalReasonOf(item.data)} />
          <AttendanceDetail attendance={item.data} />
        </>
      ) : (
        <RequestDetail item={item} />
      )}

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
