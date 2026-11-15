import { Alert, Linking, View } from 'react-native';

import { Button, Group, Notice, Sheet, Text } from '@/components/ui';
import type { AccountCredentials } from '@/lib/api';
import { fontSize, makeStyles, spacing } from '@/theme';

/**
 * The login of a new or reset account, shown once. The values are selectable so they can be copied by
 * long-press, and WhatsApp opens with the message ready when the server could not send it.
 */
export function AccountCredentialsSheet({ credentials, title, onClose }: { credentials: AccountCredentials | null; title: string; onClose: () => void }) {
  const styles = useStyles();
  const waUrl = credentials?.wa_url ?? null;

  const openWhatsApp = () => {
    if (!waUrl) {
      return;
    }
    Linking.openURL(waUrl).catch(() => Alert.alert('Gagal', 'WhatsApp tidak bisa dibuka di perangkat ini.'));
  };

  return (
    <Sheet visible={credentials !== null} onClose={onClose} title={title}>
      {credentials ? (
        <>
          <Notice
            tone={credentials.whatsapp_sent ? 'success' : 'warning'}
            icon={credentials.whatsapp_sent ? 'checkmark-circle' : 'alert-circle'}
            message={credentials.whatsapp_sent ? 'Akun login sudah dikirim lewat WhatsApp.' : 'Akun login belum terkirim lewat WhatsApp. Sampaikan email dan kata sandi ini.'}
          />
          <Group padded>
            <View style={styles.item}>
              <Text style={styles.label}>Email</Text>
              <Text style={styles.value} selectable>
                {credentials.email}
              </Text>
            </View>
            <View style={styles.item}>
              <Text style={styles.label}>Kata sandi</Text>
              <Text style={[styles.value, styles.password]} selectable>
                {credentials.password}
              </Text>
            </View>
            <Text style={styles.hint}>Tekan lama untuk menyalin. Kata sandi hanya ditampilkan sekali.</Text>
          </Group>
          <View style={styles.actions}>
            {waUrl ? <Button title="Kirim via WhatsApp" icon="logo-whatsapp" variant="whatsapp" onPress={openWhatsApp} /> : null}
            <Button title="Selesai" variant="secondary" onPress={onClose} />
          </View>
        </>
      ) : null}
    </Sheet>
  );
}

const useStyles = makeStyles((c) => ({
  item: { gap: 4 },
  label: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500' },
  value: { fontSize: fontSize.lg, color: c.text, fontWeight: '600' },
  password: { fontVariant: ['tabular-nums'], letterSpacing: 0.5 },
  hint: { fontSize: fontSize.xs, color: c.textMuted },
  actions: { gap: spacing.sm },
}));
