import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';
import { useState } from 'react';
import { Alert } from 'react-native';

import { DateTimeField } from '@/components/DateTimeField';
import { Badge, Button, EmptyState, ErrorBanner, Field, Group, LoadingView, Notice, Row, Screen, Segmented } from '@/components/ui';
import * as shiftSwapApi from '@/lib/api/shiftSwap';
import type { ShiftSwapType } from '@/lib/api/types';
import { unshownErrorMessage } from '@/lib/errors';
import { toDateString } from '@/lib/format';
import { type FieldErrors, fieldErrorsFrom } from '@/lib/forms';
import { colleagueKey, SHIFT_SWAP_QUERY_ROOT, SHIFT_SWAP_TYPE_LABEL, shiftSwapDraftErrors } from '@/lib/shiftSwap';

const TYPES: { value: ShiftSwapType; label: string }[] = [
  { value: 'swap', label: SHIFT_SWAP_TYPE_LABEL.swap },
  { value: 'give_away', label: SHIFT_SWAP_TYPE_LABEL.give_away },
];

/** Fields this form shows a server message under; any other 422 message goes to the footer notice. */
const SHOWN_FIELDS = ['requester_date', 'counterpart_date', 'colleague_id', 'reason'];

/**
 * Ajukan Tukar Shift: pick the date of your shift, the colleague (and their date for a swap), and a reason.
 * The colleague answers first, then an approver decides.
 */
export default function NewShiftSwapScreen() {
  const queryClient = useQueryClient();
  const today = toDateString(new Date());
  const [type, setType] = useState<ShiftSwapType>('swap');
  const [requesterDate, setRequesterDate] = useState<string | null>(null);
  const [counterpartDate, setCounterpartDate] = useState<string | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});

  // Swap: the colleague must work their date. Give away: the colleague must be off on yours.
  const lookupDate = type === 'swap' ? counterpartDate : requesterDate;
  const colleagues = useQuery({
    queryKey: [...SHIFT_SWAP_QUERY_ROOT, 'colleagues', lookupDate, search.trim()],
    queryFn: () => shiftSwapApi.colleagues(lookupDate as string, search),
    enabled: lookupDate !== null,
  });
  const candidates = (colleagues.data ?? []).filter((colleague) => (type === 'swap' ? !colleague.is_off : colleague.is_off));
  const chosen = (colleagues.data ?? []).find((colleague) => colleagueKey(colleague) === selected) ?? null;

  const submit = useMutation({
    mutationFn: () =>
      shiftSwapApi.create({
        type,
        requester_date: requesterDate ?? '',
        counterpart_date: type === 'swap' ? counterpartDate : null,
        colleague_type: chosen?.type ?? 'employee',
        colleague_id: chosen?.id ?? 0,
        reason: reason.trim() || null,
      }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: SHIFT_SWAP_QUERY_ROOT });
      Alert.alert('Terkirim', response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const changeType = (value: ShiftSwapType) => {
    setType(value);
    setSelected(null);
    setErrors({});
  };

  const confirm = () => {
    const next = shiftSwapDraftErrors({ type, requesterDate, counterpartDate, colleagueKey: chosen ? selected : null }, today);
    setErrors(next);
    if (Object.keys(next).length > 0 || !chosen) {
      return;
    }
    const message =
      type === 'swap'
        ? `${chosen.full_name} diajak bertukar shift. Jadwal baru berlaku setelah rekan menerima dan atasan menyetujui.`
        : `Shift Anda diserahkan ke ${chosen.full_name} dan Anda libur, setelah rekan menerima dan atasan menyetujui.`;
    Alert.alert('Kirim ajakan tukar shift?', message, [
      { text: 'Batal', style: 'cancel' },
      { text: 'Kirim', onPress: () => submit.mutate() },
    ]);
  };

  const generalError = unshownErrorMessage(submit.error, SHOWN_FIELDS);
  const minimumDate = new Date(`${today}T00:00:00`);

  return (
    <Screen
      footer={
        <>
          {generalError ? <Notice tone="danger" message={generalError} /> : null}
          <Button title="Kirim ajakan" icon="send" size="lg" onPress={confirm} loading={submit.isPending} />
        </>
      }>
      <Stack.Screen options={{ title: 'Ajukan Tukar Shift' }} />
      <Segmented options={TYPES} value={type} onChange={changeType} />
      <Notice
        tone="info"
        message={
          type === 'swap'
            ? 'Rekan mengambil shift Anda, Anda mengambil shift rekan. Pilih tanggal yang sama untuk tukar di hari yang sama.'
            : 'Rekan yang sedang libur mengambil alih shift Anda, dan Anda libur pada tanggal itu.'
        }
      />

      <Group title="Tanggal" padded>
        <DateTimeField
          label="Tanggal shift Anda"
          mode="date"
          value={requesterDate}
          onChange={(value) => {
            setRequesterDate(value);
            if (!counterpartDate) {
              setCounterpartDate(value);
            }
          }}
          error={errors.requester_date}
          minimumDate={minimumDate}
        />
        {type === 'swap' ? (
          <DateTimeField
            label="Tanggal shift rekan"
            mode="date"
            value={counterpartDate}
            onChange={(value) => {
              setCounterpartDate(value);
              setSelected(null);
            }}
            error={errors.counterpart_date}
            minimumDate={minimumDate}
            hint="Shift rekan pada tanggal ini yang Anda ambil."
          />
        ) : null}
      </Group>

      <Group title="Rekan kerja" padded footer={errors.colleague_id}>
        <Field label="Cari nama" value={search} onChangeText={setSearch} placeholder="Nama rekan" autoCapitalize="words" />
      </Group>

      {lookupDate === null ? (
        <Notice tone="info" message="Pilih tanggal dulu untuk melihat rekan yang bisa diajak." />
      ) : colleagues.isPending ? (
        <LoadingView />
      ) : colleagues.isError ? (
        <ErrorBanner error={colleagues.error} onRetry={() => colleagues.refetch()} />
      ) : candidates.length === 0 ? (
        <EmptyState
          icon="people-outline"
          title="Tidak ada rekan yang cocok"
          message={type === 'swap' ? 'Tidak ada rekan yang bekerja pada tanggal itu.' : 'Tidak ada rekan yang libur pada tanggal itu.'}
        />
      ) : (
        <Group title={type === 'swap' ? 'Rekan yang bekerja' : 'Rekan yang libur'}>
          {candidates.map((colleague) => {
            const key = colleagueKey(colleague);
            const isSelected = key === selected;
            return (
              <Row
                key={key}
                title={colleague.full_name}
                subtitle={colleague.division ?? undefined}
                icon={isSelected ? 'checkmark-circle' : 'ellipse-outline'}
                right={<Badge label={colleague.schedule_label} tone={colleague.is_off ? 'gray' : 'info'} />}
                onPress={() => setSelected(key)}
                chevron={false}
              />
            );
          })}
        </Group>
      )}

      <Group title="Keterangan" padded>
        <Field label="Alasan (opsional)" value={reason} onChangeText={setReason} multiline placeholder="Alasan tukar shift" error={errors.reason} maxLength={1000} />
      </Group>
    </Screen>
  );
}
