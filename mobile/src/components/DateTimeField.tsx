import DateTimePicker, { type DateTimePickerEvent } from '@react-native-community/datetimepicker';
import { useState } from 'react';
import { Platform, View } from 'react-native';

import { Button, PickerField, Sheet } from '@/components/ui';
import { formatDate, toDateString, toTimeString } from '@/lib/format';
import { makeStyles, spacing, useTheme } from '@/theme';

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
 * iOS shows the spinner in a bottom sheet with cancel and done buttons.
 */
export function DateTimeField({ label, mode, value, onChange, error, minimumDate, hint }: Props) {
  const styles = useStyles();
  const { colors, dark } = useTheme();
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

  const close = () => setOpen(false);

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
        <Sheet visible={open} onClose={close} title={label}>
          <DateTimePicker
            value={draft}
            mode={mode}
            display="spinner"
            locale="id-ID"
            onChange={handleChange}
            minimumDate={minimumDate}
            themeVariant={dark ? 'dark' : 'light'}
            textColor={colors.text}
            accentColor={colors.primary}
            style={styles.spinner}
          />
          <View style={styles.actions}>
            <Button title="Batal" variant="secondary" onPress={close} style={styles.action} />
            <Button
              title="Selesai"
              onPress={() => {
                commit(draft);
                close();
              }}
              style={styles.action}
            />
          </View>
        </Sheet>
      ) : null}
    </>
  );
}

const useStyles = makeStyles(() => ({
  spinner: { alignSelf: 'center' },
  actions: { flexDirection: 'row', gap: spacing.md },
  action: { flex: 1 },
}));
