import { Ionicons } from '@expo/vector-icons';
import { useIsMutating, useMutation } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, View } from 'react-native';

import { Button, ErrorBanner, Field, Notice, Screen, Segmented, Text } from '@/components/ui';
import { authApi, errorMessage } from '@/lib/api';
import type { OtpChannel } from '@/lib/api';
import { useSession } from '@/lib/session';
import { BRAND_GLOSS, BRAND_GRADIENT, fontSize, makeStyles, radius, spacing, useTheme } from '@/theme';

type Mode = 'password' | 'otp';

/** Shared by every sign-in mutation so the mode switch can stay still while one is running. */
const AUTH_MUTATION_KEY = ['auth', 'sign-in'];

const MODES: { value: Mode; label: string }[] = [
  { value: 'password', label: 'Kata sandi' },
  { value: 'otp', label: 'Kode OTP' },
];

const OTP_CHANNELS: { value: OtpChannel; label: string }[] = [
  { value: 'whatsapp', label: 'WhatsApp' },
  { value: 'email', label: 'Email' },
];

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
            <Ionicons name="time" size={42} color={colors.onPrimary} />
          </View>
          <Text style={styles.title}>OnTime</Text>
          <Text style={styles.subtitle}>Presensi dan pengajuan harian</Text>
        </View>

        <View style={styles.form}>
          <View style={busy && styles.modeBusy}>
            <Segmented options={MODES} value={mode} onChange={changeMode} />
          </View>
          {mode === 'password' ? <PasswordForm /> : <OtpForm />}
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

/**
 * Two steps: pick WhatsApp or email and ask for a code, then type the 6-digit code. The server
 * answers the same way whether or not the number or address is registered, so the hint below the
 * code field says so instead of the app guessing.
 */
function OtpForm() {
  const styles = useStyles();
  const [channel, setChannel] = useState<OtpChannel>('whatsapp');
  const [destination, setDestination] = useState('');
  const [code, setCode] = useState('');
  const [challenge, setChallenge] = useState<{ id: string; channel: OtpChannel; destination: string; expiresIn: number } | null>(null);
  const signIn = useSession((state) => state.signIn);

  const requestOtp = useMutation({
    mutationKey: AUTH_MUTATION_KEY,
    mutationFn: () => authApi.requestOtp(channel, destination.trim()),
    onSuccess: (response) => {
      setChallenge({ id: response.challenge_id, channel, destination: destination.trim(), expiresIn: response.expires_in });
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

  const changeChannel = (next: OtpChannel) => {
    setChannel(next);
    setDestination('');
    requestOtp.reset();
  };

  const startOver = () => {
    setChallenge(null);
    setCode('');
    requestOtp.reset();
    verifyOtp.reset();
  };

  if (challenge) {
    const minutes = Math.max(1, Math.round(challenge.expiresIn / 60));
    const sentTo = challenge.channel === 'whatsapp' ? `WhatsApp ${challenge.destination}` : challenge.destination;
    return (
      <View style={styles.form}>
        {verifyOtp.isError ? <ErrorBanner message={errorMessage(verifyOtp.error)} /> : null}
        {requestOtp.isError ? <ErrorBanner message={errorMessage(requestOtp.error)} /> : null}
        <Notice
          tone="info"
          icon={challenge.channel === 'whatsapp' ? 'logo-whatsapp' : 'mail-outline'}
          message={
            challenge.channel === 'whatsapp'
              ? `Kode 6 digit dikirim ke ${sentTo}. Berlaku ${minutes} menit.`
              : `Jika ${sentTo} terdaftar, kode 6 digit sudah dikirim ke email tersebut. Berlaku ${minutes} menit.`
          }
        />
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
            title={challenge.channel === 'whatsapp' ? 'Ganti nomor' : 'Ganti email'}
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
      <View style={styles.channel}>
        <Text style={styles.channelLabel}>Kirim kode lewat</Text>
        <Segmented options={OTP_CHANNELS} value={channel} onChange={changeChannel} />
      </View>
      {channel === 'whatsapp' ? (
        <Field
          key="phone"
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
      ) : (
        <Field
          key="email"
          label="Email"
          value={destination}
          onChangeText={setDestination}
          autoCapitalize="none"
          autoComplete="email"
          autoCorrect={false}
          keyboardType="email-address"
          textContentType="emailAddress"
          placeholder="nama@perusahaan.id"
          returnKeyType="go"
          onSubmitEditing={() => canRequest && requestOtp.mutate()}
        />
      )}
      <Button
        title={channel === 'whatsapp' ? 'Kirim kode ke WhatsApp' : 'Kirim kode ke email'}
        size="lg"
        onPress={() => requestOtp.mutate()}
        disabled={!canRequest}
        loading={requestOtp.isPending}
      />
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
  title: { fontSize: 32, fontWeight: '800', color: c.text, letterSpacing: -0.6 },
  subtitle: { fontSize: fontSize.md, color: c.textMuted },
  form: { gap: spacing.lg },
  modeBusy: { opacity: 0.5, pointerEvents: 'none' },
  channel: { gap: spacing.sm },
  channelLabel: { fontSize: fontSize.sm, fontWeight: '500', color: c.textMuted, paddingHorizontal: 4 },
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
