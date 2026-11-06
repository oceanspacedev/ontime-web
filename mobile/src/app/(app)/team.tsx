import { useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';

import { DaySwitcher } from '@/components/DaySwitcher';
import { ScheduleSheet, type ScheduleTarget } from '@/components/ScheduleSheet';
import { TeamFreelancerList } from '@/components/TeamFreelancerList';
import { Badge, EmptyState, ErrorBanner, Group, IconButton, LoadingView, Row, Screen, Segmented, Sheet } from '@/components/ui';
import { errorMessage, teamApi, type RosterEntry, type RosterOutlet, type TeamFreelancer } from '@/lib/api';
import { toDateString } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { rosterBadge, rosterLine, rosterSummary } from '@/lib/team';

type TeamView = 'schedule' | 'freelancers';

const VIEWS: { value: TeamView; label: string }[] = [
  { value: 'schedule', label: 'Jadwal' },
  { value: 'freelancers', label: 'Freelancer' },
];

/** Days off carry no outlet, so a group of only days off is titled "Libur". */
function outletTitle(outlet: RosterOutlet): string {
  if (outlet.location) {
    return outlet.location.name;
  }
  return outlet.entries.every((entry) => entry.state === 'libur') ? 'Libur' : 'Tanpa outlet';
}

export default function TeamScreen() {
  const queryClient = useQueryClient();
  const today = toDateString(new Date());
  const [view, setView] = useState<TeamView>('schedule');
  const [date, setDate] = useState(today);
  const [target, setTarget] = useState<ScheduleTarget | null>(null);
  const [adding, setAdding] = useState(false);
  const roster = useQuery({ queryKey: queryKeys.teamRoster(date), queryFn: () => teamApi.roster(date) });
  const options = useQuery({ queryKey: queryKeys.teamOptions, queryFn: teamApi.options });

  const editEntry = (entry: RosterEntry) =>
    setTarget({
      worker: { type: entry.worker.type, id: entry.worker.id, name: entry.worker.name, company_id: entry.worker.company_id },
      date,
      current: { shift_id: entry.shift?.id ?? null, location_id: entry.location_id, section: entry.section, is_day_off: entry.state === 'libur' },
    });

  /** Opens the freelancer's day: their schedule when they have one, otherwise their brand's outlet. */
  const scheduleFreelancer = (freelancer: TeamFreelancer) => {
    const entry = roster.data?.outlets.flatMap((outlet) => outlet.entries).find((item) => item.worker.type === 'freelancer' && item.worker.id === freelancer.id);
    if (entry) {
      editEntry(entry);
      return;
    }
    const brandOutlets = options.data?.companies.find((company) => company.id === freelancer.company_id)?.outlets.filter((outlet) => outlet.brand === freelancer.brand) ?? [];
    setTarget({
      worker: { type: 'freelancer', id: freelancer.id, name: freelancer.name, company_id: freelancer.company_id },
      date,
      defaultLocationId: brandOutlets.length === 1 ? brandOutlets[0].id : null,
    });
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

      {view === 'freelancers' ? (
        <TeamFreelancerList onSchedule={scheduleFreelancer} />
      ) : (
        <>
          <DaySwitcher value={date} onChange={setDate} />
          {roster.isPending ? (
            <LoadingView />
          ) : roster.isError ? (
            <ErrorBanner message={errorMessage(roster.error)} onRetry={() => roster.refetch()} />
          ) : roster.data.outlets.length === 0 ? (
            <EmptyState icon="calendar-clear-outline" title="Belum ada jadwal" message="Tekan + untuk menjadwalkan pekerja." />
          ) : (
            roster.data.outlets.map((outlet) => (
              <Group key={outlet.location?.id ?? 'none'} title={`${outletTitle(outlet)} · ${outlet.entries.length}`}>
                {outlet.entries.map((entry) => {
                  const badge = rosterBadge(entry, date, today);
                  return (
                    <Row
                      key={entry.schedule_id}
                      title={entry.worker.name}
                      subtitle={entry.state === 'libur' ? undefined : entry.check_in ? `${rosterLine(entry)} · masuk ${entry.check_in}` : rosterLine(entry)}
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
          <Row icon="person-add-outline" title="Freelancer baru" subtitle="Nama, nomor HP, dan outlet" onPress={() => go('/team/freelancer/new')} />
        </Group>
      </Sheet>
    </Screen>
  );
}
