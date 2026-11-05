import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Badge } from '@/components/ui';
import type { AttendanceCorrection, LeaveRequest, OvertimeRequest, Resignation } from '@/lib/api';
import { formatDate, formatDateRange, formatMinutes, formatTime, requestStatusLabel, requestStatusTone } from '@/lib/format';
import { colors, fontSize, radius, spacing } from '@/theme';

export type RequestKind = 'leave' | 'overtime' | 'correction' | 'resignation';

export const REQUEST_KIND_LABEL: Record<RequestKind, string> = {
  leave: 'Cuti / Izin',
  overtime: 'Lembur',
  correction: 'Koreksi Absensi',
  resignation: 'Pengunduran Diri',
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

export function RequestRow({ item, showWorker, href }: { item: AnyRequest; showWorker?: boolean; href?: { pathname: '/request/[type]/[id]' | '/approval/[type]/[id]' } }) {
  const target = href?.pathname ?? '/request/[type]/[id]';
  const step = requestStepLabel(item);
  return (
    <Pressable
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      onPress={() => router.push({ pathname: target, params: { type: item.kind, id: String(item.data.id) } })}>
      <View style={styles.body}>
        {showWorker ? <Text style={styles.worker}>{requestWorker(item)}</Text> : null}
        <Text style={styles.title}>{requestTitle(item)}</Text>
        <Text style={styles.subtitle}>{requestSubtitle(item)}</Text>
        <Text style={styles.reason} numberOfLines={2}>
          {item.data.reason}
        </Text>
      </View>
      <View style={styles.right}>
        <Badge label={requestStatusLabel(item.data.status)} tone={requestStatusTone(item.data.status)} />
        {step ? <Text style={styles.step}>{step}</Text> : null}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
  },
  pressed: { opacity: 0.8 },
  body: { flex: 1, gap: 2 },
  worker: { fontSize: fontSize.sm, color: colors.primary, fontWeight: '600' },
  title: { fontSize: fontSize.md, fontWeight: '600', color: colors.text },
  subtitle: { fontSize: fontSize.sm, color: colors.textMuted },
  reason: { fontSize: fontSize.sm, color: colors.text, marginTop: 4 },
  right: { alignItems: 'flex-end', gap: 4 },
  step: { fontSize: fontSize.xs, color: colors.textMuted },
});
