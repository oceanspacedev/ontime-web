import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { ScrollView } from 'react-native';

import { Button, Choice, Field, Group, LoadingView, Notice, Row, Segmented, Sheet, Text } from '@/components/ui';
import { errorMessage, teamApi, type ScheduleMode, type WorkerType } from '@/lib/api';
import { formatDate, toDateString } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { shiftText } from '@/lib/team';
import { makeStyles, spacing } from '@/theme';

/** One schedule of the day as the sheet edits it. */
export interface ScheduleSlot {
  /** The roster row; unknown on older servers, which then change the day as a whole. */
  schedule_id?: number | null;
  shift_id: number | null;
  location_id: number | null;
  section: string | null;
  is_day_off: boolean;
  /** The schedule already has attendance: it is changed by a correction, not here. */
  locked?: boolean;
  /** What the list shows for it, e.g. "P · 08:00–16:00 · JChicken Ciledug". */
  label?: string;
}

export interface ScheduleTarget {
  worker: { type: WorkerType; id: number; name: string; company_id: number };
  date: string;
  /** The schedule to edit, if any. */
  current?: ScheduleSlot | null;
  /** Every schedule of the date, from a newer server. With more than one the sheet lists them first. */
  schedules?: ScheduleSlot[];
  /** The server knows several schedules per day (it sent the newer fields), so "Tambah jadwal" is offered. */
  supportsMulti?: boolean;
  /** Outlet to start with when there is no schedule yet, usually the outlet of the worker's brand. */
  defaultLocationId?: number | null;
}

const NO_OUTLET = 'none';

/** What the sheet shows: the day's list, one schedule (or an empty day) to edit, or a new further schedule. */
type SheetStep = { kind: 'list' } | { kind: 'edit'; slot: ScheduleSlot | null } | { kind: 'add' };

const LOCKED_MESSAGE = 'Jadwal ini sudah ada absensinya, jadi tidak bisa diubah dari sini. Ajukan koreksi absensi bila jadwalnya salah.';

/**
 * Change one person's day: a shift with outlet and section, a day off, or remove the schedule. On a day with
 * several schedules it lists them, each edited by its own id, and a further one can be added.
 */
export function ScheduleSheet({ target, onClose, onSaved }: { target: ScheduleTarget | null; onClose: () => void; onSaved?: () => void }) {
  const styles = useStyles();
  return (
    <Sheet visible={target !== null} onClose={onClose} title={target?.worker.name}>
      {target ? (
        <>
          <Text style={styles.date}>{formatDate(target.date, 'weekday')}</Text>
          <ScheduleSteps key={`${target.worker.type}:${target.worker.id}:${target.date}:${target.current?.schedule_id ?? ''}`} target={target} onClose={onClose} onSaved={onSaved} />
        </>
      ) : null}
    </Sheet>
  );
}

function initialStep(target: ScheduleTarget): SheetStep {
  if ((target.schedules?.length ?? 0) > 1 && !target.current) {
    return { kind: 'list' };
  }
  return { kind: 'edit', slot: target.current ?? null };
}

function ScheduleSteps({ target, onClose, onSaved }: { target: ScheduleTarget; onClose: () => void; onSaved?: () => void }) {
  const styles = useStyles();
  const [step, setStep] = useState<SheetStep>(() => initialStep(target));
  const schedules = target.schedules ?? (target.current ? [target.current] : []);
  const isMulti = schedules.length > 1;
  // A further schedule only next to working ones, and only on a server that understands it.
  const canAdd = target.supportsMulti === true && schedules.length > 0 && schedules.length < 3 && schedules.every((slot) => !slot.is_day_off);

  if (step.kind === 'list') {
    return (
      <>
        <ScrollView style={styles.body} contentContainerStyle={styles.content}>
          <Text style={styles.label}>{schedules.length} jadwal pada hari ini</Text>
          <Group>
            {schedules.map((slot, index) => (
              <Row
                key={slot.schedule_id ?? index}
                icon={slot.locked ? 'lock-closed-outline' : 'time-outline'}
                title={slot.label ?? `Jadwal ${index + 1}`}
                subtitle={slot.locked ? 'Sudah ada absensi' : undefined}
                onPress={() => setStep({ kind: 'edit', slot })}
              />
            ))}
          </Group>
        </ScrollView>
        {canAdd ? <Button title="Tambah jadwal" icon="add" variant="secondary" size="lg" onPress={() => setStep({ kind: 'add' })} /> : null}
      </>
    );
  }

  const editing = step.kind === 'edit' ? step.slot : null;
  const toList = () => setStep({ kind: 'list' });
  return (
    <ScheduleForm
      key={step.kind === 'add' ? 'add' : String(editing?.schedule_id ?? 'day')}
      target={target}
      slot={editing}
      add={step.kind === 'add'}
      hasOthers={schedules.some((slot) => !slot.is_day_off && !sameSlot(slot, editing))}
      onAdd={step.kind === 'edit' && canAdd ? () => setStep({ kind: 'add' }) : undefined}
      onBack={isMulti ? toList : step.kind === 'add' ? () => setStep({ kind: 'edit', slot: target.current ?? null }) : undefined}
      onClose={onClose}
      onSaved={onSaved}
    />
  );
}

/** The same schedule: the same object, or the same roster row id. */
function sameSlot(slot: ScheduleSlot, other: ScheduleSlot | null): boolean {
  if (!other) {
    return false;
  }
  return slot === other || (slot.schedule_id !== undefined && slot.schedule_id !== null && slot.schedule_id === other.schedule_id);
}

