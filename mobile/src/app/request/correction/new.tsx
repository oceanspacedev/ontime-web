import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { Alert, Switch, View } from 'react-native';

import { AttachmentField } from '@/components/AttachmentField';
import { DateTimeField } from '@/components/DateTimeField';
import { Button, Choice, Field, Group, Notice, Row, Screen, Text } from '@/components/ui';
import { attendanceApi, requestsApi, type UploadFile } from '@/lib/api';
import { unshownErrorMessage } from '@/lib/errors';
import { correctionClientError, correctionTargets, initialCorrectionDate, isCompleteListing, ownAttendanceRows } from '@/lib/correction';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import { queryKeys } from '@/lib/queryClient';
import { useSession } from '@/lib/session';
import { fontSize, makeStyles, spacing, useTheme } from '@/theme';

/** Fields this form shows a server message under; any other 422 key goes to the footer notice. */
const SHOWN_FIELDS = ['date', 'corrected_check_in', 'corrected_check_out', 'reason', 'attachment', 'attendance_id', 'schedule_id', 'check_out_next_day'];

/** "Let the server infer it" in the schedule picker. */
const AUTO_TARGET = 'auto';

export default function NewCorrectionScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const queryClient = useQueryClient();
  const params = useLocalSearchParams<{ date?: string }>();
  // A correction is for a day that has already happened, so the picker stops at today.
  const today = useMemo(() => new Date(), []);
  const [date, setDate] = useState<string | null>(() => initialCorrectionDate(params.date, today));
  const [checkIn, setCheckIn] = useState<string | null>(null);
  const [checkOut, setCheckOut] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [attachment, setAttachment] = useState<UploadFile | null>(null);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [checkOutNextDay, setCheckOutNextDay] = useState(false);
  const [target, setTarget] = useState<string>(AUTO_TARGET);
  const user = useSession((state) => state.user);
  // The worker's own attendance of the date: a picker when it has several, and the check-in check below.
  // `mine` keeps a coordinator's team off the page; older servers ignore it, so the rows are filtered here too.
  const dayFilter = { start_date: date ?? '', end_date: date ?? '', mine: 1 as const };
  const day = useQuery({
    queryKey: queryKeys.attendances(dayFilter),
    queryFn: () => attendanceApi.list(dayFilter),
    enabled: date !== null,
    retry: false,
  });
  const dayRows = day.isSuccess ? ownAttendanceRows(day.data.data.filter((row) => row.date?.slice(0, 10) === date), user) : null;
  /** Every own row of the date is known only when the listing fits one page (see isCompleteListing). */
  const dayRowsComplete = day.isSuccess && isCompleteListing(day.data.pagination);
  const targets = correctionTargets(dayRows);
  const picked = targets.find((item) => String(item.attendance_id) === target) ?? null;
  const chooseDate = (value: string | null) => {
    setDate(value);
    setTarget(AUTO_TARGET);
  };

  const submit = useMutation({
    mutationFn: () =>
      requestsApi.correction.create({
        date: date ?? '',
        corrected_check_in: checkIn,
        corrected_check_out: checkOut,
        reason: reason.trim(),
        attachment,
        attendance_id: picked?.attendance_id ?? null,
        schedule_id: picked?.schedule_id ?? null,
        check_out_next_day: checkOut !== null && checkOutNextDay,
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
    } else {
      const clientError = correctionClientError({
        checkIn,
        checkOut,
        rows: dayRows,
        pickedAttendanceId: picked?.attendance_id ?? null,
        complete: dayRowsComplete,
      });
      if (clientError) {
        next[clientError.field] = clientError.message;
      }
    }
    if (!reason.trim()) {
      next.reason = requiredMessage('Alasan');
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const generalError = unshownErrorMessage(submit.error, SHOWN_FIELDS);

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
        <DateTimeField label="Tanggal presensi" mode="date" value={date} onChange={chooseDate} error={errors.date} maximumDate={today} />
        {targets.length > 0 ? (
          <View style={styles.field}>
            <Text style={styles.label}>Jadwal yang dikoreksi</Text>
            <Choice
              options={[{ value: AUTO_TARGET, label: 'Sesuai jam yang diisi' }, ...targets.map((item) => ({ value: String(item.attendance_id), label: item.label }))]}
              value={target}
              onChange={setTarget}
            />
            {errors.attendance_id || errors.schedule_id ? <Text style={styles.error}>{errors.attendance_id ?? errors.schedule_id}</Text> : null}
          </View>
        ) : null}
        <View style={styles.row}>
          <View style={styles.flex}>
            <DateTimeField label="Jam masuk yang benar" mode="time" value={checkIn} onChange={setCheckIn} error={errors.corrected_check_in} />
          </View>
          <View style={styles.flex}>
            <DateTimeField label="Jam pulang yang benar" mode="time" value={checkOut} onChange={setCheckOut} error={errors.corrected_check_out} />
          </View>
        </View>
        {checkOut ? (
          <Row
            icon="moon-outline"
            title="Jam pulang keesokan harinya"
            subtitle="Untuk shift yang selesai lewat tengah malam"
            right={
              <Switch value={checkOutNextDay} onValueChange={setCheckOutNextDay} trackColor={{ true: colors.primary }} accessibilityLabel="Jam pulang keesokan harinya" />
            }
          />
        ) : null}
        {errors.check_out_next_day ? <Text style={styles.error}>{errors.check_out_next_day}</Text> : null}
      </Group>
      <Group title="Keterangan" padded>
        <Field label="Alasan" value={reason} onChangeText={setReason} multiline placeholder="Mengapa presensi perlu dikoreksi" error={errors.reason} maxLength={1000} />
        <AttachmentField value={attachment} onChange={setAttachment} error={errors.attachment} />
      </Group>
    </Screen>
  );
}

const useStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', gap: spacing.sm },
  flex: { flex: 1 },
  field: { gap: spacing.sm },
  label: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '600', paddingHorizontal: 4 },
  error: { fontSize: fontSize.sm, color: c.danger, fontWeight: '500' },
}));
