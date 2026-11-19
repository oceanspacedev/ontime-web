import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useRef, useState } from 'react';
import { Alert } from 'react-native';

import { DaySwitcher } from '@/components/DaySwitcher';
import { ScheduleSheet, type ScheduleSlot, type ScheduleTarget } from '@/components/ScheduleSheet';
import { TeamFreelancerList } from '@/components/TeamFreelancerList';
import { TeamRecapView } from '@/components/TeamRecapView';
import { WeekGrid } from '@/components/WeekGrid';
import { Badge, EmptyState, ErrorBanner, Group, IconButton, LoadingView, Row, Screen, Segmented, Sheet } from '@/components/ui';
import { useCurrentDate } from '@/hooks/useCurrentDate';
import { errorMessage, teamApi, type RosterEntry, type TeamFreelancer } from '@/lib/api';
import { toDateString } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { BORROWED_READ_ONLY, canEditSchedule, entrySupportsMultipleSchedules, followToday, outletTitle, rosterBadge, rosterEntryKey, rosterLine, rosterSubtitle, rosterSummary, shiftDate } from '@/lib/team';

type TeamView = 'schedule' | 'week' | 'freelancers' | 'recap';

const VIEWS: { value: TeamView; label: string }[] = [
  { value: 'schedule', label: 'Hari' },
  { value: 'week', label: 'Minggu' },
  { value: 'freelancers', label: 'Freelancer' },
  { value: 'recap', label: 'Rekap' },
];

