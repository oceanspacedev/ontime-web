import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert } from 'react-native';

import { DateTimeField } from '@/components/DateTimeField';
import { RequestRow } from '@/components/RequestRow';
import { Button, Card, Field, LoadingView, Notice, Screen } from '@/components/ui';
import { ApiError, errorMessage, requestsApi } from '@/lib/api';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import { queryKeys } from '@/lib/queryClient';
import { useSession } from '@/lib/session';

export default function NewResignationScreen() {
  const queryClient = useQueryClient();
  const employeeId = useSession((state) => state.user?.employee?.id ?? null);
  const [resignationDate, setResignationDate] = useState<string | null>(null);
  const [lastWorkingDay, setLastWorkingDay] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [handover, setHandover] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});

  const existing = useQuery({ queryKey: queryKeys.resignations, queryFn: () => requestsApi.resignation.list() });
  const own = existing.data?.data.find((item) => item.employee_id === employeeId && (item.status === 'pending' || item.status === 'approved'));

  const submit = useMutation({
    mutationFn: () =>
      requestsApi.resignation.create({
        resignation_date: resignationDate ?? '',
        last_working_day: lastWorkingDay ?? '',
        reason: reason.trim(),
        handover_notes: handover.trim() || null,
      }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.resignations });
      Alert.alert('Terkirim', response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const validate = (): boolean => {
    const next: FieldErrors = {};
    if (!resignationDate) {
      next.resignation_date = requiredMessage('Tanggal pengajuan');
    }
    if (!lastWorkingDay) {
      next.last_working_day = requiredMessage('Hari kerja terakhir');
    } else if (resignationDate && lastWorkingDay < resignationDate) {
      next.last_working_day = 'Hari kerja terakhir tidak boleh sebelum tanggal pengajuan.';
    }
    if (!reason.trim()) {
      next.reason = requiredMessage('Alasan');
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const confirm = () => {
    if (!validate()) {
      return;
    }
    Alert.alert('Kirim pengajuan resign?', 'Setelah disetujui penuh, akun Anda dinonaktifkan pada hari kerja terakhir.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Kirim', style: 'destructive', onPress: () => submit.mutate() },
    ]);
  };

  if (existing.isPending) {
    return <LoadingView />;
  }

  if (own) {
    return (
      <Screen>
        <Notice tone={own.status === 'approved' ? 'success' : 'warning'} message={own.status === 'approved' ? 'Pengunduran diri Anda sudah disetujui.' : 'Anda sudah punya pengajuan resign yang sedang diproses.'} />
        <RequestRow item={{ kind: 'resignation', data: own }} />
      </Screen>
    );
  }

  const generalError = submit.isError && !(submit.error instanceof ApiError && submit.error.errors) ? errorMessage(submit.error) : null;

  return (
    <Screen>
      <Notice tone="warning" message="Pengajuan ini melewati tahap persetujuan atasan. Pastikan sudah berdiskusi dengan atasan sebelum mengirim." />
      <Card>
        <DateTimeField label="Tanggal pengajuan" mode="date" value={resignationDate} onChange={setResignationDate} error={errors.resignation_date} />
        <DateTimeField label="Hari kerja terakhir" mode="date" value={lastWorkingDay} onChange={setLastWorkingDay} error={errors.last_working_day} minimumDate={resignationDate ? new Date(resignationDate) : undefined} />
        <Field label="Alasan" value={reason} onChangeText={setReason} multiline placeholder="Alasan pengunduran diri" error={errors.reason} />
        <Field label="Catatan serah terima (opsional)" value={handover} onChangeText={setHandover} multiline placeholder="Pekerjaan dan dokumen yang diserahkan" error={errors.handover_notes} />
      </Card>
      {generalError ? <Notice tone="danger" message={generalError} /> : null}
      <Button title="Kirim pengajuan resign" icon="send" size="lg" variant="danger" onPress={confirm} loading={submit.isPending} />
    </Screen>
  );
}
