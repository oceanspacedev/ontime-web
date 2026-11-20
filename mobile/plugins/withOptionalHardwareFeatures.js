const { withAndroidManifest } = require('expo/config-plugins');

/**
 * Hardware features Android infers as required, though OnTime works without them:
 * - android.hardware.screen.portrait, from the portrait-only orientation (app.json "orientation");
 * - android.hardware.wifi, from ACCESS_WIFI_STATE, which a library adds; the app also runs on mobile data.
 * Declaring them optional keeps Play from hiding the app on devices that lack them (Play pre-review check).
 */
const OPTIONAL_FEATURES = ['android.hardware.screen.portrait', 'android.hardware.wifi'];

/**
 * Adds `<uses-feature android:required="false">` for each optional feature, or sets an existing entry to optional.
 *
 * @param {{ 'uses-feature'?: Array<{ $: Record<string, string> }> }} manifest the parsed `<manifest>` element
 * @returns the same manifest
 */
function markFeaturesOptional(manifest) {
  const features = manifest['uses-feature'] ?? [];

  for (const name of OPTIONAL_FEATURES) {
    const existing = features.find((feature) => feature.$?.['android:name'] === name);

    if (existing) {
      existing.$['android:required'] = 'false';
    } else {
      features.push({ $: { 'android:name': name, 'android:required': 'false' } });
    }
  }

  manifest['uses-feature'] = features;

  return manifest;
}

const withOptionalHardwareFeatures = (config) =>
  withAndroidManifest(config, (modConfig) => {
    markFeaturesOptional(modConfig.modResults.manifest);

    return modConfig;
  });

module.exports = withOptionalHardwareFeatures;
module.exports.markFeaturesOptional = markFeaturesOptional;
module.exports.OPTIONAL_FEATURES = OPTIONAL_FEATURES;
