import DateTimePicker, { type DateTimePickerEvent } from '@react-native-community/datetimepicker';
import { useState } from 'react';
import { Modal, Platform, Pressable, StyleSheet, Text, View } from 'react-native';

import { PickerField } from '@/components/ui';
import { formatDate, toDateString, toTimeString } from '@/lib/format';
import { colors, fontSize, radius, spacing } from '@/theme';

interface Props {
  label: string;
  mode: 'date' | 'time';
  /** `YYYY-MM-DD` for dates, `HH:MM` for times. */
  value: string | null;
  onChange: (value: string) => void;
  error?: string | null;
  minimumDate?: Date;
  hint?: string;
}

function parseValue(mode: 'date' | 'time', value: string | null): Date {
  const now = new Date();
  if (!value) {
    return now;
  }
  if (mode === 'date') {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(y, m - 1, d);
  }
  const [h, min] = value.split(':').map(Number);
  const date = new Date(now);
  date.setHours(h, min, 0, 0);
  return date;
}

/**
 * Date or time input that works the same on both platforms: Android opens the system dialog,
 * iOS shows the spinner in a bottom sheet with a done button.
 */
export function DateTimeField({ label, mode, value, onChange, error, minimumDate, hint }: Props) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<Date>(() => parseValue(mode, value));

  const display = value ? (mode === 'date' ? formatDate(value, 'weekday') : value) : null;

  const commit = (date: Date) => {
    onChange(mode === 'date' ? toDateString(date) : toTimeString(date));
  };

  const handleChange = (event: DateTimePickerEvent, date?: Date) => {
    if (Platform.OS === 'android') {
      setOpen(false);
      if (event.type === 'set' && date) {
        commit(date);
      }
      return;
    }
    if (date) {
      setDraft(date);
    }
  };

  const openPicker = () => {
    setDraft(parseValue(mode, value));
    setOpen(true);
  };

  return (
    <>
      <PickerField
        label={label}
        value={display}
        placeholder={mode === 'date' ? 'Pilih tanggal' : 'Pilih jam'}
        onPress={openPicker}
        error={error}
        icon={mode === 'date' ? 'calendar-outline' : 'time-outline'}
        hint={hint}
      />
      {open && Platform.OS === 'android' ? (
        <DateTimePicker value={draft} mode={mode} is24Hour onChange={handleChange} minimumDate={minimumDate} />
      ) : null}
      {Platform.OS === 'ios' ? (
        <Modal visible={open} transparent animationType="slide" onRequestClose={() => setOpen(false)}>
          <Pressable style={styles.backdrop} onPress={() => setOpen(false)} />
          <View style={styles.sheet}>
            <View style={styles.sheetHeader}>
              <Pressable onPress={() => setOpen(false)} hitSlop={8}>
                <Text style={styles.sheetCancel}>Batal</Text>
              </Pressable>
              <Text style={styles.sheetTitle}>{label}</Text>
              <Pressable
                onPress={() => {
                  commit(draft);
                  setOpen(false);
                }}
                hitSlop={8}>
                <Text style={styles.sheetDone}>Selesai</Text>
              </Pressable>
            </View>
            <DateTimePicker
              value={draft}
              mode={mode}
              display="spinner"
              locale="id-ID"
              onChange={handleChange}
              minimumDate={minimumDate}
              style={styles.spinner}
            />
          </View>
        </Modal>
      ) : null}
    </>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: 'rgba(15, 23, 42, 0.4)' },
  sheet: {
    backgroundColor: colors.surface,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    paddingBottom: spacing.xl,
  },
  sheetHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    padding: spacing.lg,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  sheetTitle: { fontSize: fontSize.md, fontWeight: '600', color: colors.text },
  sheetCancel: { fontSize: fontSize.md, color: colors.textMuted },
  sheetDone: { fontSize: fontSize.md, color: colors.primary, fontWeight: '700' },
  spinner: { alignSelf: 'center' },
});
