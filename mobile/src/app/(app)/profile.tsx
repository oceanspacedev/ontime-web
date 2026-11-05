import { Ionicons } from '@expo/vector-icons';
import { useMutation } from '@tanstack/react-query';
import { router } from 'expo-router';
import { Alert, View } from 'react-native';

import { Avatar, Badge, Button, Group, InfoRow, Pill, Row, Screen, Text } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { useRefreshUser } from '@/hooks/useRefreshUser';
import { authApi } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useSession } from '@/lib/session';
import { type AppearancePreference, fontSize, makeStyles, spacing, useAppearance, useTheme } from '@/theme';

const APPEARANCE_LABELS: Record<AppearancePreference, string> = {
  system: 'Ikuti sistem',
  light: 'Terang',
  dark: 'Gelap',
};

export default function ProfileScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const user = useSession((state) => state.user);
  const baseUrl = useSession((state) => state.baseUrl);
  const signOut = useSession((state) => state.signOut);
  const appearance = useAppearance((state) => state.preference);
  const access = useAccess();
  const refreshUser = useRefreshUser();

  const logout = useMutation({
    mutationFn: async () => {
      try {
        await authApi.logout();
      } catch {
        // The token is dropped locally either way.
      }
      await signOut();
    },
  });

  const confirmLogout = () => {
    Alert.alert('Keluar dari akun?', 'Anda perlu masuk lagi untuk memakai aplikasi.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Keluar', style: 'destructive', onPress: () => logout.mutate() },
    ]);
  };

  const employee = user?.employee;
  const intern = user?.intern;
  const freelancer = user?.freelancer;
  const division = employee?.division ?? intern?.division ?? freelancer?.division;
  const policy = access.company?.policy;
  const displayName = access.profileName || user?.name || '';
  const serverHost = baseUrl.replace(/^https?:\/\//, '').replace(/\/api\/v1$/, '');

  const yesNo = (value: boolean) =>
    value ? <Badge label="Ya" tone="success" dot style={styles.badge} /> : <Badge label="Tidak" tone="gray" dot style={styles.badge} />;

  return (
    <Screen title="Profil" onRefresh={() => refreshUser.mutate()}>
      <Group title="Profil Anda">
        <Row
          left={<Avatar name={displayName} uri={access.masterFacePhoto} size={52} />}
          title={displayName}
          subtitle={user?.email}
          right={access.roles.length > 0 ? <Pill label={access.roles.join(', ')} style={styles.roles} /> : null}
        />
      </Group>

      {employee || intern || freelancer ? (
        <Group title="Data pekerja">
          {employee?.nip ? <InfoRow label="NIP" value={employee.nip} /> : null}
          <InfoRow label="Badan usaha" value={access.company?.name ?? '-'} />
          <InfoRow label="Divisi" value={division?.name ?? '-'} />
          {employee?.job_title ? <InfoRow label="Posisi" value={employee.job_title.name} /> : null}
          {employee?.job_level ? <InfoRow label="Tingkat" value={employee.job_level.name} /> : null}
          {intern ? <InfoRow label="Institusi" value={intern.institution} /> : null}
          {intern?.mentor ? <InfoRow label="Mentor" value={intern.mentor.full_name} /> : null}
          {freelancer ? <InfoRow label="Instansi" value={freelancer.institution} /> : null}
          {freelancer?.supervisor ? <InfoRow label="Supervisor" value={freelancer.supervisor.full_name} /> : null}
        </Group>
      ) : null}

      {policy ? (
        <Group title="Aturan presensi">
          <InfoRow label="Jam kerja" value={`${policy.work_start_time?.slice(0, 5) ?? '-'} – ${policy.work_end_time?.slice(0, 5) ?? '-'}`} />
          <InfoRow label="Toleransi terlambat" value={`${policy.late_tolerance_minutes} menit`} />
          <InfoRow label="Wajib GPS" value={yesNo(policy.require_gps)} />
          <InfoRow label="Wajib foto" value={yesNo(policy.require_photo)} />
          <InfoRow label="Verifikasi wajah" value={yesNo(policy.require_face_recognition)} />
        </Group>
      ) : null}

      <Group title="Pengaturan">
        {access.canUseAttendance ? (
          <Row
            icon="scan-outline"
            title="Foto master wajah"
            subtitle={access.masterFaceVerifiedAt ? `Didaftarkan ${formatDateTime(access.masterFaceVerifiedAt)}` : undefined}
            value={access.masterFaceVerifiedAt ? 'Terdaftar' : 'Belum'}
            onPress={() => router.push('/attendance/master-face')}
          />
        ) : null}
        {access.canResign ? (
          <Row icon="exit-outline" title="Pengunduran diri" subtitle="Ajukan atau lihat status resign" onPress={() => router.push('/request/resignation/new')} />
        ) : null}
        <Row icon="contrast-outline" title="Tampilan" value={APPEARANCE_LABELS[appearance]} onPress={() => router.push('/settings/appearance')} />
        <Row icon="server-outline" title="Alamat server" value={serverHost} onPress={() => router.push('/settings/server')} />
      </Group>

      <Button title="Keluar" variant="danger" size="lg" icon="log-out-outline" onPress={confirmLogout} loading={logout.isPending} />
      <View style={styles.footer}>
        <Ionicons name="time-outline" size={14} color={colors.textMuted} />
        <Text style={styles.footerText}>OnTime Mobile</Text>
      </View>
    </Screen>
  );
}

const useStyles = makeStyles((c) => ({
  roles: { maxWidth: 140 },
  badge: { alignSelf: 'flex-end' },
  footer: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 4, paddingVertical: spacing.sm },
  footerText: { fontSize: fontSize.xs, color: c.textMuted },
}));
