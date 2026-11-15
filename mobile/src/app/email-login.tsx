import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { View } from 'react-native';

import { Button, LoadingView, Screen, Text } from '@/components/ui';
import { authApi, errorMessage, isNetworkError } from '@/lib/api';
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
  /** Why the link could not be redeemed; null while the app is still trying. */
  const [error, setError] = useState<unknown>(null);
  const started = useRef(false);

  const verify = useCallback(async () => {
    const pending = await readPendingMagicLink();
    if (!token || !pending) {
      setError(new Error('Link masuk ini sudah kedaluwarsa atau diminta dari HP lain. Minta link baru dari aplikasi di HP ini.'));
      return;
    }
    try {
      const response = await authApi.verifyMagicLink(pending.challengeId, token);
      await clearPendingMagicLink();
      await signIn(response.token, response.user);
    } catch (cause) {
      setError(cause);
    }
  }, [token, signIn]);

  useEffect(() => {
    if (started.current) {
      return;
    }
    started.current = true;
    void verify();
  }, [verify]);

  if (!error) {
    return <LoadingView message="Memproses link masuk…" />;
  }

  // Without a connection the link was never used, so it can be sent again as it is.
  const offline = isNetworkError(error);
  const retry = () => {
    setError(null);
    void verify();
  };

  return (
    <Screen edges={['top', 'bottom']} scroll={false}>
      <View style={styles.container}>
        <Ionicons name={offline ? 'cloud-offline-outline' : 'mail-unread-outline'} size={56} color={colors.textMuted} />
        <Text style={styles.title}>{offline ? 'Belum bisa masuk' : 'Link tidak bisa dipakai'}</Text>
        <Text style={styles.message}>{errorMessage(error)}</Text>
        <View style={styles.actions}>
          {offline ? <Button title="Coba lagi" icon="refresh" size="lg" onPress={retry} /> : null}
          <Button title="Kembali ke halaman masuk" size="lg" variant={offline ? 'secondary' : 'primary'} onPress={() => router.replace('/login')} />
        </View>
      </View>
    </Screen>
  );
}

const useStyles = makeStyles((c) => ({
  container: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.md, paddingHorizontal: spacing.lg },
  title: { fontSize: fontSize.xl, fontWeight: '700', color: c.text, textAlign: 'center' },
  message: { fontSize: fontSize.md, color: c.textMuted, textAlign: 'center', lineHeight: 22 },
  actions: { alignSelf: 'stretch', marginTop: spacing.md, gap: spacing.sm },
}));
