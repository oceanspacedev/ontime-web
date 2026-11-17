/// <reference types="jest" />
import { oneSignalAppIdFor } from '../../../app.config';

const PRODUCTION_ID = '37e96a62-f9fc-45d5-8159-1f079b3f80f5';

describe('oneSignalAppIdFor', () => {
  it('keeps the app.json id for the production profile', () => {
    expect(oneSignalAppIdFor({ appEnv: 'production' }, PRODUCTION_ID)).toBe(PRODUCTION_ID);
  });

  it('ignores the env override in production, so the store build always uses the production app', () => {
    expect(oneSignalAppIdFor({ appEnv: 'production', oneSignalAppId: 'staging-app' }, PRODUCTION_ID)).toBe(PRODUCTION_ID);
  });

  it('never gives a preview or development build the production app', () => {
    expect(oneSignalAppIdFor({ appEnv: 'preview' }, PRODUCTION_ID)).toBeNull();
    expect(oneSignalAppIdFor({ appEnv: 'development' }, PRODUCTION_ID)).toBeNull();
    expect(oneSignalAppIdFor({}, PRODUCTION_ID)).toBeNull();
    expect(oneSignalAppIdFor({ appEnv: 'preview', oneSignalAppId: '  ' }, PRODUCTION_ID)).toBeNull();
  });

  it('uses EXPO_PUBLIC_ONESIGNAL_APP_ID outside production', () => {
    expect(oneSignalAppIdFor({ appEnv: 'preview', oneSignalAppId: 'staging-app' }, PRODUCTION_ID)).toBe('staging-app');
  });
});
