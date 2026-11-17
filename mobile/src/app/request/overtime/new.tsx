import { useMutation, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert, View } from 'react-native';

import { DateTimeField } from '@/components/DateTimeField';
import { Button, Field, Group, Notice, Screen } from '@/components/ui';
import { requestsApi } from '@/lib/api';
import { formatMinutes } from '@/lib/format';
import { unshownErrorMessage } from '@/lib/errors';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import {
  crossesMidnight as runsPastMidnight,
  earliestOvertimeDate,
  latestOvertimeDate,
  overtimeDateError,
  overtimeDurationMinutes,
  overtimeTimeError,
} from '@/lib/overtime';
import { queryKeys } from '@/lib/queryClient';
import { makeStyles, spacing } from '@/theme';

/** Fields this form shows a server message under; any other 422 key goes to the footer notice. */
const SHOWN_FIELDS = ['date', 'start_time', 'end_time', 'reason'];

export default function NewOvertimeScreen() {
  const styles = useStyles();
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
    const dateError = overtimeDateError(date);
    if (!date) {
      next.date = requiredMessage('Tanggal');
    } else if (dateError) {
      next.date = dateError;
    }
    if (!startTime) {
      next.start_time = requiredMessage('Jam mulai');
    }
    const timeError = overtimeTimeError(startTime, endTime);
    if (!endTime) {
      next.end_time = requiredMessage('Jam selesai');
    } else if (timeError) {
      next.end_time = timeError;
    }
    if (!reason.trim()) {
      next.reason = requiredMessage('Alasan');
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const duration = overtimeDurationMinutes(startTime, endTime);
  const crossesMidnight = runsPastMidnight(startTime, endTime);
  const tooLong = overtimeTimeError(startTime, endTime) !== null;
  const generalError = unshownErrorMessage(submit.error, SHOWN_FIELDS);

  return (
    <Screen
      footer={
        <>
          {generalError ? <Notice tone="danger" message={generalError} /> : null}
          <Button title="Kirim pengajuan" icon="send" size="lg" onPress={() => validate() && submit.mutate()} loading={submit.isPending} />
        </>
      }>
      <Group title="Waktu lembur" padded>
        <DateTimeField
          label="Tanggal lembur"
          mode="date"
          value={date}
          onChange={setDate}
          error={errors.date}
          minimumDate={earliestOvertimeDate()}
          maximumDate={latestOvertimeDate()}
          hint="Hari ini, paling lama 31 hari ke belakang atau 31 hari ke depan."
        />
        <View style={styles.row}>
          <View style={styles.flex}>
            <DateTimeField label="Jam mulai" mode="time" value={startTime} onChange={setStartTime} error={errors.start_time} />
          </View>
          <View style={styles.flex}>
            <DateTimeField label="Jam selesai" mode="time" value={endTime} onChange={setEndTime} error={errors.end_time} />
          </View>
        </View>
        {duration !== null ? (
          <Notice
            tone={tooLong ? 'danger' : 'info'}
            icon="time-outline"
            message={
              tooLong
                ? `Durasi ${formatMinutes(duration)}, melebihi batas lembur 12 jam.`
                : `Durasi ${formatMinutes(duration)}${crossesMidnight ? ' (melewati tengah malam)' : ''}.`
            }
          />
        ) : null}
      </Group>
      <Group title="Keterangan" padded>
        <Field label="Alasan" value={reason} onChangeText={setReason} multiline placeholder="Pekerjaan yang dilakukan saat lembur" error={errors.reason} maxLength={1000} />
      </Group>
    </Screen>
  );
}

const useStyles = makeStyles(() => ({
  row: { flexDirection: 'row', gap: spacing.sm },
  flex: { flex: 1 },
}));
