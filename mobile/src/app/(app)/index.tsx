import { Ionicons } from '@expo/vector-icons';
import { useQuery } from '@tanstack/react-query';
import { router, type Href } from 'expo-router';
import { Linking, Pressable, View } from 'react-native';

import { TODAY_GROUP_TITLE, TodayCard } from '@/components/TodayCard';
import { Avatar, ErrorBanner, Group, IconButton, type IconName, LoadingView, Notice, ProgressBar, QuickAction, Row, Screen, SectionTitle, Text } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { useRefreshUser } from '@/hooks/useRefreshUser';
import { approvalsApi, attendanceApi, errorMessage, requestsApi, teamApi } from '@/lib/api';
import { countPending } from '@/lib/approvals';
import { greeting, toDateString } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { useSession } from '@/lib/session';
import { rosterSummary, teamAttention } from '@/lib/team';
import { fontSize, makeStyles, spacing, useTheme } from '@/theme';

interface MenuItem {
  key: string;
  label: string;
  icon: IconName;
  /** A screen in the app, or an external page opened in the browser. */
  href: Href | { external: string };
  badge?: number;
}

function openMenuItem(item: MenuItem): void {
  if (typeof item.href === 'object' && 'external' in item.href) {
    void Linking.openURL(item.href.external);
    return;
  }
  router.push(item.href);
}

/** Quick action tiles per row on the home screen. */
const MENU_COLUMNS = 4;

/** Splits the menu into rows of equal length, padding the last row with empty slots. */
function toRows(items: MenuItem[]): (MenuItem | null)[][] {
  const rows: (MenuItem | null)[][] = [];
  for (let index = 0; index < items.length; index += MENU_COLUMNS) {
    const row: (MenuItem | null)[] = items.slice(index, index + MENU_COLUMNS);
    while (row.length < MENU_COLUMNS) {
      row.push(null);
    }
    rows.push(row);
  }
  return rows;
}