function ScheduleForm({ target, slot, add, hasOthers, onAdd, onBack, onClose, onSaved }: {
  target: ScheduleTarget;
  slot: ScheduleSlot | null;
  /** Creates a further schedule on the day instead of changing one. */
  add: boolean;
  /** Other working schedules stay on the day, so it cannot become a day off. */
  hasOthers: boolean;
  onAdd?: () => void;
  onBack?: () => void;
  onClose: () => void;
  onSaved?: () => void;
}) {
  const styles = useStyles();
  const queryClient = useQueryClient();
  const options = useQuery({ queryKey: queryKeys.teamOptions, queryFn: teamApi.options });
  const company = useMemo(() => options.data?.companies.find((item) => item.id === target.worker.company_id) ?? null, [options.data, target]);
  const current = add ? null : slot;

  const [mode, setMode] = useState<ScheduleMode>(current?.is_day_off ? 'off' : 'shift');
  const [shiftId, setShiftId] = useState<string | null>(current?.shift_id ? String(current.shift_id) : null);
  const startOutlet = current ? current.location_id : (target.current?.location_id ?? target.defaultLocationId);
  const [outletId, setOutletId] = useState<string>(startOutlet ? String(startOutlet) : NO_OUTLET);
  const [section, setSection] = useState(current?.section ?? '');
  const [reason, setReason] = useState('');
  // A change to a day that has passed is logged with its reason.
  const isPast = target.date < toDateString(new Date());

  // An outlet narrows the shifts to its brand; without one every brand is listed with its name.
  const outlet = company?.outlets.find((item) => String(item.id) === outletId) ?? null;
  const shifts = (company?.shifts ?? []).filter((shift) => !outlet || shift.brand === null || shift.brand === outlet.brand);
  const chooseOutlet = (value: string) => {
    setOutletId(value);
    const nextBrand = company?.outlets.find((item) => String(item.id) === value)?.brand;
    const picked = company?.shifts.find((shift) => String(shift.id) === shiftId);
    if (picked && value !== NO_OUTLET && picked.brand !== null && picked.brand !== nextBrand) {
      setShiftId(null);
    }
  };

  const save = useMutation({
    mutationFn: () =>
      teamApi.saveSchedule({
        worker_type: target.worker.type,
        worker_id: target.worker.id,
        date: target.date,
        mode: add ? 'shift' : mode,
        shift_id: (add || mode === 'shift') && shiftId ? Number(shiftId) : null,
        company_location_id: (add || mode === 'shift') && outletId !== NO_OUTLET ? Number(outletId) : null,
        section: add || mode === 'shift' ? section.trim() || null : null,
        schedule_id: add ? null : (current?.schedule_id ?? null),
        add,
        reason: isPast ? reason : null,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.team });
      onSaved?.();
      onClose();
    },
  });

  const modes: { value: ScheduleMode; label: string }[] = [
    { value: 'shift', label: 'Masuk' },
    ...(hasOthers ? [] : [{ value: 'off' as const, label: 'Libur' }]),
    ...(current ? [{ value: 'clear' as const, label: 'Kosongkan' }] : []),
  ];
  const effectiveMode = add ? 'shift' : mode;
  const locked = current?.locked === true;

  return (
    <>
      <ScrollView style={styles.body} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        {add ? <Text style={styles.label}>Jadwal tambahan pada hari ini</Text> : <Segmented options={modes} value={mode} onChange={setMode} />}
        {locked ? <Notice tone="warning" icon="lock-closed-outline" message={LOCKED_MESSAGE} /> : null}

        {effectiveMode === 'shift' ? (
          options.isPending ? (
            <LoadingView />
          ) : options.isError ? (
            <Notice tone="danger" message={errorMessage(options.error)} />
          ) : company ? (
            <>
              <Text style={styles.label}>Outlet</Text>
              <Choice
                options={[...company.outlets.map((item) => ({ value: String(item.id), label: item.own_brand === false ? `${item.name} · pinjam` : item.name })), { value: NO_OUTLET, label: 'Lokasi mana pun' }]}
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

        {isPast ? <Field label="Alasan perubahan (opsional)" value={reason} onChangeText={setReason} placeholder="Mengapa jadwal yang sudah lewat diubah" maxLength={255} /> : null}

        {save.isError ? <Notice tone="danger" message={errorMessage(save.error)} /> : null}
      </ScrollView>
      <Button
        title={effectiveMode === 'clear' ? 'Hapus jadwal' : add ? 'Tambah jadwal' : 'Simpan'}
        variant={effectiveMode === 'clear' ? 'danger' : 'primary'}
        size="lg"
        loading={save.isPending}
        disabled={locked || (effectiveMode === 'shift' && !shiftId)}
        onPress={() => save.mutate()}
      />
      {onAdd ? <Button title="Tambah jadwal" icon="add" variant="secondary" onPress={onAdd} disabled={save.isPending} /> : null}
      {onBack ? <Button title={add ? 'Batal' : 'Semua jadwal hari ini'} variant="secondary" onPress={onBack} disabled={save.isPending} /> : null}
    </>
  );
}

const useStyles = makeStyles((c) => ({
  date: { textAlign: 'center', color: c.textMuted, marginTop: -spacing.md },
  body: { maxHeight: 460 },
  content: { gap: spacing.md },
  label: { color: c.textMuted, fontWeight: '500', paddingHorizontal: 4 },
}));
