import { Ionicons } from '@expo/vector-icons';
import { useMutation } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, View } from 'react-native';

import { Button, Choice, ErrorBanner, Field, Notice, Screen } from '@/components/ui';
import { authApi, errorMessage } from '@/lib/api';
import type { OtpChannel } from '@/lib/api';
import { useSession } from '@/lib/session';
import { colors, fontSize, radius, spacing } from '@/theme';

type Mode = 'password' | 'otp';

const OTP_CHANNELS: { value: OtpChannel; label: string }[] = [
  { value: 'whatsapp', label: 'WhatsApp' },
  { value: 'email', label: 'Email' },
];

export default function LoginScreen() {
  const [mode, setMode] = useState<Mode>('password');
  const baseUrl = useSession((state) => state.baseUrl);
  const serverLabel = baseUrl.replace(/^https?:\/\//, '').replace(/\/api\/v1$/, '');

  return (
    <Screen edges={['top', 'bottom']} padded={false}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.container}>
        <View style={styles.hero}>
          <View style={styles.logo}>
            <Ionicons name="time" size={40} color={colors.textOnPrimary} />
          </View>
          <Text style={styles.title}>OnTime</Text>
          <Text style={styles.subtitle}>Presensi dan pengajuan harian</Text>
        </View>

        {mode === 'password' ? (
          <PasswordForm onUseOtp={() => setMode('otp')} />
        ) : (
          <OtpForm onUsePassword={() => setMode('password')} />
        )}

        <Pressable style={styles.server} onPress={() => router.push('/settings/server')} hitSlop={8}>
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

function PasswordForm({ onUseOtp }: { onUseOtp: () => void }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const signIn = useSession((state) => state.signIn);

  const login = useMutation({
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
      <View>
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
        />
        <Pressable style={styles.eye} onPress={() => setShowPassword((value) => !value)} hitSlop={8}>
          <Ionicons name={showPassword ? 'eye-off-outline' : 'eye-outline'} size={20} color={colors.textMuted} />
        </Pressable>
      </View>
      <Button title="Masuk" size="lg" onPress={() => login.mutate()} disabled={!canSubmit} loading={login.isPending} />
      <Pressable style={styles.switchLink} onPress={onUseOtp} hitSlop={8} disabled={login.isPending}>
        <Ionicons name="key-outline" size={16} color={colors.primary} />
        <Text style={styles.switchLinkText}>Masuk dengan kode OTP</Text>
      </Pressable>
    </View>
  );
}

/**
 * Two steps: pick WhatsApp or email and ask for a code, then type the 6-digit code. The server
 * answers the same way whether or not the number or address is registered, so the hint below the
 * code field says so instead of the app guessing.
 */
function OtpForm({ onUsePassword }: { onUsePassword: () => void }) {
  const [channel, setChannel] = useState<OtpChannel>('whatsapp');
  const [destination, setDestination] = useState('');
  const [code, setCode] = useState('');
  const [challenge, setChallenge] = useState<{ id: string; channel: OtpChannel; destination: string; expiresIn: number } | null>(null);
  const signIn = useSession((state) => state.signIn);

  const requestOtp = useMutation({
    mutationFn: () => authApi.requestOtp(channel, destination.trim()),
    onSuccess: (response) => {
      setChallenge({ id: response.challenge_id, channel, destination: destination.trim(), expiresIn: response.expires_in });
      setCode('');
    },
  });

  const verifyOtp = useMutation({
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
          <Pressable style={styles.switchLink} onPress={() => requestOtp.mutate()} hitSlop={8} disabled={pending}>
            <Ionicons name="refresh-outline" size={16} color={colors.primary} />
            <Text style={styles.switchLinkText}>{requestOtp.isPending ? 'Mengirim ulang…' : 'Kirim ulang kode'}</Text>
          </Pressable>
          <Pressable style={styles.switchLink} onPress={startOver} hitSlop={8} disabled={pending}>
            <Ionicons name="arrow-back-outline" size={16} color={colors.primary} />
            <Text style={styles.switchLinkText}>{challenge.channel === 'whatsapp' ? 'Ganti nomor' : 'Ganti email'}</Text>
          </Pressable>
        </View>
      </View>
    );
  }

  return (
    <View style={styles.form}>
      {requestOtp.isError ? <ErrorBanner message={errorMessage(requestOtp.error)} /> : null}
      <View style={styles.channel}>
        <Text style={styles.channelLabel}>Kirim kode lewat</Text>
        <Choice options={OTP_CHANNELS} value={channel} onChange={changeChannel} />
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
      <Pressable style={styles.switchLink} onPress={onUsePassword} hitSlop={8} disabled={pending}>
        <Ionicons name="lock-closed-outline" size={16} color={colors.primary} />
        <Text style={styles.switchLinkText}>Masuk dengan kata sandi</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: spacing.xl, justifyContent: 'center', gap: spacing.xxl },
  hero: { alignItems: 'center', gap: spacing.sm },
  logo: {
    width: 80,
    height: 80,
    borderRadius: radius.lg,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.sm,
  },
  title: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.text },
  subtitle: { fontSize: fontSize.md, color: colors.textMuted },
  form: { gap: spacing.lg },
  eye: { position: 'absolute', right: spacing.md, top: 34 },
  channel: { gap: spacing.sm },
  channelLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textMuted },
  linkRow: { flexDirection: 'row', justifyContent: 'space-between', flexWrap: 'wrap', gap: spacing.md },
  switchLink: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, paddingVertical: spacing.xs },
  switchLinkText: { fontSize: fontSize.sm, fontWeight: '600', color: colors.primary },
  server: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 },
  serverText: { fontSize: fontSize.sm, color: colors.textMuted, maxWidth: '80%' },
});
