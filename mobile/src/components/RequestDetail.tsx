import { Linking, StyleSheet, Text, View } from 'react-native';

import { type AnyRequest, REQUEST_KIND_LABEL, requestStepLabel, requestTitle, requestWorker } from '@/components/RequestRow';
import { Badge, Button, Card, InfoRow, Notice, text } from '@/components/ui';
import { formatDate, formatDateTime, formatMinutes, formatTime, requestStatusLabel, requestStatusTone } from '@/lib/format';
import { spacing } from '@/theme';

export function RequestDetail({ item }: { item: AnyRequest }) {
  const { data } = item;
  const step = requestStepLabel(item);
  const worker = data.employee ?? data.intern ?? data.freelancer;
  const attachment = 'attachment' in data ? data.attachment : null;

  return (
    <>
      <Card>
        <View style={styles.header}>
          <View style={styles.headerText}>
            <Text style={text.muted}>{REQUEST_KIND_LABEL[item.kind]}</Text>
            <Text style={text.title}>{requestTitle(item)}</Text>
            <Text style={text.subtitle}>{requestWorker(item)}</Text>
          </View>
          <Badge label={requestStatusLabel(data.status)} tone={requestStatusTone(data.status)} />
        </View>
        {worker?.division ? <InfoRow label="Divisi" value={worker.division.name} /> : null}
        {worker?.company ? <InfoRow label="Badan usaha" value={worker.company.name} /> : null}

        {item.kind === 'leave' ? (
          <>
            <InfoRow label="Mulai" value={formatDate(item.data.start_date)} />
            <InfoRow label="Selesai" value={formatDate(item.data.end_date)} />
            <InfoRow label="Jumlah hari" value={`${item.data.days_count ?? '-'} hari`} />
          </>
        ) : null}
        {item.kind === 'overtime' ? (
          <>
            <InfoRow label="Tanggal" value={formatDate(item.data.date)} />
            <InfoRow label="Jam" value={`${formatTime(item.data.start_time)} – ${formatTime(item.data.end_time)}`} />
            <InfoRow label="Durasi" value={formatMinutes(item.data.duration_minutes)} />
            {item.data.actual_duration_minutes ? <InfoRow label="Durasi aktual" value={formatMinutes(item.data.actual_duration_minutes)} /> : null}
          </>
        ) : null}
        {item.kind === 'correction' ? (
          <>
            <InfoRow label="Tanggal presensi" value={formatDate(item.data.date)} />
            <InfoRow label="Jam masuk" value={formatTime(item.data.corrected_check_in)} />
            <InfoRow label="Jam pulang" value={formatTime(item.data.corrected_check_out)} />
          </>
        ) : null}
        {item.kind === 'resignation' ? (
          <>
            <InfoRow label="Tanggal pengajuan" value={formatDate(item.data.resignation_date)} />
            <InfoRow label="Hari kerja terakhir" value={formatDate(item.data.last_working_day)} />
          </>
        ) : null}

        <InfoRow label="Diajukan" value={formatDateTime(data.created_at)} />
        {step ? <InfoRow label="Proses" value={step} /> : null}
      </Card>

      <Card>
        <Text style={text.strong}>Alasan</Text>
        <Text style={text.body}>{data.reason}</Text>
        {item.kind === 'resignation' && item.data.handover_notes ? (
          <>
            <Text style={[text.strong, styles.spaced]}>Catatan serah terima</Text>
            <Text style={text.body}>{item.data.handover_notes}</Text>
          </>
        ) : null}
        {attachment ? <Button title="Buka lampiran" variant="secondary" icon="attach-outline" onPress={() => Linking.openURL(attachment)} /> : null}
      </Card>

      {data.status === 'rejected' && data.rejection_reason ? <Notice tone="danger" message={`Alasan penolakan: ${data.rejection_reason}`} /> : null}
    </>
  );
}

const styles = StyleSheet.create({
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: spacing.sm, marginBottom: spacing.sm },
  headerText: { flex: 1, gap: 2 },
  spaced: { marginTop: spacing.sm },
});
