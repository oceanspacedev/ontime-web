import { Image } from 'expo-image';
import { StyleSheet, Text, View } from 'react-native';

import { workerName } from '@/components/AttendanceRow';
import { Badge, Button, Card, InfoRow, Notice, text } from '@/components/ui';
import type { Attendance } from '@/lib/api';
import { attendanceStatusLabel, attendanceStatusTone, formatDate, formatTime } from '@/lib/format';
import { openMaps } from '@/lib/geo';
import { colors, radius, spacing } from '@/theme';

export function AttendanceDetail({ attendance }: { attendance: Attendance }) {
  return (
    <>
      <Card>
        <View style={styles.header}>
          <View style={styles.headerText}>
            <Text style={text.title}>{formatDate(attendance.date, 'weekday')}</Text>
            <Text style={text.subtitle}>{workerName(attendance)}</Text>
          </View>
          <Badge label={attendanceStatusLabel(attendance.status)} tone={attendanceStatusTone(attendance.status)} />
        </View>
        <InfoRow label="Masuk" value={formatTime(attendance.check_in)} />
        <InfoRow label="Pulang" value={formatTime(attendance.check_out)} />
        {attendance.late_minutes ? <InfoRow label="Terlambat" value={`${attendance.late_minutes} menit`} /> : null}
        {attendance.is_corrected ? <InfoRow label="Koreksi" value="Sudah dikoreksi" /> : null}
        {attendance.is_out_of_bounds ? <Notice tone="warning" message="Presensi dilakukan di luar area kantor." /> : null}
        {attendance.is_face_verified === false ? (
          <Notice tone="warning" message={`Verifikasi wajah tidak cocok${attendance.face_verification_notes ? `: ${attendance.face_verification_notes}` : '.'}`} />
        ) : null}
      </Card>

      <PhotoCard title="Foto masuk" uri={attendance.check_in_photo} lat={attendance.check_in_lat} lng={attendance.check_in_lng} />
      <PhotoCard title="Foto pulang" uri={attendance.check_out_photo} lat={attendance.check_out_lat} lng={attendance.check_out_lng} />
    </>
  );
}

function PhotoCard({ title, uri, lat, lng }: { title: string; uri: string | null; lat: string | null; lng: string | null }) {
  if (!uri && !lat) {
    return null;
  }
  return (
    <Card>
      <Text style={text.strong}>{title}</Text>
      {uri ? <Image source={{ uri }} style={styles.photo} contentFit="cover" /> : null}
      {lat && lng ? <Button title="Lihat lokasi di peta" variant="secondary" icon="map-outline" onPress={() => openMaps(lat, lng)} /> : null}
    </Card>
  );
}

const styles = StyleSheet.create({
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: spacing.sm, marginBottom: spacing.sm },
  headerText: { flex: 1, gap: 2 },
  photo: { width: '100%', aspectRatio: 3 / 4, borderRadius: radius.md, backgroundColor: colors.graySoft },
});
