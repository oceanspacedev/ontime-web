import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { View } from 'react-native';

import { Button, LoadingView, Screen, Text } from '@/components/ui';
import { authApi, errorMessage } from '@/lib/api';
import { clearPendingMagicLink, readPendingMagicLink } from '@/lib/magicLink';
import { useSession } from '@/lib/session';
import { fontSize, makeStyles, spacing, useTheme } from '@/theme';

/**
 * Opened by the link in the login email (ontime://email-login?token=…). The token only works together
 * with the challenge id this device kept when it asked for the link.
 */
export default function EmailLoginScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const { token } = useLocalSearchParams<{ token?: string }>();
  const signIn = useSession((state) => state.signIn);
  const [error, setError] = useState<string | null>(null);
  const started = useRef(false);

  useEffect(() => {
    if (started.current) {
      return;
    }
    started.current = true;

    void (async () => {
      const pending = await readPendingMagicLink();
      if (!token || !pending) {
        setError('Link masuk ini sudah kedaluwarsa atau diminta dari HP lain. Minta link baru dari aplikasi di HP ini.');
        return;
      }
      try {
        const response = await authApi.verifyMagicLink(pending.challengeId, token);
        await clearPendingMagicLink();
        await signIn(response.token, response.user);
      } catch (cause) {
        setError(errorMessage(cause));
      }
    })();
  }, [token, signIn]);

  if (!error) {
    return <LoadingView message="Memproses link masuk…" />;
  }

  return (
    <Screen edges={['top', 'bottom']} scroll={false}>
      <View style={styles.container}>
        <Ionicons name="mail-unread-outline" size={56} color={colors.textMuted} />
        <Text style={styles.title}>Link tidak bisa dipakai</Text>
        <Text style={styles.message}>{error}</Text>
        <Button title="Kembali ke halaman masuk" size="lg" onPress={() => router.replace('/login')} style={styles.button} />
      </View>
    </Screen>
  );
}

const useStyles = makeStyles((c) => ({
  container: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.md, paddingHorizontal: spacing.lg },
  title: { fontSize: fontSize.xl, fontWeight: '700', color: c.text, textAlign: 'center' },
  message: { fontSize: fontSize.md, color: c.textMuted, textAlign: 'center', lineHeight: 22 },
  button: { alignSelf: 'stretch', marginTop: spacing.md },
}));
