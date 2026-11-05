import type { ReactElement } from 'react';
import { Linking, View } from 'react-native';

import { type AnyRequest, REQUEST_KIND_LABEL, requestStepLabel, requestTitle, requestWorker } from '@/components/RequestRow';
import { Badge, Button, Group, InfoRow, Notice, Text, useTextStyles } from '@/components/ui';
import { formatDate, formatDateTime, formatMinutes, formatTime, requestStatusLabel, requestStatusTone } from '@/lib/format';
import { makeStyles, spacing } from '@/theme';

/** Full read-only view of one request: summary rows, the reason and, when rejected, why. */
export function RequestDetail({ item }: { item: AnyRequest }) {
  const styles = useStyles();
  const t = useTextStyles();
  const { data } = item;
  const step = requestStepLabel(item);
  const worker = data.employee ?? data.intern ?? data.freelancer;
  const attachment = 'attachment' in data ? data.attachment : null;

  return (
    <>
      <Group>
        <View style={styles.header}>
          <Text style={t.label}>{REQUEST_KIND_LABEL[item.kind]}</Text>
          <Text style={t.title}>{requestTitle(item)}</Text>
          <Text style={t.subtitle}>{requestWorker(item)}</Text>
          <Badge label={requestStatusLabel(data.status)} tone={requestStatusTone(data.status)} style={styles.badge} />
        </View>
        {worker?.division ? <InfoRow label="Divisi" value={worker.division.name} /> : null}
        {worker?.company ? <InfoRow label="Badan usaha" value={worker.company.name} /> : null}

        {kindRows(item)}

        <InfoRow label="Diajukan" value={formatDateTime(data.created_at)} />
        {step ? <InfoRow label="Proses" value={step} /> : null}
      </Group>

      <Group title="Alasan" padded>
        <Text style={t.body}>{data.reason}</Text>
        {item.kind === 'resignation' && item.data.handover_notes ? (
          <View style={styles.block}>
            <Text style={t.strong}>Catatan serah terima</Text>
            <Text style={t.body}>{item.data.handover_notes}</Text>
          </View>
        ) : null}
        {attachment ? <Button title="Buka lampiran" variant="secondary" icon="attach-outline" onPress={() => Linking.openURL(attachment)} /> : null}
      </Group>

      {data.status === 'rejected' && data.rejection_reason ? <Notice tone="danger" message={`Alasan penolakan: ${data.rejection_reason}`} /> : null}
    </>
  );
}

/**
 * The rows specific to each kind. Returned as a keyed array (not a Fragment) so Group sees every
 * row as its own child and draws a separator between them.
 */
function kindRows(item: AnyRequest): ReactElement[] {
  switch (item.kind) {
    case 'leave':
      return [
        <InfoRow key="start" label="Mulai" value={formatDate(item.data.start_date)} />,
        <InfoRow key="end" label="Selesai" value={formatDate(item.data.end_date)} />,
        <InfoRow key="days" label="Jumlah hari" value={`${item.data.days_count ?? '-'} hari`} />,
      ];
    case 'overtime':
      return [
        <InfoRow key="date" label="Tanggal" value={formatDate(item.data.date)} />,
        <InfoRow key="time" label="Jam" value={`${formatTime(item.data.start_time)} – ${formatTime(item.data.end_time)}`} />,
        <InfoRow key="duration" label="Durasi" value={formatMinutes(item.data.duration_minutes)} />,
        ...(item.data.actual_duration_minutes
          ? [<InfoRow key="actual" label="Durasi aktual" value={formatMinutes(item.data.actual_duration_minutes)} />]
          : []),
      ];
    case 'correction':
      return [
        <InfoRow key="date" label="Tanggal presensi" value={formatDate(item.data.date)} />,
        <InfoRow key="in" label="Jam masuk" value={formatTime(item.data.corrected_check_in)} />,
        <InfoRow key="out" label="Jam pulang" value={formatTime(item.data.corrected_check_out)} />,
      ];
    case 'resignation':
      return [
        <InfoRow key="date" label="Tanggal pengajuan" value={formatDate(item.data.resignation_date)} />,
        <InfoRow key="last" label="Hari kerja terakhir" value={formatDate(item.data.last_working_day)} />,
      ];
  }
}

const useStyles = makeStyles(() => ({
  header: { gap: 4, paddingHorizontal: 16, paddingTop: 18, paddingBottom: 16 },
  badge: { marginTop: spacing.sm },
  block: { gap: 4, marginTop: spacing.xs },
}));
