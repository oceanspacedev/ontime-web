import { Ionicons } from '@expo/vector-icons';
import { useIsMutating, useMutation } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, Pressable, View } from 'react-native';

import { Button, ErrorBanner, Field, Notice, Screen, Text } from '@/components/ui';
import { authApi, errorMessage } from '@/lib/api';
import { clearPendingMagicLink, savePendingMagicLink } from '@/lib/magicLink';
import { useSession } from '@/lib/session';
import { BRAND_GLOSS, BRAND_GRADIENT, fontSize, makeStyles, radius, spacing, useTheme } from '@/theme';

/** The white mark of the OnTime logo, the same one the web panel shows (public/images/icon.svg). */
const BRAND_MARK = require('../../assets/brand-mark.png');

/** Email and password by default; WhatsApp code and email link are the alternatives below it. */
type Mode = 'password' | 'whatsapp' | 'emailLink';

/** Shared by every sign-in mutation so the mode switch can stay still while one is running. */
const AUTH_MUTATION_KEY = ['auth', 'sign-in'];

export default function LoginScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const [mode, setMode] = useState<Mode>('password');
  const busy = useIsMutating({ mutationKey: AUTH_MUTATION_KEY }) > 0;
  const baseUrl = useSession((state) => state.baseUrl);
  const serverLabel = baseUrl.replace(/^https?:\/\//, '').replace(/\/api\/v1$/, '');

  const changeMode = (next: Mode) => {
    if (!busy) {
      setMode(next);
    }
  };

  return (
    <Screen edges={['top', 'bottom']} padded={false}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.container}>
        <View style={styles.hero}>
          <View style={styles.logo}>
            <Image source={BRAND_MARK} style={styles.logoMark} accessibilityIgnoresInvertColors />
          </View>
          <Text style={styles.title}>OnTime</Text>
          <Text style={styles.subtitle}>Presensi dan pengajuan harian</Text>
        </View>

        <View style={styles.form}>
          {mode === 'password' ? <PasswordForm /> : null}
          {mode === 'whatsapp' ? <WhatsAppForm /> : null}
          {mode === 'emailLink' ? <EmailLinkForm /> : null}

          <View style={[styles.alternatives, busy && styles.modeBusy]}>
            {mode === 'password' ? (
              <>
                <View style={styles.dividerRow}>
                  <View style={styles.dividerLine} />
                  <Text style={styles.dividerText}>atau masuk dengan</Text>
                  <View style={styles.dividerLine} />
                </View>
                <View style={styles.alternativeRow}>
                  <Button title="WhatsApp" variant="whatsapp" icon="logo-whatsapp" style={styles.alternativeButton} onPress={() => changeMode('whatsapp')} />
                  <Button title="Email" variant="secondary" icon="mail-outline" style={styles.alternativeButton} onPress={() => changeMode('emailLink')} />
                </View>
              </>
            ) : (
              <Button title="Masuk dengan kata sandi" variant="ghost" size="sm" icon="arrow-back-outline" onPress={() => changeMode('password')} />
            )}
          </View>
        </View>

        <Pressable
          style={({ pressed }) => [styles.server, pressed && styles.serverPressed]}
          onPress={() => router.push('/settings/server')}
          hitSlop={8}
          accessibilityRole="button"
          accessibilityLabel={`Alamat server ${serverLabel}`}>
          <Ionicons name="server-outline" size={16} color={colors.textMuted} />
          <Text style={styles.serverText} numberOfLines={1}>
            {serverLabel}
          </Text>
          <Ionicons name="chevron-forward" size={16} color={colors.textMuted} />
        </Pressable>
      </KeyboardAvoidingView>
    </Screen>
  );
}

function PasswordForm() {
  const styles = useStyles();
  const { colors } = useTheme();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const signIn = useSession((state) => state.signIn);

  const login = useMutation({
    mutationKey: AUTH_MUTATION_KEY,
    mutationFn: () => authApi.login(email.trim(), password),
    onSuccess: async (response) => {
      await signIn(response.token, response.user);
    },
  });

  const canSubmit = email.trim().length > 0 && password.length > 0 && !login.isPending;

  return (
    <View style={styles.form}>
      {login.isError ? <ErrorBanner message={errorMessage(login.error)} /> : null}
      <Field
        label="Email"
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        autoComplete="email"
        autoCorrect={false}
        keyboardType="email-address"
        textContentType="emailAddress"
        placeholder="nama@perusahaan.id"
        returnKeyType="next"
      />
      <Field
        label="Kata sandi"
        value={password}
        onChangeText={setPassword}
        secureTextEntry={!showPassword}
        autoCapitalize="none"
        textContentType="password"
        placeholder="••••••••"
        returnKeyType="go"
        onSubmitEditing={() => canSubmit && login.mutate()}
        trailing={
          <Pressable
            onPress={() => setShowPassword((value) => !value)}
            hitSlop={10}
            accessibilityRole="button"
            accessibilityLabel={showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}>
            <Ionicons name={showPassword ? 'eye-off-outline' : 'eye-outline'} size={22} color={colors.textMuted} />
          </Pressable>
        }
      />
      <Button title="Masuk" size="lg" onPress={() => login.mutate()} disabled={!canSubmit} loading={login.isPending} />
    </View>
  );
}

