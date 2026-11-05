import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { Pressable, View } from 'react-native';

import { Badge, type IconName, Text } from '@/components/ui';
import type { AttendanceCorrection, LeaveRequest, OvertimeRequest, Resignation } from '@/lib/api';
import { formatDate, formatDateRange, formatMinutes, formatTime, requestStatusLabel, requestStatusTone } from '@/lib/format';
import { fontSize, makeStyles, radius, spacing, useTheme } from '@/theme';

export type RequestKind = 'leave' | 'overtime' | 'correction' | 'resignation';

export const REQUEST_KIND_LABEL: Record<RequestKind, string> = {
  leave: 'Cuti / Izin',
  overtime: 'Lembur',
  correction: 'Koreksi Absensi',
  resignation: 'Pengunduran Diri',
};

/** Icon shown in the tile on the left of a request row. */
export const REQUEST_KIND_ICON: Record<RequestKind, IconName> = {
  leave: 'sunny-outline',
  overtime: 'moon-outline',
  correction: 'create-outline',
  resignation: 'exit-outline',
};

export type AnyRequest =
  | { kind: 'leave'; data: LeaveRequest }
  | { kind: 'overtime'; data: OvertimeRequest }
  | { kind: 'correction'; data: AttendanceCorrection }
  | { kind: 'resignation'; data: Resignation };

export function requestTitle(item: AnyRequest): string {
  switch (item.kind) {
    case 'leave':
      return item.data.leave_type_label;
    case 'overtime':
      return `Lembur ${formatTime(item.data.start_time)} – ${formatTime(item.data.end_time)}`;
    case 'correction':
      return 'Koreksi absensi';
    case 'resignation':
      return 'Pengunduran diri';
  }
}

export function requestSubtitle(item: AnyRequest): string {
  switch (item.kind) {
    case 'leave':
      return `${formatDateRange(item.data.start_date, item.data.end_date)}${item.data.days_count ? ` · ${item.data.days_count} hari` : ''}`;
    case 'overtime':
      return `${formatDate(item.data.date, 'short')}${item.data.duration_minutes ? ` · ${formatMinutes(item.data.duration_minutes)}` : ''}`;
    case 'correction': {
      const parts = [];
      if (item.data.corrected_check_in) {
        parts.push(`Masuk ${formatTime(item.data.corrected_check_in)}`);
      }
      if (item.data.corrected_check_out) {
        parts.push(`Pulang ${formatTime(item.data.corrected_check_out)}`);
      }
      return `${formatDate(item.data.date, 'short')}${parts.length ? ` · ${parts.join(', ')}` : ''}`;
    }
    case 'resignation':
      return `Hari kerja terakhir ${formatDate(item.data.last_working_day, 'short')}`;
  }
}

export function requestWorker(item: AnyRequest): string {
  const data = item.data;
  return data.employee?.full_name ?? data.intern?.full_name ?? data.freelancer?.full_name ?? data.user?.name ?? '-';
}

export function requestStepLabel(item: AnyRequest): string | null {
  const { status, current_step } = item.data;
  if (status !== 'pending' || !current_step) {
    return null;
  }
  const total = 'total_steps' in item.data ? item.data.total_steps : null;
  return total ? `Tahap ${current_step} dari ${total}` : `Tahap ${current_step}`;
}

/**
 * One request inside a Group card: kind icon tile, summary with the status underneath, and a
 * chevron. The status sits under the text rather than in a right column because labels such as
 * "Menunggu Persetujuan" would otherwise squeeze the summary on narrow phones. It has no card
 * background of its own, so always place it inside a Group.
 */
export function RequestRow({ item, showWorker, href }: { item: AnyRequest; showWorker?: boolean; href?: { pathname: '/request/[type]/[id]' | '/approval/[type]/[id]' } }) {
  const styles = useStyles();
  const { colors } = useTheme();
  const target = href?.pathname ?? '/request/[type]/[id]';
  const step = requestStepLabel(item);

  return (
    <Pressable
      accessibilityRole="button"
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      onPress={() => router.push({ pathname: target, params: { type: item.kind, id: String(item.data.id) } })}>
      <View style={styles.tile}>
        <Ionicons name={REQUEST_KIND_ICON[item.kind]} size={22} color={colors.text} />
      </View>
      <View style={styles.body}>
        {showWorker ? (
          <Text style={styles.worker} numberOfLines={1}>
            {requestWorker(item)}
          </Text>
        ) : null}
        <Text style={styles.title} numberOfLines={2}>
          {requestTitle(item)}
        </Text>
        <Text style={styles.subtitle}>{requestSubtitle(item)}</Text>
        {item.data.reason ? (
          <Text style={styles.reason} numberOfLines={2}>
            {item.data.reason}
          </Text>
        ) : null}
        <View style={styles.status}>
          <Badge label={requestStatusLabel(item.data.status)} tone={requestStatusTone(item.data.status)} />
          {step ? <Text style={styles.step}>{step}</Text> : null}
        </View>
      </View>
      <Ionicons name="chevron-forward" size={20} color={colors.textSubtle} />
    </Pressable>
  );
}

const useStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingHorizontal: 16, paddingVertical: 14 },
  pressed: { backgroundColor: c.fill },
  tile: { width: 46, height: 46, borderRadius: radius.md, backgroundColor: c.fill, alignItems: 'center', justifyContent: 'center', alignSelf: 'flex-start' },
  body: { flex: 1, gap: 2 },
  worker: { fontSize: fontSize.sm, color: c.primary, fontWeight: '600' },
  title: { fontSize: fontSize.lg, fontWeight: '600', color: c.text },
  subtitle: { fontSize: fontSize.sm, color: c.textMuted },
  reason: { fontSize: fontSize.sm, color: c.text, marginTop: 4, lineHeight: 19 },
  status: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: spacing.sm, marginTop: spacing.sm },
  step: { fontSize: fontSize.xs, color: c.textMuted },
}));
