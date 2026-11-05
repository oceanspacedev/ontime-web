import { useMutation, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert, View } from 'react-native';

import { AttachmentField } from '@/components/AttachmentField';
import { DateTimeField } from '@/components/DateTimeField';
import { Button, Field, Group, Notice, Screen } from '@/components/ui';
import { ApiError, errorMessage, requestsApi, type UploadFile } from '@/lib/api';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import { queryKeys } from '@/lib/queryClient';
import { makeStyles, spacing } from '@/theme';

export default function NewCorrectionScreen() {
  const styles = useStyles();
  const queryClient = useQueryClient();
  const [date, setDate] = useState<string | null>(null);
  const [checkIn, setCheckIn] = useState<string | null>(null);
  const [checkOut, setCheckOut] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [attachment, setAttachment] = useState<UploadFile | null>(null);
  const [errors, setErrors] = useState<FieldErrors>({});

  const submit = useMutation({
    mutationFn: () =>
      requestsApi.correction.create({
        date: date ?? '',
        corrected_check_in: checkIn,
        corrected_check_out: checkOut,
        reason: reason.trim(),
        attachment,
      }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.corrections });
      Alert.alert('Terkirim', response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const validate = (): boolean => {
    const next: FieldErrors = {};
    if (!date) {
      next.date = requiredMessage('Tanggal');
    }
    if (!checkIn && !checkOut) {
      next.corrected_check_in = 'Isi jam masuk atau jam pulang yang benar.';
    }
    if (!reason.trim()) {
      next.reason = requiredMessage('Alasan');
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const generalError = submit.isError && !(submit.error instanceof ApiError && submit.error.errors) ? errorMessage(submit.error) : null;

  return (
    <Screen
      footer={
        <>
          {generalError ? <Notice tone="danger" message={generalError} /> : null}
          <Button title="Kirim pengajuan" icon="send" size="lg" onPress={() => validate() && submit.mutate()} loading={submit.isPending} />
        </>
      }>
      <Notice tone="info" message="Ajukan koreksi bila lupa absen atau jam presensi tercatat salah. Setelah disetujui, presensi di tanggal itu diperbarui." />
      <Group title="Presensi" padded>
        <DateTimeField label="Tanggal presensi" mode="date" value={date} onChange={setDate} error={errors.date} />
        <View style={styles.row}>
          <View style={styles.flex}>
            <DateTimeField label="Jam masuk yang benar" mode="time" value={checkIn} onChange={setCheckIn} error={errors.corrected_check_in} />
          </View>
          <View style={styles.flex}>
            <DateTimeField label="Jam pulang yang benar" mode="time" value={checkOut} onChange={setCheckOut} error={errors.corrected_check_out} />
          </View>
        </View>
      </Group>
      <Group title="Keterangan" padded>
        <Field label="Alasan" value={reason} onChangeText={setReason} multiline placeholder="Mengapa presensi perlu dikoreksi" error={errors.reason} maxLength={1000} />
        <AttachmentField value={attachment} onChange={setAttachment} error={errors.attachment} />
      </Group>
    </Screen>
  );
}

const useStyles = makeStyles(() => ({
  row: { flexDirection: 'row', gap: spacing.sm },
  flex: { flex: 1 },
}));