/** Two steps: ask for a code at the WhatsApp number, then type the 6-digit code. */
function WhatsAppForm() {
  const styles = useStyles();
  const [destination, setDestination] = useState('');
  const [code, setCode] = useState('');
  const [challenge, setChallenge] = useState<{ id: string; destination: string; expiresIn: number } | null>(null);
  const signIn = useSession((state) => state.signIn);

  const requestOtp = useMutation({
    mutationKey: AUTH_MUTATION_KEY,
    mutationFn: () => authApi.requestOtp('whatsapp', destination.trim()),
    onSuccess: (response) => {
      setChallenge({ id: response.challenge_id, destination: destination.trim(), expiresIn: response.expires_in });
      setCode('');
    },
  });

  const verifyOtp = useMutation({
    mutationKey: AUTH_MUTATION_KEY,
    mutationFn: () => {
      if (!challenge) {
        throw new Error('Minta kode OTP terlebih dahulu.');
      }
      return authApi.verifyOtp(challenge.id, code.trim());
    },
    onSuccess: async (response) => {
      await signIn(response.token, response.user);
    },
  });

  const pending = requestOtp.isPending || verifyOtp.isPending;
  const canRequest = destination.trim().length > 0 && !pending;
  const canVerify = /^\d{6}$/.test(code.trim()) && !pending;

  const startOver = () => {
    setChallenge(null);
    setCode('');
    requestOtp.reset();
    verifyOtp.reset();
  };

  if (challenge) {
    const minutes = Math.max(1, Math.round(challenge.expiresIn / 60));
    return (
      <View style={styles.form}>
        {verifyOtp.isError ? <ErrorBanner message={errorMessage(verifyOtp.error)} /> : null}
        {requestOtp.isError ? <ErrorBanner message={errorMessage(requestOtp.error)} /> : null}
        <Notice tone="info" icon="logo-whatsapp" message={`Kode 6 digit dikirim ke WhatsApp ${challenge.destination}. Berlaku ${minutes} menit.`} />
        <Field
          label="Kode OTP"
          value={code}
          onChangeText={(value) => setCode(value.replace(/\D/g, '').slice(0, 6))}
          keyboardType="number-pad"
          autoComplete="one-time-code"
          textContentType="oneTimeCode"
          placeholder="123456"
          maxLength={6}
          autoFocus
          returnKeyType="go"
          onSubmitEditing={() => canVerify && verifyOtp.mutate()}
        />
        <Button title="Verifikasi dan masuk" size="lg" onPress={() => verifyOtp.mutate()} disabled={!canVerify} loading={verifyOtp.isPending} />
        <View style={styles.linkRow}>
          <Button
            title={requestOtp.isPending ? 'Mengirim ulang…' : 'Kirim ulang kode'}
            variant="ghost"
            size="sm"
            icon="refresh-outline"
            onPress={() => requestOtp.mutate()}
            disabled={pending}
          />
          <Button
            title="Ganti nomor"
            variant="ghost"
            size="sm"
            icon="arrow-back-outline"
            onPress={startOver}
            disabled={pending}
          />
        </View>
      </View>
    );
  }

  return (
    <View style={styles.form}>
      {requestOtp.isError ? <ErrorBanner message={errorMessage(requestOtp.error)} /> : null}
      <Field
        label="Nomor WhatsApp"
        value={destination}
        onChangeText={setDestination}
        keyboardType="phone-pad"
        autoComplete="tel"
        textContentType="telephoneNumber"
        placeholder="0812xxxxxxxx"
        hint="Nomor yang tercatat di data karyawan Anda."
        returnKeyType="go"
        onSubmitEditing={() => canRequest && requestOtp.mutate()}
      />
      <Button
        title="Kirim kode ke WhatsApp"
        size="lg"
        onPress={() => requestOtp.mutate()}
        disabled={!canRequest}
        loading={requestOtp.isPending}
      />
    </View>
  );
}

