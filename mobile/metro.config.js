// Sentry's Expo Metro config: the default Expo config plus debug IDs on bundles and source maps,
// so stack traces in Sentry point at the original TypeScript.
const { getSentryExpoConfig } = require('@sentry/react-native/metro');

const config = getSentryExpoConfig(__dirname);

module.exports = config;
