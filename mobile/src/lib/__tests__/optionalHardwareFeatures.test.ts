// eslint-disable-next-line @typescript-eslint/no-require-imports
const { markFeaturesOptional, OPTIONAL_FEATURES } = require('../../../plugins/withOptionalHardwareFeatures');

type Feature = { $: Record<string, string> };

describe('markFeaturesOptional', () => {
  it('adds portrait and wifi as optional features when the manifest has none', () => {
    const manifest = markFeaturesOptional({});

    expect(manifest['uses-feature']).toEqual([
      { $: { 'android:name': 'android.hardware.screen.portrait', 'android:required': 'false' } },
      { $: { 'android:name': 'android.hardware.wifi', 'android:required': 'false' } },
    ]);
  });

  it('turns an existing required entry optional without duplicating it', () => {
    const manifest = markFeaturesOptional({
      'uses-feature': [{ $: { 'android:name': 'android.hardware.wifi', 'android:required': 'true' } }],
    });

    const wifi = manifest['uses-feature'].filter((feature: Feature) => feature.$['android:name'] === 'android.hardware.wifi');
    expect(wifi).toEqual([{ $: { 'android:name': 'android.hardware.wifi', 'android:required': 'false' } }]);
    expect(manifest['uses-feature']).toHaveLength(OPTIONAL_FEATURES.length);
  });

  it('keeps unrelated features as they are', () => {
    const camera = { $: { 'android:name': 'android.hardware.camera', 'android:required': 'true' } };
    const manifest = markFeaturesOptional({ 'uses-feature': [camera] });

    expect(manifest['uses-feature'][0]).toEqual(camera);
    expect(manifest['uses-feature']).toHaveLength(1 + OPTIONAL_FEATURES.length);
  });
});
