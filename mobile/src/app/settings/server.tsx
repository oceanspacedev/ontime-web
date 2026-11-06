import { router } from 'expo-router';
import { useState } from 'react';
import { Platform, View } from 'react-native';

import { Button, Choice, Field, Group, Notice, Screen, Text, useTextStyles } from '@/components/ui';
import { buildUrl } from '@/lib/api/client';
import { DEFAULT_BASE_URL, normalizeBaseUrl, useSession } from '@/lib/session';
import { makeStyles, spacing } from '@/theme';

type Preset = 'default' | 'ios' | 'android' | 'custom';

const PRESETS: { value: Preset; label: string; url: string | null }[] = [
  { value: 'default', label: 'Bawaan', url: DEFAULT_BASE_URL },
  { value: 'ios', label: 'iOS Simulator', url: 'http://127.0.0.1:8000/api/v1' },
  { value: 'android', label: 'Android Emulator', url: 'http://10.0.2.2:8000/api/v1' },
  { value: 'custom', label: 'Lainnya', url: null },
];

export default function ServerSettingsScreen() {
  const styles = useStyles();
  const t = useTextStyles();
  const baseUrl = useSession((state) => state.baseUrl);
  const setBaseUrl = useSession((state) => state.setBaseUrl);
  const signedIn = useSession((state) => state.status === 'signedIn');
  const [value, setValue] = useState(baseUrl);
  const [preset, setPreset] = useState<Preset>(() => PRESETS.find((item) => item.url === baseUrl)?.value ?? 'custom');
  const [testing, setTesting] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
  // Saving another server ends the session (see setBaseUrl), so say so before it happens.
  const signsOut = signedIn && value.trim().length > 0 && normalizeBaseUrl(value) !== baseUrl;

  const choosePreset = (next: Preset) => {
    setPreset(next);
    const url = PRESETS.find((item) => item.value === next)?.url;
    if (url) {
      setValue(url);
    }
    setResult(null);
  };

  const testConnection = async () => {
    setTesting(true);
    setResult(null);
    const target = normalizeBaseUrl(value);
    try {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 10000);
      const response = await fetch(buildUrl(target, '/auth/me'), {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });
      clearTimeout(timer);
      const contentType = response.headers.get('content-type') ?? '';
      if (response.status === 401 && contentType.includes('application/json')) {
        setResult({ ok: true, message: 'Server OnTime ditemukan dan siap dipakai.' });
      } else {
        setResult({ ok: false, message: `Alamat merespons (${response.status}) tetapi bukan API OnTime.` });
      }
    } catch {
      setResult({ ok: false, message: 'Server tidak bisa dihubungi. Periksa alamat dan jaringan.' });
    } finally {
      setTesting(false);
    }
  };

  const save = async () => {
    await setBaseUrl(value);
    router.back();
  };

  return (
    <Screen>
      <Group title="Alamat server" padded>
        <Text style={[t.muted, styles.description]}>
          Masukkan alamat OnTime, misalnya https://ontime.perusahaan.id. Akhiran /api/v1 ditambahkan otomatis.
        </Text>
        <Choice options={PRESETS.map(({ value: presetValue, label }) => ({ value: presetValue, label }))} value={preset} onChange={choosePreset} />
        <Field
          label="URL"
          value={value}
          onChangeText={(next) => {
            setValue(next);
            setPreset('custom');
            setResult(null);
          }}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="url"
          placeholder="https://ontime.perusahaan.id"
        />
        {Platform.OS === 'android' && /127\.0\.0\.1|localhost/.test(value) ? (
          <Notice
            tone="info"
            message="Di Android Emulator, komputer pengembang diakses lewat 10.0.2.2. Di HP yang tersambung USB, 127.0.0.1 hanya bisa dipakai bila portnya diteruskan dengan adb reverse."
          />
        ) : null}
        {result ? <Notice tone={result.ok ? 'success' : 'danger'} message={result.message} /> : null}
        <View style={styles.actions}>
          <Button title="Tes koneksi" variant="secondary" onPress={testConnection} loading={testing} style={styles.action} />
          <Button title="Simpan" onPress={save} disabled={value.trim().length === 0} style={styles.action} />
        </View>
      </Group>
      {signsOut ? <Notice tone="warning" message="Anda akan keluar dari akun ini. Sesi hanya berlaku di server yang menerbitkannya, jadi masuk lagi setelah alamat diganti." /> : null}
    </Screen>
  );
}

const useStyles = makeStyles(() => ({
  description: { lineHeight: 20 },
  actions: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.xs },
  action: { flex: 1 },
}));
