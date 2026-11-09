import { router, useRootNavigationState } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { Alert } from 'react-native';

import { useAccess } from '@/hooks/useAccess';
import {
  hasPushPermission,
  identifyPushUser,
  isRegisteredId,
  onPushClick,
  onPushInForeground,
  onPushSubscriptionChange,
  pushSubscriptionId,
  requestPushPermission,
  wasPermissionAsked,
} from '@/lib/onesignal';
import { pushTarget } from '@/lib/push';
import { queryClient } from '@/lib/queryClient';
import { useSession } from '@/lib/session';

/**
 * Push through OneSignal while signed in: links the device to the user, asks for permission once
 * (after explaining why), refreshes lists when a push arrives in the foreground, and opens the
 * related screen when a push is tapped (also from a cold start).
 */
export function usePushNotifications(): void {
  const status = useSession((state) => state.status);
  const userId = useSession((state) => state.user?.id ?? null);
  const { canApprove, canManageTeam } = useAccess();
  const navigationReady = Boolean(useRootNavigationState()?.key);

  // The tapped push waits here until the navigator exists and the user is signed in.
  const pendingData = useRef<unknown>(null);
  const [tapCount, setTapCount] = useState(0);
  const asking = useRef(false);

  // Link the device to the user, then explain and ask for permission once the device is registered.
  useEffect(() => {
    if (status !== 'signedIn' || userId === null) {
      return;
    }
    identifyPushUser(userId);

    const askOnce = async (subscriptionId: string | null | undefined) => {
      if (asking.current || !isRegisteredId(subscriptionId) || (await hasPushPermission()) || (await wasPermissionAsked())) {
        return;
      }
      asking.current = true;
      Alert.alert(
        'Aktifkan notifikasi',
        'OnTime akan mengingatkan jadwal shift, absen masuk dan pulang, serta kabar pengajuan Anda.',
        [{ text: 'Aktifkan', onPress: () => void requestPushPermission().finally(() => (asking.current = false)) }],
        { cancelable: false },
      );
    };

    const stop = onPushSubscriptionChange((event) => void askOnce(event.current.id));
    void pushSubscriptionId().then(askOnce);
    return stop;
  }, [status, userId]);

  useEffect(() => {
    const stopForeground = onPushInForeground(() => {
      void queryClient.invalidateQueries({ queryKey: ['team'] });
      void queryClient.invalidateQueries({ queryKey: ['approvals'] });
      void queryClient.invalidateQueries({ queryKey: ['requests'] });
      void queryClient.invalidateQueries({ queryKey: ['attendance'] });
    });
    const stopClick = onPushClick((data) => {
      pendingData.current = data;
      setTapCount((count) => count + 1);
    });
    return () => {
      stopForeground();
      stopClick();
    };
  }, []);

  useEffect(() => {
    const data = pendingData.current;
    if (data === null || !navigationReady || status !== 'signedIn') {
      return;
    }
    pendingData.current = null;
    const target = pushTarget(data, canApprove, canManageTeam);
    if (target) {
      router.push(target);
    }
  }, [tapCount, navigationReady, status, canApprove, canManageTeam]);
}
