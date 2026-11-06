import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { ScrollView } from 'react-native';

import { DateTimeField } from '@/components/DateTimeField';
import { ScheduleSheet, type ScheduleTarget } from '@/components/ScheduleSheet';
import { Badge, Choice, EmptyState, ErrorBanner, Group, LoadingView, Row, Screen } from '@/components/ui';
import { errorMessage, teamApi, type RosterEntry, type TeamRoster } from '@/lib/api';
import { toDateString } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { rosterBadge, rosterLine, shiftDate } from '@/lib/team';
import { makeStyles, spacing } from '@/theme';

const TITLE = 'Tim';

function summary(roster: TeamRoster): string {
  const people = roster.outlets.reduce((sum, outlet) => sum + outlet.counts.scheduled, 0);
  const present = roster.outlets.reduce((sum, outlet) => sum + outlet.counts.hadir + outlet.counts.terlambat + outlet.counts.pulang, 0);
  if (people === 0) {
    return 'Belum ada jadwal';
  }
  return roster.date <= toDateString(new Date()) ? `${present} dari ${people} orang sudah absen` : `${people} orang terjadwal`;
}

export default function TeamScreen() {
  const styles = useStyles();
  const today = toDateString(new Date());
  const [date, setDate] = useState(today);
  const [target, setTarget] = useState<ScheduleTarget | null>(null);
  const roster = useQuery({ queryKey: queryKeys.teamRoster(date), queryFn: () => teamApi.roster(date) });

  const quickDays = [
    { value: shiftDate(today, -1), label: 'Kemarin' },
    { value: today, label: 'Hari ini' },
    { value: shiftDate(today, 1), label: 'Besok' },
  ];

  const edit = (entry: RosterEntry) =>
    setTarget({
      worker: { type: entry.worker.type, id: entry.worker.id, name: entry.worker.name, company_id: entry.worker.company_id },
      date,
      current: { shift_id: entry.shift?.id ?? null, location_id: entry.location_id, section: entry.section, is_day_off: entry.state === 'libur' },
    });

  return (
    <Screen title={TITLE} subtitle={roster.data ? summary(roster.data) : undefined} onRefresh={() => roster.refetch()}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.days} contentContainerStyle={styles.daysContent}>
        <Choice options={quickDays} value={quickDays.some((day) => day.value === date) ? date : null} onChange={setDate} />
      </ScrollView>
      <DateTimeField label="Tanggal" mode="date" value={date} onChange={setDate} />

      <Group>
        <Row icon="calendar-outline" title="Jadwalkan pekerja" onPress={() => router.push({ pathname: '/team/assign', params: { date } })} />
        <Row icon="person-add-outline" title="Tambah freelancer" onPress={() => router.push('/team/freelancer/new')} />
        <Row icon="people-outline" title="Daftar freelancer" onPress={() => router.push('/team/freelancers')} />
      </Group>

      {roster.isPending ? (
        <LoadingView />
      ) : roster.isError ? (
        <ErrorBanner message={errorMessage(roster.error)} onRetry={() => roster.refetch()} />
      ) : roster.data.outlets.length === 0 ? (
        <EmptyState icon="calendar-clear-outline" title="Belum ada jadwal" message="Jadwalkan pekerja untuk tanggal ini." />
      ) : (
        roster.data.outlets.map((outlet) => (
          <Group key={outlet.location?.id ?? 'none'} title={`${outlet.location?.name ?? 'Tanpa outlet'} · ${outlet.counts.scheduled}`}>
            {outlet.entries.map((entry) => {
              const badge = rosterBadge(entry, date, today);
              return (
                <Row
                  key={entry.schedule_id}
                  title={entry.worker.name}
                  subtitle={entry.check_in ? `${rosterLine(entry)} · masuk ${entry.check_in}` : rosterLine(entry)}
                  right={badge ? <Badge label={badge.label} tone={badge.tone} /> : undefined}
                  onPress={() => edit(entry)}
                />
              );
            })}
          </Group>
        ))
      )}

      <ScheduleSheet target={target} onClose={() => setTarget(null)} />
    </Screen>
  );
}

const useStyles = makeStyles(() => ({
  days: { marginHorizontal: -spacing.lg, flexGrow: 0 },
  daysContent: { paddingHorizontal: spacing.lg },
}));
