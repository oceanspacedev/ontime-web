import { Image } from 'expo-image';
import { View } from 'react-native';

import { workerName } from '@/components/AttendanceRow';
import { Badge, Button, Group, InfoRow, Notice, Text, useTextStyles } from '@/components/ui';
import type { Attendance } from '@/lib/api';
import { attendanceStatusLabel, attendanceStatusTone, formatDate, formatTime } from '@/lib/format';
import { openMaps } from '@/lib/geo';
import { makeStyles, radius, spacing } from '@/theme';

export function AttendanceDetail({ attendance }: { attendance: Attendance }) {
  const styles = useStyles();
  const t = useTextStyles();

  return (
    <>
      <Group>
        <View style={styles.header}>
          <View style={styles.headerText}>
            <Text style={t.title}>{formatDate(attendance.date, 'weekday')}</Text>
            <Text style={t.subtitle}>{workerName(attendance)}</Text>
          </View>
          <Badge label={attendanceStatusLabel(attendance.status)} tone={attendanceStatusTone(attendance.status)} />
        </View>
        <InfoRow label="Masuk" value={formatTime(attendance.check_in)} valueStyle={styles.time} />
        <InfoRow label="Pulang" value={formatTime(attendance.check_out)} valueStyle={styles.time} />
        {attendance.late_minutes ? <InfoRow label="Terlambat" value={`${attendance.late_minutes} menit`} /> : null}
        {attendance.is_corrected ? <InfoRow label="Koreksi" value="Sudah dikoreksi" /> : null}
      </Group>

      {attendance.is_out_of_bounds ? <Notice tone="warning" message="Presensi dilakukan di luar area kantor." /> : null}
      {attendance.is_face_verified === false ? (
        <Notice tone="warning" message={`Verifikasi wajah tidak cocok${attendance.face_verification_notes ? `: ${attendance.face_verification_notes}` : '.'}`} />
      ) : null}

      <PhotoGroup title="Foto masuk" uri={attendance.check_in_photo} lat={attendance.check_in_lat} lng={attendance.check_in_lng} />
      <PhotoGroup title="Foto pulang" uri={attendance.check_out_photo} lat={attendance.check_out_lat} lng={attendance.check_out_lng} />
    </>
  );
}

function PhotoGroup({ title, uri, lat, lng }: { title: string; uri: string | null; lat: string | null; lng: string | null }) {
  const styles = useStyles();
  if (!uri && !lat) {
    return null;
  }
  return (
    <Group title={title} padded>
      {uri ? <Image source={{ uri }} style={styles.photo} contentFit="cover" accessibilityLabel={title} /> : null}
      {lat && lng ? <Button title="Lihat lokasi di peta" variant="secondary" icon="map-outline" onPress={() => openMaps(lat, lng)} /> : null}
    </Group>
  );
}

const useStyles = makeStyles((c) => ({
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: spacing.md, paddingHorizontal: 16, paddingTop: 18, paddingBottom: 16 },
  headerText: { flex: 1, gap: 4 },
  time: { fontVariant: ['tabular-nums'] },
  photo: { width: '100%', aspectRatio: 3 / 4, borderRadius: radius.lg, backgroundColor: c.fill },
}));