export default function TeamScreen() {
  const queryClient = useQueryClient();
  const [view, setView] = useState<TeamView>('schedule');
  const [date, setDate] = useState(() => toDateString(new Date()));
  const previousToday = useRef(date);
  // The day view follows the calendar: left on today (past midnight or days in the background), it moves on to the new today.
  const today = useCurrentDate((next) => {
    const previous = previousToday.current;
    previousToday.current = next;
    setDate((shown) => followToday(shown, previous, next));
  });
  const [target, setTarget] = useState<ScheduleTarget | null>(null);
  const [adding, setAdding] = useState(false);
  const roster = useQuery({ queryKey: queryKeys.teamRoster(date), queryFn: () => teamApi.roster(date) });
  const options = useQuery({ queryKey: queryKeys.teamOptions, queryFn: teamApi.options });

  /** A worker lent in from another brand is only shown; tapping explains who plans them. */
  const editEntry = (entry: RosterEntry) => (canEditSchedule(entry) ? openEntry(entry) : Alert.alert(entry.worker.name, BORROWED_READ_ONLY));

  /** The worker's scheduled entries of the shown day (several on a day with more than one schedule). */
  const entriesOf = (type: RosterEntry['worker']['type'], id: number): RosterEntry[] =>
    roster.data?.outlets.flatMap((outlet) => outlet.entries).filter((item) => item.worker.type === type && item.worker.id === id && item.schedule_id !== null) ?? [];

  const slotOf = (entry: RosterEntry): ScheduleSlot => ({
    schedule_id: entry.schedule_id,
    shift_id: entry.shift?.id ?? null,
    location_id: entry.location_id,
    section: entry.section,
    is_day_off: entry.state === 'libur',
    locked: entry.locked,
    label: [rosterLine(entry), entry.outlet_name].filter(Boolean).join(' · '),
  });

  /** Edits exactly the tapped schedule; the worker's other schedules of the day stay one tap away. */
  const openEntry = (entry: RosterEntry, siblings: RosterEntry[] = entriesOf(entry.worker.type, entry.worker.id)) =>
    setTarget({
      worker: { type: entry.worker.type, id: entry.worker.id, name: entry.worker.name, company_id: entry.worker.company_id },
      date,
      current: slotOf(entry),
      // Someone listed without a schedule (checked in off the roster) has no rows to list.
      schedules: entry.schedule_id !== null && siblings.length > 0 ? siblings.map(slotOf) : undefined,
      // A newer server says how many schedules the worker has that day; an unscheduled entry has none to add to.
      supportsMulti: entrySupportsMultipleSchedules(entry),
    });

  /** Opens the freelancer's day: their schedules when they have any, otherwise their brand's outlet. */
  const scheduleFreelancer = (freelancer: TeamFreelancer) => {
    const entries = entriesOf('freelancer', freelancer.id);
    if (entries.length === 1) {
      editEntry(entries[0]);
      return;
    }
    if (entries.length > 1) {
      if (!entries.every(canEditSchedule)) {
        Alert.alert(freelancer.name, BORROWED_READ_ONLY);
        return;
      }
      // Several schedules: the sheet lists them first.
      setTarget({
        worker: { type: 'freelancer', id: freelancer.id, name: freelancer.name, company_id: freelancer.company_id },
        date,
        schedules: entries.map(slotOf),
        supportsMulti: true,
      });
      return;
    }
    const brandOutlets = options.data?.companies.find((company) => company.id === freelancer.company_id)?.outlets.filter((outlet) => outlet.brand === freelancer.brand) ?? [];
    setTarget({
      worker: { type: 'freelancer', id: freelancer.id, name: freelancer.name, company_id: freelancer.company_id },
      date,
      defaultLocationId: brandOutlets.length === 1 ? brandOutlets[0].id : null,
    });
  };

  const copyWeek = useMutation({
    mutationFn: () => teamApi.copySchedules(shiftDate(date, -7), date),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.team });
      Alert.alert('Jadwal minggu lalu', response.message);
    },
    onError: (error) => Alert.alert('Gagal menyalin', errorMessage(error)),
  });

  const confirmCopyWeek = () => {
    setAdding(false);
    Alert.alert('Salin jadwal minggu lalu?', 'Jadwal minggu sebelum tanggal ini disalin ke minggu yang dipilih. Hari yang sudah terisi tidak ditimpa.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Salin', onPress: () => copyWeek.mutate() },
    ]);
  };

  const go = (path: '/team/assign' | '/team/freelancer/new') => {
    setAdding(false);
    router.push(path === '/team/assign' ? { pathname: path, params: { date } } : path);
  };

  return (
    <Screen
      title="Tim"
      subtitle={roster.data ? rosterSummary(roster.data) : undefined}
      headerRight={<IconButton icon="add" tone="primary" onPress={() => setAdding(true)} accessibilityLabel="Tambah" />}
      onRefresh={() => queryClient.invalidateQueries({ queryKey: queryKeys.team })}>
      <Segmented options={VIEWS} value={view} onChange={setView} />

      {view === 'recap' ? (
        <TeamRecapView />
      ) : view === 'week' ? (
        <WeekGrid date={date} onChange={setDate} onEdit={setTarget} />
      ) : view === 'freelancers' ? (
        <TeamFreelancerList onSchedule={scheduleFreelancer} />
      ) : (
        <>
          <DaySwitcher value={date} onChange={setDate} />
          {roster.isPending ? (
            <LoadingView />
          ) : roster.isError ? (
            <ErrorBanner error={roster.error} onRetry={() => roster.refetch()} />
          ) : roster.data.outlets.length === 0 ? (
            <EmptyState icon="calendar-clear-outline" title="Belum ada jadwal" message="Tekan + untuk menjadwalkan pekerja." />
          ) : (
            roster.data.outlets.map((outlet) => (
              <Group key={outlet.kind ?? outlet.location?.id ?? 'none'} title={`${outletTitle(outlet)} · ${outlet.entries.length}`}>
                {outlet.entries.map((entry) => {
                  const badge = rosterBadge(entry, date, today);
                  return (
                    <Row
                      key={rosterEntryKey(entry)}
                      title={entry.worker.name}
                      subtitle={rosterSubtitle(entry)}
                      right={badge ? <Badge label={badge.label} tone={badge.tone} /> : undefined}
                      onPress={() => editEntry(entry)}
                    />
                  );
                })}
              </Group>
            ))
          )}
        </>
      )}

      <ScheduleSheet target={target} onClose={() => setTarget(null)} />

      <Sheet visible={adding} onClose={() => setAdding(false)} title="Tambah">
        <Group>
          <Row icon="calendar-outline" title="Jadwalkan pekerja" subtitle="Atur shift seseorang untuk hari yang dipilih" onPress={() => go('/team/assign')} />
          <Row icon="copy-outline" title="Salin jadwal minggu lalu" subtitle="Mulai dari jadwal minggu sebelumnya" onPress={confirmCopyWeek} />
          <Row icon="person-add-outline" title="Freelancer baru" subtitle="Nama, nomor HP, dan outlet" onPress={() => go('/team/freelancer/new')} />
        </Group>
      </Sheet>
    </Screen>
  );
}
