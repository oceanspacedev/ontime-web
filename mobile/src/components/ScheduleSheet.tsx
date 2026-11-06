import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { ScrollView } from 'react-native';

import { Button, Choice, Field, LoadingView, Notice, Segmented, Sheet, Text } from '@/components/ui';
import { errorMessage, teamApi, type ScheduleMode, type WorkerType } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { shiftText } from '@/lib/team';
import { makeStyles, spacing } from '@/theme';

export interface ScheduleTarget {
  worker: { type: WorkerType; id: number; name: string; company_id: number };
  date: string;
  /** The schedule already set for that day, if any. */
  current?: { shift_id: number | null; location_id: number | null; section: string | null; is_day_off: boolean } | null;
  /** Outlet to start with when there is no schedule yet, usually the outlet of the worker's brand. */
  defaultLocationId?: number | null;
}

const NO_OUTLET = 'none';

/**
 * Change one person's day: a shift with outlet and section, a day off, or remove the schedule.
 */
export function ScheduleSheet({ target, onClose, onSaved }: { target: ScheduleTarget | null; onClose: () => void; onSaved?: () => void }) {
  const styles = useStyles();
  return (
    <Sheet visible={target !== null} onClose={onClose} title={target?.worker.name}>
      {target ? (
        <>
          <Text style={styles.date}>{formatDate(target.date, 'weekday')}</Text>
          <ScheduleForm key={`${target.worker.type}:${target.worker.id}:${target.date}`} target={target} onClose={onClose} onSaved={onSaved} />
        </>
      ) : null}
    </Sheet>
  );
}

function ScheduleForm({ target, onClose, onSaved }: { target: ScheduleTarget; onClose: () => void; onSaved?: () => void }) {
  const styles = useStyles();
  const queryClient = useQueryClient();
  const options = useQuery({ queryKey: queryKeys.teamOptions, queryFn: teamApi.options });
  const company = useMemo(() => options.data?.companies.find((item) => item.id === target.worker.company_id) ?? null, [options.data, target]);

  const [mode, setMode] = useState<ScheduleMode>(target.current?.is_day_off ? 'off' : 'shift');
  const [shiftId, setShiftId] = useState<string | null>(target.current?.shift_id ? String(target.current.shift_id) : null);
  const startOutlet = target.current ? target.current.location_id : target.defaultLocationId;
  const [outletId, setOutletId] = useState<string>(startOutlet ? String(startOutlet) : NO_OUTLET);
  const [section, setSection] = useState(target.current?.section ?? '');

  // An outlet narrows the shifts to its brand; without one every brand is listed with its name.
  const outlet = company?.outlets.find((item) => String(item.id) === outletId) ?? null;
  const shifts = (company?.shifts ?? []).filter((shift) => !outlet || shift.brand === null || shift.brand === outlet.brand);
  const chooseOutlet = (value: string) => {
    setOutletId(value);
    const nextBrand = company?.outlets.find((item) => String(item.id) === value)?.brand;
    const current = company?.shifts.find((shift) => String(shift.id) === shiftId);
    if (current && value !== NO_OUTLET && current.brand !== null && current.brand !== nextBrand) {
      setShiftId(null);
    }
  };

  const save = useMutation({
    mutationFn: () =>
      teamApi.saveSchedule({
        worker_type: target.worker.type,
        worker_id: target.worker.id,
        date: target.date,
        mode,
        shift_id: mode === 'shift' && shiftId ? Number(shiftId) : null,
        company_location_id: mode === 'shift' && outletId !== NO_OUTLET ? Number(outletId) : null,
        section: mode === 'shift' ? section.trim() || null : null,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.team });
      onSaved?.();
      onClose();
    },
  });

  const modes: { value: ScheduleMode; label: string }[] = [
    { value: 'shift', label: 'Masuk' },
    { value: 'off', label: 'Libur' },
    ...(target.current ? [{ value: 'clear' as const, label: 'Kosongkan' }] : []),
  ];

  return (
    <>
      <ScrollView style={styles.body} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <Segmented options={modes} value={mode} onChange={setMode} />

        {mode === 'shift' ? (
          options.isPending ? (
            <LoadingView />
          ) : options.isError ? (
            <Notice tone="danger" message={errorMessage(options.error)} />
          ) : company ? (
            <>
              <Text style={styles.label}>Outlet</Text>
              <Choice
                options={[...company.outlets.map((item) => ({ value: String(item.id), label: item.name })), { value: NO_OUTLET, label: 'Lokasi mana pun' }]}
                value={outletId}
                onChange={chooseOutlet}
              />
              <Text style={styles.label}>Shift</Text>
              <Choice
                options={shifts.map((shift) => ({ value: String(shift.id), label: outlet || !shift.brand ? shiftText(shift) : `${shift.brand} ${shiftText(shift)}` }))}
                value={shiftId}
                onChange={setShiftId}
              />
              <Field label="Section" value={section} onChangeText={setSection} placeholder={company.sections.slice(0, 3).join(', ') || 'Kasir, Bar, Cook'} maxLength={50} />
            </>
          ) : (
            <Notice tone="warning" message="Tidak ada shift yang bisa dipilih untuk orang ini." />
          )
        ) : null}

        {save.isError ? <Notice tone="danger" message={errorMessage(save.error)} /> : null}
      </ScrollView>
      <Button
        title={mode === 'clear' ? 'Hapus jadwal' : 'Simpan'}
        variant={mode === 'clear' ? 'danger' : 'primary'}
        size="lg"
        loading={save.isPending}
        disabled={mode === 'shift' && !shiftId}
        onPress={() => save.mutate()}
      />
    </>
  );
}

const useStyles = makeStyles((c) => ({
  date: { textAlign: 'center', color: c.textMuted, marginTop: -spacing.md },
  body: { maxHeight: 460 },
  content: { gap: spacing.md },
  label: { color: c.textMuted, fontWeight: '500', paddingHorizontal: 4 },
}));
