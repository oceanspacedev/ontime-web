import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Alert, Pressable, ScrollView, View } from 'react-native';

import { Choice, EmptyState, ErrorBanner, IconButton, LoadingView, Sheet, Text } from '@/components/ui';
import type { ScheduleTarget } from '@/components/ScheduleSheet';
import { errorMessage, teamApi, type TeamWeek } from '@/lib/api';
import { toDateString } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { shiftDate, shiftText, weekCellText, weekStart } from '@/lib/team';
import { fontSize, makeStyles, radius, spacing } from '@/theme';

const OFF = 'off';
const CLEAR = 'clear';
const NO_SECTION = '-';

const DAY_NAMES = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

interface Props {
  /** Any date inside the week to show. */
  date: string;
  onChange: (date: string) => void;
  /** Called with the cell tapped, to edit that worker's day. */
  onEdit: (target: ScheduleTarget) => void;
}

/** The week as the outlets' spreadsheets show it: a line per person, a column per day. Tap a cell to change it. */
export function WeekGrid({ date, onChange, onEdit }: Props) {
  const styles = useStyles();
  const start = weekStart(date);
  const week = useQuery({ queryKey: queryKeys.teamWeek(start), queryFn: () => teamApi.week(start) });
  const today = toDateString(new Date());
  const queryClient = useQueryClient();
  const options = useQuery({ queryKey: queryKeys.teamOptions, queryFn: teamApi.options });
  // Quick fill: pick a shift (or OFF / clear) once, then every tapped cell gets it without a form.
  const [brush, setBrush] = useState<string | null>(null);
  const [brushSection, setBrushSection] = useState<string>(NO_SECTION);
  const [picking, setPicking] = useState(false);
  const companies = options.data?.companies ?? [];
  // Shifts grouped by brand, so a long list stays readable and lives in a sheet instead of pushing the grid away.
  const brushGroups = Object.values(
    companies
      .flatMap((company) => company.shifts.map((shift) => ({ company, shift })))
      .reduce<Record<string, { title: string; shifts: { value: string; label: string }[] }>>((groups, { company, shift }) => {
        const title = `${companies.length > 1 ? `${company.name} · ` : ''}${shift.brand ?? 'Semua brand'}`;
        (groups[title] ??= { title, shifts: [] }).shifts.push({ value: String(shift.id), label: shiftText(shift) });
        return groups;
      }, {}),
  );
  const brushLabel = brush === OFF ? 'Libur' : brush === CLEAR ? 'Kosongkan' : (brushGroups.flatMap((group) => group.shifts).find((shift) => shift.value === brush)?.label ?? '');
  const choose = (value: string) => {
    setBrush(value);
    setPicking(false);
  };
  const sections = [...new Set(companies.flatMap((company) => company.sections))];

  const paint = useMutation({
    mutationFn: (input: { row: TeamWeek['workers'][number]; day: string }) => {
      const company = companies.find((item) => item.id === input.row.worker.company_id);
      const base = { worker_type: input.row.worker.type, worker_id: input.row.worker.id, date: input.day };
      if (brush === OFF || brush === CLEAR) {
        return teamApi.saveSchedule({ ...base, mode: brush });
      }
      const shift = company?.shifts.find((item) => String(item.id) === brush);
      if (!shift) {
        return Promise.reject(new Error('Shift ini bukan milik badan usaha pekerja tersebut.'));
      }
      // The outlet of the shift's brand, when it has exactly one.
      const brandOutlets = company?.outlets.filter((outlet) => outlet.brand === shift.brand) ?? [];
      return teamApi.saveSchedule({
        ...base,
        mode: 'shift',
        shift_id: shift.id,
        company_location_id: brandOutlets.length === 1 ? brandOutlets[0].id : null,
        section: brushSection === NO_SECTION ? null : brushSection,
      });
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.team }),
    onError: (error) => Alert.alert('Tidak tersimpan', errorMessage(error)),
  });

  const edit = (row: TeamWeek['workers'][number], day: string) => {
    if (brush) {
      paint.mutate({ row, day });
      return;
    }
    const cell = row.days[day];
    onEdit({
      worker: { type: row.worker.type, id: row.worker.id, name: row.worker.name, company_id: row.worker.company_id },
      date: day,
      current: cell ? { shift_id: cell.shift?.id ?? null, location_id: cell.location_id, section: cell.section, is_day_off: cell.is_day_off } : null,
    });
  };

  return (
    <View style={styles.wrap}>
      <View style={styles.nav}>
        <IconButton icon="chevron-back" onPress={() => onChange(shiftDate(start, -7))} accessibilityLabel="Minggu sebelumnya" />
        <Pressable style={styles.pill} onPress={() => onChange(today)} accessibilityRole="button" accessibilityLabel="Kembali ke minggu ini">
          <Text style={styles.pillText} numberOfLines={1}>
            {start.slice(8)}/{start.slice(5, 7)} – {shiftDate(start, 6).slice(8)}/{shiftDate(start, 6).slice(5, 7)}
          </Text>
        </Pressable>
        <IconButton icon="chevron-forward" onPress={() => onChange(shiftDate(start, 7))} accessibilityLabel="Minggu berikutnya" />
      </View>

      <View style={styles.brush}>
        {brush ? (
          <View style={styles.brushBar}>
            <Text style={styles.brushTitle} numberOfLines={1}>
              Isi cepat: {brushLabel} · ketuk sel
            </Text>
            <Pressable onPress={() => setPicking(true)} accessibilityRole="button" accessibilityLabel="Ganti isian">
              <Text style={styles.brushAction}>Ganti</Text>
            </Pressable>
            <Pressable onPress={() => setBrush(null)} accessibilityRole="button" accessibilityLabel="Selesai mengisi cepat">
              <Text style={styles.brushAction}>Selesai</Text>
            </Pressable>
          </View>
        ) : (
          <Pressable style={styles.brushStart} onPress={() => setPicking(true)} accessibilityRole="button" accessibilityLabel="Isi cepat">
            <Text style={styles.brushAction}>Isi cepat</Text>
            <Text style={styles.brushHint}>pilih shift sekali, lalu ketuk sel</Text>
          </Pressable>
        )}
      </View>

      <Sheet visible={picking} onClose={() => setPicking(false)} title="Isi cepat">
        <ScrollView style={styles.picker} contentContainerStyle={styles.pickerContent}>
          <Choice
            options={[
              { value: OFF, label: 'Libur' },
              { value: CLEAR, label: 'Kosongkan' },
            ]}
            value={brush === OFF || brush === CLEAR ? brush : null}
            onChange={choose}
          />
          {brushGroups.map((group) => (
            <View key={group.title} style={styles.group}>
              <Text style={styles.groupTitle}>{group.title}</Text>
              <Choice options={group.shifts} value={brush} onChange={choose} />
            </View>
          ))}
          {sections.length > 0 ? (
            <View style={styles.group}>
              <Text style={styles.groupTitle}>Section</Text>
              <Choice options={[{ value: NO_SECTION, label: 'Tanpa section' }, ...sections.map((section) => ({ value: section, label: section }))]} value={brushSection} onChange={setBrushSection} />
            </View>
          ) : null}
        </ScrollView>
      </Sheet>

      {week.isPending ? (
        <LoadingView />
      ) : week.isError ? (
        <ErrorBanner message={errorMessage(week.error)} onRetry={() => week.refetch()} />
      ) : week.data.workers.length === 0 ? (
        <EmptyState icon="calendar-clear-outline" title="Belum ada jadwal minggu ini" message="Salin jadwal minggu lalu, atau tekan + untuk menjadwalkan." />
      ) : (
        <ScrollView horizontal showsHorizontalScrollIndicator={false}>
          <View>
            <View style={styles.line}>
              <View style={styles.nameCell} />
              {week.data.days.map((day, index) => (
                <View key={day} style={styles.cell}>
                  <Text style={[styles.head, day === today && styles.today]}>{DAY_NAMES[index]}</Text>
                  <Text style={[styles.headDate, day === today && styles.today]}>{day.slice(8)}</Text>
                </View>
              ))}
            </View>
            {week.data.workers.map((row) => (
              <View key={`${row.worker.type}:${row.worker.id}`} style={styles.line}>
                <View style={styles.nameCell}>
                  <Text style={styles.name} numberOfLines={2}>
                    {row.worker.name}
                  </Text>
                </View>
                {week.data.days.map((day) => {
                  const cell = row.days[day];
                  return (
                    <Pressable key={day} style={[styles.cell, styles.box, cell?.is_day_off && styles.off]} onPress={() => edit(row, day)} accessibilityRole="button" accessibilityLabel={`${row.worker.name}, ${day}`}>
                      <Text style={[styles.code, !cell && styles.empty]}>{weekCellText(cell)}</Text>
                      {cell?.borrowed_from ? <Text style={styles.borrowed}>pinjam</Text> : null}
                    </Pressable>
                  );
                })}
              </View>
            ))}
          </View>
        </ScrollView>
      )}
    </View>
  );
}

