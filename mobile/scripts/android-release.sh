#!/bin/bash
# Builds the signed Android App Bundle (.aab) for the Play Store.
#
#   scripts/android-release.sh create-key        one time: creates the upload key (asks for the passwords)
#   scripts/android-release.sh build [git-ref]   builds the bundle from a committed ref (default: HEAD)
#
# The build runs in a temporary copy, so the working tree and the committed native files stay untouched.
# Passwords are asked silently, or read from ONTIME_UPLOAD_STORE_PASSWORD / ONTIME_UPLOAD_KEY_PASSWORD.
# Keep the key file and its passwords somewhere safe: Play only accepts updates signed with this upload key.
set -euo pipefail

KEYSTORE="${ONTIME_UPLOAD_STORE_FILE:-$HOME/.android/ontime-upload.jks}"
ALIAS="${ONTIME_UPLOAD_KEY_ALIAS:-upload}"
export ANDROID_HOME="${ANDROID_HOME:-$HOME/Library/Android/sdk}"
if [ -z "${JAVA_HOME:-}" ]; then
  if [ -d "/Applications/Android Studio.app/Contents/jbr/Contents/Home" ]; then
    export JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home"
  else
    JAVA_HOME="$(/usr/libexec/java_home 2>/dev/null || true)"; export JAVA_HOME
  fi
fi
KEYTOOL="$JAVA_HOME/bin/keytool"
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(git -C "$HERE" rev-parse --show-toplevel)"

ask_secret() { # name, prompt
  local name="$1" prompt="$2" value
  if [ -z "${!name:-}" ]; then
    read -r -s -p "$prompt" value; echo
    export "$name=$value"
  fi
}

fingerprints() {
  "$KEYTOOL" -list -v -keystore "$KEYSTORE" -alias "$ALIAS" -storepass "$ONTIME_UPLOAD_STORE_PASSWORD" 2>/dev/null | grep -E "SHA1:|SHA256:" | sed 's/^ *//'
}

case "${1:-}" in
  create-key)
    if [ -e "$KEYSTORE" ]; then echo "Key already exists: $KEYSTORE (not overwritten)"; exit 1; fi
    mkdir -p "$(dirname "$KEYSTORE")"
    ask_secret ONTIME_UPLOAD_STORE_PASSWORD "Choose a password for the key file (min. 6 characters): "
    "$KEYTOOL" -genkeypair -storetype PKCS12 -keystore "$KEYSTORE" -alias "$ALIAS" \
      -keyalg RSA -keysize 2048 -validity 10000 -dname "CN=OnTime, O=Oceanspace, C=ID" \
      -storepass "$ONTIME_UPLOAD_STORE_PASSWORD" -keypass "$ONTIME_UPLOAD_STORE_PASSWORD"
    echo "Created $KEYSTORE (alias: $ALIAS). BACK IT UP together with the password."
    echo "Fingerprints (Play Console shows the same after the first upload):"
    fingerprints
    ;;

  build)
    REF="${2:-HEAD}"
    [ -f "$KEYSTORE" ] || { echo "No key at $KEYSTORE. Run: scripts/android-release.sh create-key"; exit 1; }
    ask_secret ONTIME_UPLOAD_STORE_PASSWORD "Key file password: "
    export ONTIME_UPLOAD_KEY_PASSWORD="${ONTIME_UPLOAD_KEY_PASSWORD:-$ONTIME_UPLOAD_STORE_PASSWORD}"
    export ONTIME_UPLOAD_STORE_FILE="$KEYSTORE" ONTIME_UPLOAD_KEY_ALIAS="$ALIAS"
    # Fails early on a wrong password.
    "$KEYTOOL" -list -keystore "$KEYSTORE" -alias "$ALIAS" -storepass "$ONTIME_UPLOAD_STORE_PASSWORD" >/dev/null 2>&1 || { echo "Cannot open the key with that password/alias."; exit 1; }

    WORK="$(mktemp -d "${TMPDIR:-/tmp}/ontime-android.XXXXXX")"
    trap 'rm -rf "$WORK"' EXIT
    git -C "$REPO" archive "$REF" mobile | tar -x -C "$WORK"
    cd "$WORK/mobile"

    VERSION_NAME="$(python3 -c "import json;print(json.load(open('app.json'))['expo']['version'])")"
    VERSION_CODE="$(python3 -c "import json;print(json.load(open('app.json'))['expo']['android']['versionCode'])")"
    echo "Building OnTime $VERSION_NAME (versionCode $VERSION_CODE) from $REF"

    npm install --omit=dev --legacy-peer-deps --no-audit --no-fund
    CI=1 npx expo prebuild --platform android --no-install

    # Sign the release build with the upload key (read from the environment), not the debug key.
    python3 - <<'PY'
import re
p = 'android/app/build.gradle'
s = open(p).read()
block = """    signingConfigs {
        release {
            storeFile file(System.getenv('ONTIME_UPLOAD_STORE_FILE'))
            storePassword System.getenv('ONTIME_UPLOAD_STORE_PASSWORD')
            keyAlias System.getenv('ONTIME_UPLOAD_KEY_ALIAS')
            keyPassword System.getenv('ONTIME_UPLOAD_KEY_PASSWORD')
        }
    }
"""
s, n1 = re.subn(r'(\n    buildTypes \{)', '\n' + block + r'\1', s, count=1)
s, n2 = re.subn(r'(release \{[^}]*?)signingConfig signingConfigs\.debug', r'\1signingConfig signingConfigs.release', s, count=1, flags=re.S)
assert n1 == 1 and n2 == 1, 'could not patch the release signing config'
open(p, 'w').write(s)
PY

    # Source maps are uploaded to Sentry only when SENTRY_AUTH_TOKEN is set.
    if [ -z "${SENTRY_AUTH_TOKEN:-}" ]; then export SENTRY_DISABLE_AUTO_UPLOAD=true; fi
    export NODE_ENV=production
    cd android
    echo "sdk.dir=$ANDROID_HOME" > local.properties
    ./gradlew bundleRelease --no-daemon

    AAB="$(ls app/build/outputs/bundle/release/*.aab | head -1)"
    OUT_DIR="${ONTIME_OUT_DIR:-$HOME/Downloads}"; mkdir -p "$OUT_DIR"
    OUT="$OUT_DIR/ontime-$VERSION_NAME-$VERSION_CODE.aab"
    cp "$AAB" "$OUT"
    echo
    echo "Bundle: $OUT ($(du -h "$OUT" | cut -f1))"
    echo "Signed with:"
    "$JAVA_HOME/bin/jarsigner" -verify -verbose:summary -certs "$OUT" 2>&1 | grep -E "jar verified|CN=" | sort -u | head -3
    "$KEYTOOL" -printcert -jarfile "$OUT" 2>/dev/null | grep -E "SHA1:|SHA256:" | sed 's/^ *//'
    ;;

  *)
    echo "Usage: $0 create-key | build [git-ref]"; exit 2 ;;
esac
