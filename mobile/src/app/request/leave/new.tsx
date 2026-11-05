import { useMutation, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert } from 'react-native';

import { AttachmentField } from '@/components/AttachmentField';
import { DateTimeField } from '@/components/DateTimeField';
import { Button, Choice, Field, Group, Notice, Screen } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { ApiError, errorMessage, requestsApi, type LeaveType, type UploadFile } from '@/lib/api';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import { queryKeys } from '@/lib/queryClient';

export default function NewLeaveScreen() {
  const access = useAccess();
  const queryClient = useQueryClient();
  const [leaveType, setLeaveType] = useState<LeaveType | null>(access.leaveTypes[0]?.value ?? null);
  const [startDate, setStartDate] = useState<string | null>(null);
  const [endDate, setEndDate] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [attachment, setAttachment] = useState<UploadFile | null>(null);
  const [errors, setErrors] = useState<FieldErrors>({});

  const submit = useMutation({
    mutationFn: () =>
      requestsApi.leave.create({
        leave_type: leaveType ?? '',
        start_date: startDate ?? '',
        end_date: endDate ?? '',
        reason: reason.trim(),
        attachment,
      }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.leaves });
      Alert.alert('Terkirim', response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const validate = (): boolean => {
    const next: FieldErrors = {};
    if (!leaveType) {
      next.leave_type = requiredMessage('Jenis');
    }
    if (!startDate) {
      next.start_date = requiredMessage('Tanggal mulai');
    }
    if (!endDate) {
      next.end_date = requiredMessage('Tanggal selesai');
    } else if (startDate && endDate < startDate) {
      next.end_date = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
    }
    if (!reason.trim()) {
      next.reason = requiredMessage('Alasan');
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const changeStartDate = (value: string) => {
    setStartDate(value);
    if (!endDate || endDate < value) {
      setEndDate(value);
    }
  };

  const days = startDate && endDate && endDate >= startDate ? Math.round((Date.parse(endDate) - Date.parse(startDate)) / 86400000) + 1 : null;
  const generalError = submit.isError && !(submit.error instanceof ApiError && submit.error.errors) ? errorMessage(submit.error) : null;

  return (
    <Screen
      footer={
        <>
          {generalError ? <Notice tone="danger" message={generalError} /> : null}
          <Button title="Kirim pengajuan" icon="send" size="lg" onPress={() => validate() && submit.mutate()} loading={submit.isPending} />
        </>
      }>
      <Group title="Jenis pengajuan" padded>
        <Choice options={access.leaveTypes} value={leaveType} onChange={setLeaveType} />
        {errors.leave_type ? <Notice tone="danger" message={errors.leave_type} /> : null}
      </Group>
      <Group title="Tanggal" padded>
        <DateTimeField label="Tanggal mulai" mode="date" value={startDate} onChange={changeStartDate} error={errors.start_date} />
        <DateTimeField
          label="Tanggal selesai"
          mode="date"
          value={endDate}
          onChange={setEndDate}
          error={errors.end_date}
          minimumDate={startDate ? new Date(startDate) : undefined}
          hint={days ? `${days} hari kalender` : undefined}
        />
      </Group>
      <Group title="Keterangan" padded>
        <Field label="Alasan" value={reason} onChangeText={setReason} multiline placeholder="Tuliskan alasan pengajuan" error={errors.reason} maxLength={1000} />
        <AttachmentField value={attachment} onChange={setAttachment} error={errors.attachment} />
      </Group>
    </Screen>
  );
}