/**
 * Asks for a link by email; the email's button opens the app at /email-login, which finishes the
 * login with the challenge kept here. No code to type.
 */
function EmailLinkForm() {
  const styles = useStyles();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState<{ email: string; expiresIn: number } | null>(null);

  const requestLink = useMutation({
    mutationKey: AUTH_MUTATION_KEY,
    mutationFn: async () => {
      const address = email.trim();
      const response = await authApi.requestMagicLink(address);
      await savePendingMagicLink({ challengeId: response.challenge_id, email: address, expiresAt: Date.now() + response.expires_in * 1000 });
      return { email: address, expiresIn: response.expires_in };
    },
    onSuccess: setSent,
  });

  const canRequest = email.trim().length > 0 && !requestLink.isPending;

  const startOver = () => {
    void clearPendingMagicLink();
    setSent(null);
    requestLink.reset();
  };

  if (sent) {
    const minutes = Math.max(1, Math.round(sent.expiresIn / 60));
    return (
      <View style={styles.form}>
        {requestLink.isError ? <ErrorBanner message={errorMessage(requestLink.error)} /> : null}
        <Notice
          tone="info"
          icon="mail-unread-outline"
          message={`Jika ${sent.email} terdaftar, link masuk sudah dikirim ke email tersebut. Buka email di HP ini lalu ketuk tombol "Masuk". Link berlaku ${minutes} menit.`}
        />
        <View style={styles.linkRow}>
          <Button
            title={requestLink.isPending ? 'Mengirim ulang…' : 'Kirim ulang link'}
            variant="ghost"
            size="sm"
            icon="refresh-outline"
            onPress={() => requestLink.mutate()}
            disabled={requestLink.isPending}
          />
          <Button title="Ganti email" variant="ghost" size="sm" icon="create-outline" onPress={startOver} disabled={requestLink.isPending} />
        </View>
      </View>
    );
  }

  return (
    <View style={styles.form}>
      {requestLink.isError ? <ErrorBanner message={errorMessage(requestLink.error)} /> : null}
      <Field
        label="Email"
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        autoComplete="email"
        autoCorrect={false}
        keyboardType="email-address"
        textContentType="emailAddress"
        placeholder="nama@perusahaan.id"
        hint="Kami kirim link untuk langsung masuk, tanpa kata sandi."
        returnKeyType="go"
        onSubmitEditing={() => canRequest && requestLink.mutate()}
      />
      <Button title="Kirim link masuk" size="lg" icon="paper-plane-outline" onPress={() => requestLink.mutate()} disabled={!canRequest} loading={requestLink.isPending} />
    </View>
  );
}

const useStyles = makeStyles((c, dark) => ({
  container: { flex: 1, paddingHorizontal: spacing.xl, paddingVertical: spacing.xxl, justifyContent: 'center', gap: spacing.xxl },
  hero: { alignItems: 'center', gap: spacing.xs },
  logo: {
    width: 84,
    height: 84,
    borderRadius: 26,
    backgroundColor: c.primary,
    backgroundImage: BRAND_GRADIENT,
    boxShadow: `${BRAND_GLOSS}, 0px 12px 26px rgba(242,41,41,${dark ? 0.4 : 0.32})`,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.md,
  },
  logoMark: { width: 40, height: 48 },
  title: { fontSize: 32, fontWeight: '800', color: c.text, letterSpacing: -0.6 },
  subtitle: { fontSize: fontSize.md, color: c.textMuted },
  form: { gap: spacing.lg },
  modeBusy: { opacity: 0.5, pointerEvents: 'none' },
  alternatives: { gap: spacing.md, alignItems: 'stretch' },
  dividerRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  dividerLine: { flex: 1, height: 1, backgroundColor: c.separator },
  dividerText: { fontSize: fontSize.sm, color: c.textMuted },
  alternativeRow: { flexDirection: 'row', gap: spacing.sm },
  alternativeButton: { flex: 1 },
  linkRow: { flexDirection: 'row', justifyContent: 'space-between', flexWrap: 'wrap', gap: spacing.sm },
  server: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'center',
    gap: 6,
    maxWidth: '90%',
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: radius.pill,
    backgroundColor: c.fill,
  },
  serverPressed: { backgroundColor: c.fillPressed },
  serverText: { flexShrink: 1, fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500' },
}));
