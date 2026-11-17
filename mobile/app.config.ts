import type { ConfigContext, ExpoConfig } from 'expo/config';

/**
 * Picks the OneSignal app a build registers with. Production (EXPO_PUBLIC_APP_ENV=production, set by the
 * production profile in eas.json) keeps the id in app.json. Every other build (preview, development, a
 * local dev server) uses EXPO_PUBLIC_ONESIGNAL_APP_ID or nothing, so test devices never subscribe to the
 * production app and never get production pushes. Without an id the app turns push off (src/lib/onesignal.ts).
 */
export function oneSignalAppIdFor(env: { appEnv?: string; oneSignalAppId?: string }, productionAppId: unknown): string | null {
  if (env.appEnv === 'production') {
    return typeof productionAppId === 'string' && productionAppId.length > 0 ? productionAppId : null;
  }
  const id = env.oneSignalAppId?.trim();
  return id ? id : null;
}

/** app.json plus the OneSignal id for this build; the key is left out when the build has no id. */
export default ({ config }: ConfigContext): ExpoConfig => {
  const { oneSignalAppId: productionAppId, ...extra } = config.extra ?? {};
  const oneSignalAppId = oneSignalAppIdFor(
    { appEnv: process.env.EXPO_PUBLIC_APP_ENV, oneSignalAppId: process.env.EXPO_PUBLIC_ONESIGNAL_APP_ID },
    productionAppId,
  );
  return { ...(config as ExpoConfig), extra: oneSignalAppId ? { ...extra, oneSignalAppId } : extra };
};
