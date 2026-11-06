import * as Sentry from '@sentry/react-native';
import Constants from 'expo-constants';
import { isRunningInExpoGo } from 'expo';

/**
 * Error monitoring and tracing (Sentry project ontime-mobile). Only the OnTime user id is attached
 * to events: no IP address or other personal data (sendDefaultPii stays off for an HR app).
 */
export function initSentry(): void {
  const dsn = Constants.expoConfig?.extra?.sentryDsn;
  if (typeof dsn !== 'string' || dsn.length === 0) {
    return;
  }

  Sentry.init({
    dsn,
    environment: __DEV__ ? 'development' : 'production',
    sendDefaultPii: false,
    // Every transaction while developing; a sample in production to keep volume down.
    tracesSampleRate: __DEV__ ? 1.0 : 0.2,
    integrations: [Sentry.expoRouterIntegration({ enableTimeToInitialDisplay: !isRunningInExpoGo() })],
    enableNativeFramesTracking: !isRunningInExpoGo(),
  });
}

/** Tag events with the signed-in user, or clear it on sign-out. */
export function setSentryUser(userId: number | null): void {
  Sentry.setUser(userId === null ? null : { id: String(userId) });
}

export const wrapRoot = Sentry.wrap;
