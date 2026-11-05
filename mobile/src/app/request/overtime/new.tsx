import { useMutation, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert, StyleSheet, View } from 'react-native';

import { DateTimeField } from '@/components/DateTimeField';
import { Button, Card, Field, Notice, Screen } from '@/components/ui';
import { ApiError, errorMessage, requestsApi } from '@/lib/api';
import { formatMinutes } from '@/lib/format';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import { queryKeys } from '@/lib/queryClient';
import { spacing } from '@/theme';

function durationMinutes(start: string | null, end: string | null): number | null {
  if (!start || !end) {
    return null;
  }
  const [sh, sm] = start.split(':').map(Number);
  const [eh, em] = end.split(':').map(Number);
  let minutes = eh * 60 + em - (sh * 60 + sm);
  if (minutes < 0) {
    minutes += 24 * 60; // crosses midnight, same rule as the backend
  }
  return minutes;
}

export default function NewOvertimeScreen() {
  const queryClient = useQueryClient();
  const [date, setDate] = useState<string | null>(null);
  const [startTime, setStartTime] = useState<string | null>(null);
  const [endTime, setEndTime] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});

  const submit = useMutation({
    mutationFn: () =>
      requestsApi.overtime.create({ date: date ?? '', start_time: startTime ?? '', end_time: endTime ?? '', reason: reason.trim() }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.overtimes });
      Alert.alert('Terkirim', response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const validate = (): boolean => {
    const next: FieldErrors = {};
    if (!date) {
      next.date = requiredMessage('Tanggal');
    }
    if (!startTime) {
      next.start_time = requiredMessage('Jam mulai');
    }
    if (!endTime) {
      next.end_time = requiredMessage('Jam selesai');
    } else if (startTime === endTime) {
      next.end_time = 'Jam selesai tidak boleh sama dengan jam mulai.';
    }
    if (!reason.trim()) {
      next.reason = requiredMessage('Alasan');
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const duration = durationMinutes(startTime, endTime);
  const generalError = submit.isError && !(submit.error instanceof ApiError && submit.error.errors) ? errorMessage(submit.error) : null;

  return (
    <Screen>
      <Card>
        <DateTimeField label="Tanggal lembur" mode="date" value={date} onChange={setDate} error={errors.date} />
        <View style={styles.row}>
          <View style={styles.flex}>
            <DateTimeField label="Jam mulai" mode="time" value={startTime} onChange={setStartTime} error={errors.start_time} />
          </View>
          <View style={styles.flex}>
            <DateTimeField label="Jam selesai" mode="time" value={endTime} onChange={setEndTime} error={errors.end_time} />
          </View>
        </View>
        {duration !== null && duration > 0 ? <Notice tone="info" icon="time-outline" message={`Durasi ${formatMinutes(duration)}${endTime && startTime && endTime < startTime ? ' (melewati tengah malam)' : ''}.`} /> : null}
        <Field label="Alasan" value={reason} onChangeText={setReason} multiline placeholder="Pekerjaan yang dilakukan saat lembur" error={errors.reason} maxLength={1000} />
      </Card>
      {generalError ? <Notice tone="danger" message={generalError} /> : null}
      <Button title="Kirim pengajuan" icon="send" size="lg" onPress={() => validate() && submit.mutate()} loading={submit.isPending} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', gap: spacing.sm },
  flex: { flex: 1 },
});
