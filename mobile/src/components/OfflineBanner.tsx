import { onlineManager } from '@tanstack/react-query';
import { StatusBar } from 'expo-status-bar';
import { createContext, type ReactNode, useContext, useEffect, useState, useSyncExternalStore } from 'react';
import { View } from 'react-native';
import { type EdgeInsets, SafeAreaInsetsContext, useSafeAreaInsets } from 'react-native-safe-area-context';

import { Text } from '@/components/ui';
import { listenForNetwork } from '@/lib/deviceNetwork';
import { fontSize, spacing } from '@/theme';

/** Deep red that stays readable with white text in both color schemes. */
const OFFLINE_RED = '#A10A0A';

export const OFFLINE_MESSAGE = 'Periksa koneksi internet Anda dan coba lagi';

// React Query pauses queries while offline and refetches when the connection is back. The submit
// guards and the bar below read the same value, so a refresh (see listenForNetwork) fixes all of them.
onlineManager.setEventListener(listenForNetwork);

/** Whether the device has no connection or no internet. Unknown counts as online. */
export function useIsOffline(): boolean {
  const online = useSyncExternalStore(
    (onChange) => onlineManager.subscribe(onChange),
    () => onlineManager.isOnline(),
  );
  return !online;
}

const DeviceInsetsContext = createContext<EdgeInsets | null>(null);

/**
 * The device's real safe area, for full-screen modals that cover the banner and so still need the
 * status bar space themselves.
 */
export function useDeviceInsets(): EdgeInsets {
  const insets = useSafeAreaInsets();
  return useContext(DeviceInsetsContext) ?? insets;
}

/**
 * Shows a red bar behind the status bar while offline and pushes the app down below it. The screens
 * underneath get a top inset of 0, since the bar already covers the status bar area.
 */
export function OfflineBanner({ children }: { children: ReactNode }) {
  const insets = useSafeAreaInsets();
  const offline = useIsOffline();
  // A brief drop while the app starts or switches networks should not flash the bar.
  const [settled, setSettled] = useState(false);

  useEffect(() => {
    if (!offline) {
      return;
    }
    const timer = setTimeout(() => setSettled(true), 800);
    return () => {
      clearTimeout(timer);
      setSettled(false);
    };
  }, [offline]);

  const visible = offline && settled;

  return (
    <DeviceInsetsContext.Provider value={insets}>
      {visible ? (
        <View
          accessibilityRole="alert"
          accessibilityLiveRegion="polite"
          style={{ backgroundColor: OFFLINE_RED, paddingTop: insets.top + spacing.sm, paddingBottom: spacing.md, paddingHorizontal: spacing.lg }}>
          <StatusBar style="light" />
          <Text style={{ color: '#FFFFFF', fontSize: fontSize.md, fontWeight: '500', textAlign: 'center' }}>{OFFLINE_MESSAGE}</Text>
        </View>
      ) : null}
      <SafeAreaInsetsContext.Provider value={visible ? { ...insets, top: 0 } : insets}>
        <View style={{ flex: 1 }}>{children}</View>
      </SafeAreaInsetsContext.Provider>
    </DeviceInsetsContext.Provider>
  );
}
