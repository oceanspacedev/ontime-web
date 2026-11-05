import { router } from 'expo-router';
import { useState } from 'react';
import { Platform, StyleSheet, Text, View } from 'react-native';

import { Button, Card, Choice, Field, Notice, Screen, text } from '@/components/ui';
import { buildUrl } from '@/lib/api/client';
import { DEFAULT_BASE_URL, normalizeBaseUrl, useSession } from '@/lib/session';
import { spacing } from '@/theme';

type Preset = 'default' | 'ios' | 'android' | 'custom';

const PRESETS: { value: Preset; label: string; url: string | null }[] = [
  { value: 'default', label: 'Bawaan', url: DEFAULT_BASE_URL },
  { value: 'ios', label: 'iOS Simulator', url: 'http://127.0.0.1:8000/api/v1' },
  { value: 'android', label: 'Android Emulator', url: 'http://10.0.2.2:8000/api/v1' },
  { value: 'custom', label: 'Lainnya', url: null },
];

export default function ServerSettingsScreen() {
  const baseUrl = useSession((state) => state.baseUrl);
  const setBaseUrl = useSession((state) => state.setBaseUrl);
  const signedIn = useSession((state) => state.status === 'signedIn');
  const [value, setValue] = useState(baseUrl);
  const [preset, setPreset] = useState<Preset>(() => PRESETS.find((item) => item.url === baseUrl)?.value ?? 'custom');
  const [testing, setTesting] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);

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
      <Card>
        <Text style={text.strong}>Alamat server</Text>
        <Text style={text.muted}>
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
          <Notice tone="warning" message="Di Android Emulator, komputer pengembang diakses lewat 10.0.2.2, bukan 127.0.0.1." />
        ) : null}
        {result ? <Notice tone={result.ok ? 'success' : 'danger'} message={result.message} /> : null}
        <View style={styles.actions}>
          <Button title="Tes koneksi" variant="secondary" onPress={testConnection} loading={testing} style={styles.action} />
          <Button title="Simpan" onPress={save} disabled={value.trim().length === 0} style={styles.action} />
        </View>
      </Card>
      {signedIn ? (
        <Notice tone="warning" message="Mengganti server saat masih masuk bisa membuat token tidak dikenali. Keluar dulu bila berpindah server." />
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  actions: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.sm },
  action: { flex: 1 },
});