const useStyles = makeStyles((c) => ({
  wrap: { gap: spacing.md },
  brush: { gap: spacing.sm },
  brushBar: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingHorizontal: 4 },
  brushStart: { flexDirection: 'row', alignItems: 'baseline', gap: spacing.sm, paddingHorizontal: 4 },
  brushTitle: { flex: 1, fontSize: fontSize.sm, color: c.text, fontWeight: '600' },
  brushHint: { fontSize: fontSize.sm, color: c.textMuted },
  brushAction: { fontSize: fontSize.sm, fontWeight: '600', color: c.primary },
  picker: { maxHeight: 520 },
  pickerContent: { gap: spacing.lg },
  group: { gap: spacing.sm },
  groupTitle: { fontSize: fontSize.sm, fontWeight: '600', color: c.textMuted, paddingHorizontal: 4 },
  nav: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  pill: { flex: 1, minHeight: 46, borderRadius: radius.pill, backgroundColor: c.fill, alignItems: 'center', justifyContent: 'center', paddingHorizontal: spacing.md },
  pillText: { fontSize: fontSize.md, fontWeight: '600', color: c.text },
  line: { flexDirection: 'row', alignItems: 'center', marginBottom: 4 },
  nameCell: { width: 110, paddingRight: spacing.sm },
  name: { fontSize: fontSize.sm, color: c.text },
  cell: { width: 52, alignItems: 'center', marginRight: 4 },
  box: { minHeight: 40, justifyContent: 'center', borderRadius: radius.md, backgroundColor: c.fill },
  off: { opacity: 0.6 },
  head: { fontSize: fontSize.xs, color: c.textMuted },
  headDate: { fontSize: fontSize.sm, fontWeight: '600', color: c.text },
  today: { color: c.primary },
  code: { fontSize: fontSize.sm, fontWeight: '600', color: c.text },
  borrowed: { fontSize: 9, color: c.textMuted },
  empty: { color: c.textMuted, fontWeight: '400' },
}));