export default function HomeScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const user = useSession((state) => state.user);
  const access = useAccess();
  const refreshUser = useRefreshUser();

  const today = useQuery({
    queryKey: queryKeys.today,
    queryFn: attendanceApi.today,
    enabled: access.canUseAttendance,
  });
  const leaves = useQuery({
    queryKey: queryKeys.leaves,
    queryFn: () => requestsApi.leave.list(1),
    enabled: access.canRequestLeave && access.hasAnnualLeave,
  });
  const pending = useQuery({
    queryKey: queryKeys.approvals,
    queryFn: approvalsApi.pending,
    enabled: access.canApprove,
  });
  const todayDate = toDateString(new Date());
  const team = useQuery({
    queryKey: queryKeys.teamRoster(todayDate),
    queryFn: () => teamApi.roster(todayDate),
    enabled: access.canManageTeam,
  });

  const refresh = () => {
    refreshUser.mutate();
    void today.refetch();
    void leaves.refetch();
    void pending.refetch();
    void team.refetch();
  };

  const pendingCount = countPending(pending.data);

  // Only what the bottom bar does not already offer: Riwayat and Persetujuan have their own tabs.
  const menu: MenuItem[] = [
    ...(access.canRequestLeave ? [{ key: 'leave', label: 'Cuti / Izin', icon: 'sunny-outline', href: '/request/leave/new' } as MenuItem] : []),
    ...(access.canRequestOvertime ? [{ key: 'overtime', label: 'Lembur', icon: 'moon-outline', href: '/request/overtime/new' } as MenuItem] : []),
    ...(access.canRequestCorrection ? [{ key: 'correction', label: 'Koreksi', icon: 'create-outline', href: '/request/correction/new' } as MenuItem] : []),
    ...(access.canSeeLeaveCalendar ? [{ key: 'calendar', label: 'Kalender Cuti', icon: 'people-outline', href: '/calendar' } as MenuItem] : []),
    // Team runners have Tim in the bar instead of Riwayat, so Riwayat stays reachable here.
    ...(access.canManageTeam && access.canUseAttendance ? [{ key: 'history', label: 'Riwayat', icon: 'calendar-outline', href: '/history' } as MenuItem] : []),
    ...(access.wasteUrl ? [{ key: 'waste', label: 'Waste', icon: 'trash-outline', href: { external: access.wasteUrl } } as MenuItem] : []),
  ];

  const displayName = access.profileName || user?.name;
  const mentor = user?.intern?.mentor ?? null;
  const supervisor = user?.freelancer?.supervisor ?? null;
  const guide = mentor ?? supervisor;
  const quota = access.hasAnnualLeave && leaves.data?.quota_summary.eligible !== false ? leaves.data?.quota_summary : undefined;
  const quotaRatio = quota && quota.annual_leave_quota > 0 ? quota.remaining_days / quota.annual_leave_quota : 0;

  return (
    <Screen edges={['top']} onRefresh={refresh}>
      <View style={styles.hero}>
        <Pressable onPress={() => router.push('/profile')} accessibilityRole="button" accessibilityLabel="Profil">
          <Avatar name={displayName || ''} uri={access.masterFacePhoto} size={44} />
        </Pressable>
        <View style={styles.heroText}>
          <Text style={styles.greeting} numberOfLines={1}>
            {greeting()},
          </Text>
          <Text style={styles.name} numberOfLines={1}>
            {displayName}
          </Text>
          <Text style={styles.company} numberOfLines={1}>
            {[access.company?.name, user?.employee?.division?.name ?? user?.intern?.division?.name ?? user?.freelancer?.division?.name]
              .filter(Boolean)
              .join(' · ') || access.roles.join(', ')}
          </Text>
        </View>
        {access.canApprove && pendingCount > 0 ? (
          <IconButton icon="notifications-outline" onPress={() => router.push('/approvals')} badge={pendingCount} accessibilityLabel="Persetujuan" />
        ) : null}
      </View>

      {access.canUseAttendance ? (
        today.isPending ? (
          <Group title={TODAY_GROUP_TITLE} padded>
            <LoadingView message="Memuat presensi hari ini…" />
          </Group>
        ) : today.isError ? (
          <ErrorBanner message={errorMessage(today.error)} onRetry={() => today.refetch()} />
        ) : today.data ? (
          <TodayCard today={today.data} access={access} />
        ) : null
      ) : (
        <Notice tone="info" message="Akun ini tidak terhubung ke profil pekerja, jadi presensi dan pengajuan pribadi tidak tersedia. Menu lain tetap bisa dipakai sesuai role." />
      )}

      {access.canManageTeam && team.data ? (
        <Group title="Tim hari ini">
          <Row
            icon="people-outline"
            title={rosterSummary(team.data)}
            subtitle={teamAttention(team.data)}
            onPress={() => router.push('/team')}
          />
        </Group>
      ) : null}

      <View style={styles.menu}>
        <SectionTitle title="Menu" />
        {toRows(menu).map((row, rowIndex) => (
          <View key={rowIndex} style={styles.menuRow}>
            {row.map((item, slot) =>
              item ? (
                <QuickAction key={item.key} icon={item.icon} label={item.label} badge={item.badge} onPress={() => openMenuItem(item)} />
              ) : (
                <View key={`empty-${slot}`} style={styles.menuSpacer} />
              ),
            )}
          </View>
        ))}
      </View>

      {quota ? (
        <Group title="Cuti Tahunan" padded>
          <View style={styles.quotaRow}>
            <View style={styles.quotaText}>
              <Text style={styles.quotaLabel}>Sisa cuti tahunan</Text>
              <Text style={styles.quotaValue}>
                {quota.remaining_days} <Text style={styles.quotaUnit}>dari {quota.annual_leave_quota} hari</Text>
              </Text>
            </View>
            <View style={styles.quotaIcon}>
              <Ionicons name="sunny-outline" size={24} color={colors.primary} />
            </View>
          </View>
          <ProgressBar
            progress={quotaRatio}
            height={6}
            accessibilityValue={{ min: 0, max: quota.annual_leave_quota, now: quota.remaining_days }}
          />
        </Group>
      ) : null}

      {guide ? (
        <Group title={mentor ? 'Mentor' : 'Supervisor'}>
          <Row title={guide.full_name} left={<Avatar name={guide.full_name} size={40} />} />
        </Group>
      ) : null}
    </Screen>
  );
}

const useStyles = makeStyles((c) => ({
  hero: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingTop: spacing.sm, paddingHorizontal: spacing.xs },
  heroText: { flex: 1 },
  greeting: { fontSize: fontSize.sm, color: c.textMuted },
  name: { fontSize: fontSize.xl, fontWeight: '700', color: c.text, letterSpacing: -0.3 },
  company: { fontSize: fontSize.xs, color: c.textMuted, marginTop: 2 },
  menu: { gap: spacing.md },
  menuRow: { flexDirection: 'row', gap: spacing.sm },
  menuSpacer: { flex: 1 },
  quotaRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  quotaText: { flex: 1, gap: 2 },
  quotaLabel: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500' },
  quotaValue: { fontSize: 34, fontWeight: '700', color: c.text, letterSpacing: -0.6, fontVariant: ['tabular-nums'] },
  quotaUnit: { fontSize: fontSize.md, fontWeight: '500', color: c.textMuted, letterSpacing: 0 },
  quotaIcon: {
    width: 46,
    height: 46,
    borderRadius: 23,
    backgroundColor: c.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
}));
