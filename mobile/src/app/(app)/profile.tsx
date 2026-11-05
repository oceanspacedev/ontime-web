import { Ionicons } from '@expo/vector-icons';
import { useMutation } from '@tanstack/react-query';
import { router } from 'expo-router';
import { Alert, StyleSheet, Text, View } from 'react-native';

import { Avatar, Button, Card, InfoRow, ListRow, Screen, text } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { useRefreshUser } from '@/hooks/useRefreshUser';
import { authApi } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useSession } from '@/lib/session';
import { spacing } from '@/theme';

export default function ProfileScreen() {
  const user = useSession((state) => state.user);
  const baseUrl = useSession((state) => state.baseUrl);
  const signOut = useSession((state) => state.signOut);
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

  return (
    <Screen onRefresh={() => refreshUser.mutate()}>
      <Card style={styles.identity}>
        <Avatar name={access.profileName || user?.name || ''} uri={access.masterFacePhoto} size={72} />
        <View style={styles.identityText}>
          <Text style={text.title}>{access.profileName || user?.name}</Text>
          <Text style={text.subtitle}>{user?.email}</Text>
          <Text style={text.muted}>{access.roles.join(', ')}</Text>
        </View>
      </Card>

      {employee || intern || freelancer ? (
        <Card>
          <Text style={text.strong}>Data pekerja</Text>
          {employee?.nip ? <InfoRow label="NIP" value={employee.nip} /> : null}
          <InfoRow label="Badan usaha" value={access.company?.name ?? '-'} />
          <InfoRow label="Divisi" value={division?.name ?? '-'} />
          {employee?.job_title ? <InfoRow label="Posisi" value={employee.job_title.name} /> : null}
          {employee?.job_level ? <InfoRow label="Tingkat" value={employee.job_level.name} /> : null}
          {intern ? <InfoRow label="Institusi" value={intern.institution} /> : null}
          {intern?.mentor ? <InfoRow label="Mentor" value={intern.mentor.full_name} /> : null}
          {freelancer ? <InfoRow label="Instansi" value={freelancer.institution} /> : null}
          {freelancer?.supervisor ? <InfoRow label="Supervisor" value={freelancer.supervisor.full_name} /> : null}
        </Card>
      ) : null}

      {policy ? (
        <Card>
          <Text style={text.strong}>Aturan presensi</Text>
          <InfoRow label="Jam kerja" value={`${policy.work_start_time?.slice(0, 5) ?? '-'} – ${policy.work_end_time?.slice(0, 5) ?? '-'}`} />
          <InfoRow label="Toleransi terlambat" value={`${policy.late_tolerance_minutes} menit`} />
          <InfoRow label="Wajib GPS" value={policy.require_gps ? 'Ya' : 'Tidak'} />
          <InfoRow label="Wajib foto" value={policy.require_photo ? 'Ya' : 'Tidak'} />
          <InfoRow label="Verifikasi wajah" value={policy.require_face_recognition ? 'Ya' : 'Tidak'} />
        </Card>
      ) : null}

      <View style={styles.list}>
        {access.canUseAttendance ? (
          <ListRow
            icon="scan-outline"
            title="Foto master wajah"
            subtitle={access.masterFaceVerifiedAt ? `Didaftarkan ${formatDateTime(access.masterFaceVerifiedAt)}` : 'Belum didaftarkan'}
            onPress={() => router.push('/attendance/master-face')}
          />
        ) : null}
        {access.canResign ? (
          <ListRow icon="exit-outline" title="Pengunduran diri" subtitle="Ajukan atau lihat status resign" onPress={() => router.push('/request/resignation/new')} />
        ) : null}
        <ListRow icon="server-outline" title="Alamat server" subtitle={baseUrl} onPress={() => router.push('/settings/server')} />
      </View>

      <Button title="Keluar" variant="danger" icon="log-out-outline" onPress={confirmLogout} loading={logout.isPending} />
      <View style={styles.footer}>
        <Ionicons name="time-outline" size={14} color="#94A3B8" />
        <Text style={text.muted}>OnTime Mobile</Text>
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  identity: { flexDirection: 'row', alignItems: 'center', gap: spacing.lg },
  identityText: { flex: 1, gap: 2 },
  list: { gap: spacing.sm },
  footer: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 4, paddingVertical: spacing.md },
});
