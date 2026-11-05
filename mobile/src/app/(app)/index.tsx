import { Ionicons } from '@expo/vector-icons';
import { useQuery } from '@tanstack/react-query';
import { router, type Href } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { TodayCard } from '@/components/TodayCard';
import { Avatar, Card, ErrorBanner, type IconName, LoadingView, Notice, Screen, SectionTitle } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { useRefreshUser } from '@/hooks/useRefreshUser';
import { approvalsApi, attendanceApi, errorMessage, requestsApi } from '@/lib/api';
import { greeting } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';
import { useSession } from '@/lib/session';
import { countPending } from '@/lib/approvals';
import { colors, fontSize, radius, spacing } from '@/theme';

interface MenuItem {
  key: string;
  label: string;
  icon: IconName;
  href: Href;
  badge?: number;
}

export default function HomeScreen() {
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
    enabled: access.canRequestLeave && access.profileKind !== 'freelancer',
  });
  const pending = useQuery({
    queryKey: queryKeys.approvals,
    queryFn: approvalsApi.pending,
    enabled: access.canApprove,
  });

  const refresh = () => {
    refreshUser.mutate();
    void today.refetch();
    void leaves.refetch();
    void pending.refetch();
  };

  const menu: MenuItem[] = [
    { key: 'history', label: 'Riwayat', icon: 'calendar-outline', href: '/history' },
    ...(access.canRequestLeave ? [{ key: 'leave', label: 'Cuti / Izin', icon: 'sunny-outline', href: '/request/leave/new' } as MenuItem] : []),
    ...(access.canRequestOvertime ? [{ key: 'overtime', label: 'Lembur', icon: 'moon-outline', href: '/request/overtime/new' } as MenuItem] : []),
    ...(access.canRequestCorrection ? [{ key: 'correction', label: 'Koreksi', icon: 'create-outline', href: '/request/correction/new' } as MenuItem] : []),
    ...(access.canSeeLeaveCalendar ? [{ key: 'calendar', label: 'Kalender Cuti', icon: 'people-outline', href: '/calendar' } as MenuItem] : []),
    ...(access.canApprove ? [{ key: 'approvals', label: 'Persetujuan', icon: 'checkmark-done-outline', href: '/approvals', badge: countPending(pending.data) } as MenuItem] : []),
    ...(access.canUseAttendance ? [{ key: 'face', label: 'Master Wajah', icon: 'scan-outline', href: '/attendance/master-face' } as MenuItem] : []),
  ];

  const mentor = user?.intern?.mentor ?? null;
  const supervisor = user?.freelancer?.supervisor ?? null;
  const quota = leaves.data?.quota_summary;

  return (
    <Screen edges={['top', 'bottom']} onRefresh={refresh}>
      <View style={styles.hero}>
        <View style={styles.heroText}>
          <Text style={styles.greeting}>{greeting()},</Text>
          <Text style={styles.name} numberOfLines={1}>
            {access.profileName || user?.name}
          </Text>
          <Text style={styles.company} numberOfLines={1}>
            {[access.company?.name, user?.employee?.division?.name ?? user?.intern?.division?.name ?? user?.freelancer?.division?.name]
              .filter(Boolean)
              .join(' · ') || access.roles.join(', ')}
          </Text>
        </View>
        <Pressable onPress={() => router.push('/profile')}>
          <Avatar name={access.profileName || user?.name || ''} uri={access.masterFacePhoto} size={52} />
        </Pressable>
      </View>

      {access.canUseAttendance ? (
        today.isPending ? (
          <Card>
            <LoadingView message="Memuat presensi hari ini…" />
          </Card>
        ) : today.isError ? (
          <ErrorBanner message={errorMessage(today.error)} onRetry={() => today.refetch()} />
        ) : today.data ? (
          <TodayCard today={today.data} access={access} />
        ) : null
      ) : (
        <Notice tone="info" message="Akun ini tidak terhubung ke profil pekerja, jadi presensi dan pengajuan pribadi tidak tersedia. Menu lain tetap bisa dipakai sesuai role." />
      )}

      {quota ? (
        <Card style={styles.quotaCard}>
          <View style={styles.quotaRow}>
            <View>
              <Text style={styles.quotaLabel}>Sisa cuti tahunan</Text>
              <Text style={styles.quotaValue}>
                {quota.remaining_days} <Text style={styles.quotaUnit}>dari {quota.annual_leave_quota} hari</Text>
              </Text>
            </View>
            <Ionicons name="sunny-outline" size={32} color={colors.primary} />
          </View>
        </Card>
      ) : null}

      {mentor || supervisor ? (
        <Card>
          <Text style={styles.cardLabel}>{mentor ? 'Mentor' : 'Supervisor'}</Text>
          <Text style={styles.cardValue}>{(mentor ?? supervisor)?.full_name}</Text>
        </Card>
      ) : null}

      <SectionTitle title="Menu" />
      <View style={styles.grid}>
        {menu.map((item) => (
          <Pressable key={item.key} style={({ pressed }) => [styles.menuItem, pressed && styles.menuPressed]} onPress={() => router.push(item.href)}>
            <View style={styles.menuIcon}>
              <Ionicons name={item.icon} size={24} color={colors.primary} />
              {item.badge ? (
                <View style={styles.menuBadge}>
                  <Text style={styles.menuBadgeText}>{item.badge}</Text>
                </View>
              ) : null}
            </View>
            <Text style={styles.menuLabel} numberOfLines={2}>
              {item.label}
            </Text>
          </Pressable>
        ))}
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  hero: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingTop: spacing.sm },
  heroText: { flex: 1 },
  greeting: { fontSize: fontSize.sm, color: colors.textMuted },
  name: { fontSize: fontSize.xl, fontWeight: '700', color: colors.text },
  company: { fontSize: fontSize.sm, color: colors.textMuted, marginTop: 2 },
  quotaCard: { backgroundColor: colors.primarySoft, borderColor: colors.primarySoft },
  quotaRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  quotaLabel: { fontSize: fontSize.sm, color: colors.primaryDark },
  quotaValue: { fontSize: fontSize.xxl, fontWeight: '700', color: colors.primaryDark },
  quotaUnit: { fontSize: fontSize.sm, fontWeight: '500' },
  cardLabel: { fontSize: fontSize.xs, color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.5 },
  cardValue: { fontSize: fontSize.md, fontWeight: '600', color: colors.text },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md },
  menuItem: {
    width: '30%',
    flexGrow: 1,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    alignItems: 'center',
    gap: spacing.sm,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
  },
  menuPressed: { opacity: 0.8 },
  menuIcon: {
    width: 48,
    height: 48,
    borderRadius: radius.md,
    backgroundColor: colors.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  menuBadge: {
    position: 'absolute',
    top: -6,
    right: -6,
    minWidth: 20,
    height: 20,
    borderRadius: 10,
    backgroundColor: colors.danger,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 5,
  },
  menuBadgeText: { color: colors.textOnPrimary, fontSize: 11, fontWeight: '700' },
  menuLabel: { fontSize: fontSize.sm, color: colors.text, textAlign: 'center', fontWeight: '500' },
});
