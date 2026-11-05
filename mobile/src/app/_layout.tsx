import { QueryClientProvider } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';

import { LoadingView } from '@/components/ui';
import { usePushNotifications } from '@/hooks/usePushNotifications';
import { queryClient } from '@/lib/queryClient';
import { useSession } from '@/lib/session';
import { colors } from '@/theme';

export default function RootLayout() {
  const status = useSession((state) => state.status);
  const hydrate = useSession((state) => state.hydrate);

  usePushNotifications();

  useEffect(() => {
    void hydrate();
  }, [hydrate]);

  useEffect(() => {
    if (status === 'signedOut') {
      queryClient.clear();
    }
  }, [status]);

  if (status === 'loading') {
    return <LoadingView />;
  }

  const signedIn = status === 'signedIn';

  return (
    <QueryClientProvider client={queryClient}>
      <StatusBar style="dark" />
      <Stack
        screenOptions={{
          headerTintColor: colors.primary,
          headerTitleStyle: { color: colors.text, fontWeight: '600' },
          headerStyle: { backgroundColor: colors.surface },
          headerShadowVisible: false,
          headerBackButtonDisplayMode: 'minimal',
          contentStyle: { backgroundColor: colors.background },
        }}>
        <Stack.Protected guard={!signedIn}>
          <Stack.Screen name="login" options={{ headerShown: false }} />
        </Stack.Protected>

        <Stack.Protected guard={signedIn}>
          <Stack.Screen name="(app)" options={{ headerShown: false }} />
          <Stack.Screen
            name="attendance/check"
            options={{
              presentation: 'fullScreenModal',
              gestureEnabled: false,
              headerStyle: { backgroundColor: colors.primaryDark },
              headerTintColor: colors.textOnPrimary,
              headerTitleStyle: { color: colors.textOnPrimary, fontWeight: '700' },
              contentStyle: { backgroundColor: colors.primaryDark },
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
        </Stack.Protected>

        <Stack.Screen name="settings/server" options={{ title: 'Alamat Server', presentation: 'modal' }} />
      </Stack>
    </QueryClientProvider>
  );
}
