import {
  PlusJakartaSans_400Regular,
  PlusJakartaSans_500Medium,
  PlusJakartaSans_600SemiBold,
  PlusJakartaSans_700Bold,
  PlusJakartaSans_800ExtraBold,
  useFonts,
} from '@expo-google-fonts/plus-jakarta-sans';
import { QueryClientProvider } from '@tanstack/react-query';
import { DarkTheme, DefaultTheme, SplashScreen, Stack, ThemeProvider } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useMemo } from 'react';
import { View } from 'react-native';

import { StackHeader } from '@/components/navigation';
import { LoadingView } from '@/components/ui';
import { usePushNotifications } from '@/hooks/usePushNotifications';
import { queryClient } from '@/lib/queryClient';
import { useSession } from '@/lib/session';
import { useAppearance, useTheme } from '@/theme';

void SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const status = useSession((state) => state.status);
  const hydrate = useSession((state) => state.hydrate);
  const hydrateAppearance = useAppearance((state) => state.hydrate);
  const { colors, dark } = useTheme();
  const [fontsLoaded, fontError] = useFonts({
    PlusJakartaSans_400Regular,
    PlusJakartaSans_500Medium,
    PlusJakartaSans_600SemiBold,
    PlusJakartaSans_700Bold,
    PlusJakartaSans_800ExtraBold,
  });

  usePushNotifications();

  useEffect(() => {
    void hydrate();
    void hydrateAppearance();
  }, [hydrate, hydrateAppearance]);

  useEffect(() => {
    if (status === 'signedOut') {
      queryClient.clear();
    }
  }, [status]);

  const ready = status !== 'loading' && (fontsLoaded || Boolean(fontError));

  useEffect(() => {
    if (ready) {
      void SplashScreen.hideAsync();
    }
  }, [ready]);

  const navigationTheme = useMemo(() => {
    const base = dark ? DarkTheme : DefaultTheme;
    return {
      ...base,
      colors: {
        ...base.colors,
        primary: colors.primary,
        background: colors.background,
        card: colors.background,
        text: colors.text,
        border: colors.separator,
        notification: colors.primary,
      },
    };
  }, [colors, dark]);

  if (!ready) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.background }}>
        <LoadingView />
      </View>
    );
  }

  const signedIn = status === 'signedIn';

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider value={navigationTheme}>
        <StatusBar style={dark ? 'light' : 'dark'} />
        <Stack
          screenOptions={{
            header: ({ options, back }) => <StackHeader title={options.title} canGoBack={Boolean(back)} right={options.headerRight?.({ canGoBack: Boolean(back) })} />,
            contentStyle: { backgroundColor: colors.background },
          }}>
          <Stack.Protected guard={!signedIn}>
            <Stack.Screen name="login" options={{ headerShown: false }} />
            <Stack.Screen name="email-login" options={{ headerShown: false, animation: 'fade' }} />
          </Stack.Protected>

          <Stack.Protected guard={signedIn}>
            <Stack.Screen name="(app)" options={{ headerShown: false }} />
            <Stack.Screen
              name="attendance/check"
              options={{
                presentation: 'fullScreenModal',
                gestureEnabled: false,
                headerShown: false,
              }}
            />
            <Stack.Screen name="attendance/master-face" options={{ title: 'Foto Master Wajah' }} />
            <Stack.Screen name="attendance/[id]" options={{ title: 'Detail Presensi' }} />
            <Stack.Screen name="calendar" options={{ title: 'Kalender Cuti Tim' }} />
            <Stack.Screen name="request/leave/new" options={{ title: 'Ajukan Cuti / Izin' }} />
            <Stack.Screen name="request/overtime/new" options={{ title: 'Ajukan Lembur' }} />
            <Stack.Screen name="request/correction/new" options={{ title: 'Ajukan Koreksi Absensi' }} />
            <Stack.Screen name="request/resignation/new" options={{ title: 'Pengunduran Diri' }} />
            <Stack.Screen name="request/[type]/[id]" options={{ title: 'Detail Pengajuan' }} />
            <Stack.Screen name="approval/[type]/[id]" options={{ title: 'Proses Persetujuan' }} />
            <Stack.Screen name="team/assign" options={{ title: 'Jadwalkan Pekerja' }} />
            <Stack.Screen name="team/freelancers" options={{ title: 'Freelancer' }} />
            <Stack.Screen name="team/freelancer/new" options={{ title: 'Tambah Freelancer' }} />
            <Stack.Screen name="settings/appearance" options={{ title: 'Tampilan' }} />
          </Stack.Protected>

          <Stack.Screen
            name="settings/server"
            options={{
              title: 'Alamat Server',
              presentation: 'modal',
              header: ({ options }) => <StackHeader title={options.title} canGoBack={false} modal />,
              contentStyle: { backgroundColor: colors.background },
            }}
          />
        </Stack>
      </ThemeProvider>
    </QueryClientProvider>
  );
}
