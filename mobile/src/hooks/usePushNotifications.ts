import * as Notifications from 'expo-notifications';
import { router, useRootNavigationState } from 'expo-router';
import { useEffect, useRef, useState } from 'react';

import { useAccess } from '@/hooks/useAccess';
import { pushTarget, registerPushToken } from '@/lib/push';
import { queryClient } from '@/lib/queryClient';
import { useSession } from '@/lib/session';

/**
 * Registers the device for push while signed in, refreshes lists when a push arrives in the
 * foreground, and opens the related screen when a push is tapped (also from a cold start).
 */
export function usePushNotifications(): void {
  const status = useSession((state) => state.status);
  const userId = useSession((state) => state.user?.id ?? null);
  const { canApprove } = useAccess();
  const navigationReady = Boolean(useRootNavigationState()?.key);

  const registeredFor = useRef<string | null>(null);
  // The tapped push waits here until the navigator exists and the user is signed in.
  const pendingResponse = useRef<Notifications.NotificationResponse | null>(null);
  const [responseCount, setResponseCount] = useState(0);

  useEffect(() => {
    if (status !== 'signedIn' || userId === null) {
      registeredFor.current = null;
      return;
    }
    const key = String(userId);
    if (registeredFor.current === key) {
      return;
    }
    registeredFor.current = key;
    void registerPushToken();
  }, [status, userId]);

  useEffect(() => {
    const queueResponse = (response: Notifications.NotificationResponse) => {
      pendingResponse.current = response;
      setResponseCount((count) => count + 1);
    };

    const received = Notifications.addNotificationReceivedListener(() => {
      void queryClient.invalidateQueries({ queryKey: ['approvals'] });
      void queryClient.invalidateQueries({ queryKey: ['requests'] });
      void queryClient.invalidateQueries({ queryKey: ['attendance'] });
    });
    const responded = Notifications.addNotificationResponseReceivedListener(queueResponse);

    // The tap that launched the app happens before any listener is attached.
    void Notifications.getLastNotificationResponseAsync().then((response) => {
      if (response && !pendingResponse.current) {
        queueResponse(response);
      }
    });

    return () => {
      received.remove();
      responded.remove();
    };
  }, []);

  useEffect(() => {
    const response = pendingResponse.current;
    if (!response || !navigationReady || status !== 'signedIn') {
      return;
    }
    pendingResponse.current = null;
    void Notifications.clearLastNotificationResponseAsync();
    const target = pushTarget(response.notification.request.content.data, canApprove);
    if (target) {
      router.push(target);
    }
  }, [responseCount, navigationReady, status, canApprove]);
}
