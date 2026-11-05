import * as SecureStore from 'expo-secure-store';
import { useMemo } from 'react';
import { Appearance, type ImageStyle, StyleSheet, type TextStyle, useColorScheme, type ViewStyle } from 'react-native';
import { create } from 'zustand';

/** Brand red. Everything that says "act here" or "you are here" uses it. */
export const BRAND = '#F22929';

/** Glossy top-to-bottom red used on primary buttons, the shutter and the login logo. */
export const BRAND_GRADIENT = 'linear-gradient(180deg, #FF4D4D 0%, #F22929 55%, #DD1A1A 100%)';

/** Inner highlight and rim that make a {@link BRAND_GRADIENT} surface look raised. */
export const BRAND_GLOSS = 'inset 0px 1px 0px rgba(255,255,255,0.35), inset 0px -2px 0px rgba(120,0,0,0.25)';

export interface Palette {
  /** Screen background behind the grouped sections. */
  background: string;
  /** Outer shell of a grouped section, the tinted frame with the small label. */
  group: string;
  /** Inner card that holds rows, sits inside a group. */
  card: string;
  /** Sheets and modals. */
  sheet: string;
  /** Neutral filled controls: chips, inputs, icon circles, inactive pills. */
  fill: string;
  fillPressed: string;
  separator: string;
  border: string;

  text: string;
  textMuted: string;
  textSubtle: string;

  primary: string;
  primaryPressed: string;
  primarySoft: string;
  onPrimary: string;

  /** High-contrast neutral button: near black in light mode, white in dark mode. */
  inverse: string;
  onInverse: string;

  success: string;
  successSoft: string;
  warning: string;
  warningSoft: string;
  danger: string;
  dangerSoft: string;
  info: string;
  infoSoft: string;
  gray: string;
  graySoft: string;

  overlay: string;
  shadow: string;
}

export const lightColors: Palette = {
  background: '#F6F6F7',
  group: '#EAEAEC',
  card: '#FFFFFF',
  sheet: '#FFFFFF',
  fill: '#EDEDEF',
  fillPressed: '#E2E2E5',
  separator: '#ECECEE',
  border: '#E3E3E6',

  text: '#141416',
  textMuted: '#6C6C72',
  textSubtle: '#A3A3A9',

  primary: BRAND,
  primaryPressed: '#D61E1E',
  primarySoft: '#FFE8E8',
  onPrimary: '#FFFFFF',

  inverse: '#2A2A2D',
  onInverse: '#FFFFFF',

  success: '#15803D',
  successSoft: '#DCF5E4',
  warning: '#C2410C',
  warningSoft: '#FFEFD9',
  danger: '#D61E1E',
  dangerSoft: '#FFE4E4',
  info: '#1D5FD8',
  infoSoft: '#E1ECFF',
  gray: '#55555B',
  graySoft: '#EDEDEF',

  overlay: 'rgba(10, 10, 12, 0.35)',
  shadow: 'rgba(16, 16, 20, 0.10)',
};

export const darkColors: Palette = {
  background: '#060607',
  group: '#262628',
  card: '#18181A',
  sheet: '#1C1C1E',
  fill: '#2C2C2F',
  fillPressed: '#38383C',
  separator: '#2A2A2D',
  border: '#323236',

  text: '#F4F4F6',
  textMuted: '#9C9CA3',
  textSubtle: '#65656B',

  primary: BRAND,
  primaryPressed: '#FF4545',
  primarySoft: 'rgba(242, 41, 41, 0.18)',
  onPrimary: '#FFFFFF',

  inverse: '#F7F7F8',
  onInverse: '#1A1A1C',

  success: '#4ADE80',
  successSoft: 'rgba(74, 222, 128, 0.16)',
  warning: '#FBBF24',
  warningSoft: 'rgba(251, 191, 36, 0.16)',
  danger: '#FF5A5A',
  dangerSoft: 'rgba(255, 90, 90, 0.16)',
  info: '#7AA7FF',
  infoSoft: 'rgba(122, 167, 255, 0.16)',
  gray: '#B4B4BA',
  graySoft: '#2C2C2F',

  overlay: 'rgba(0, 0, 0, 0.55)',
  shadow: 'rgba(0, 0, 0, 0.45)',
};

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

export const radius = {
  sm: 10,
  md: 14,
  lg: 20,
  /** Inner card of a grouped section. */
  card: 24,
  /** Outer frame of a grouped section, one inset larger than the card. */
  group: 30,
  pill: 999,
} as const;

export const fontSize = {
  xs: 12,
  sm: 13,
  md: 15,
  lg: 17,
  xl: 20,
  xxl: 28,
  hero: 40,
} as const;

/**
 * Plus Jakarta Sans, loaded in the root layout. Custom fonts carry their weight in the family
 * name, so never combine these with `fontWeight` (Android ignores it and iOS fakes it).
 */
export const fonts = {
  regular: 'PlusJakartaSans_400Regular',
  medium: 'PlusJakartaSans_500Medium',
  semibold: 'PlusJakartaSans_600SemiBold',
  bold: 'PlusJakartaSans_700Bold',
  extrabold: 'PlusJakartaSans_800ExtraBold',
} as const;

export type AppearancePreference = 'system' | 'light' | 'dark';

const APPEARANCE_KEY = 'ontime.appearance';

interface AppearanceState {
  preference: AppearancePreference;
  hydrate: () => Promise<void>;
  setPreference: (preference: AppearancePreference) => Promise<void>;
}

function applyPreference(preference: AppearancePreference): void {
  Appearance.setColorScheme(preference === 'system' ? 'unspecified' : preference);
}

export const useAppearance = create<AppearanceState>((set) => ({
  preference: 'system',
  hydrate: async () => {
    let stored: string | null = null;
    try {
      stored = await SecureStore.getItemAsync(APPEARANCE_KEY);
    } catch {
      stored = null;
    }
    const preference: AppearancePreference = stored === 'light' || stored === 'dark' ? stored : 'system';
    applyPreference(preference);
    set({ preference });
  },
  setPreference: async (preference) => {
    applyPreference(preference);
    set({ preference });
    try {
      await SecureStore.setItemAsync(APPEARANCE_KEY, preference);
    } catch {
      // The choice still applies until the app restarts.
    }
  },
}));

export interface Theme {
  dark: boolean;
  colors: Palette;
}

/**
 * Reads the in-app preference from the store as well as the system scheme. Native headers and
 * other long-lived views do not always re-render on Appearance events alone, but every component
 * using this hook re-renders when the store changes.
 */
export function useTheme(): Theme {
  const scheme = useColorScheme();
  const preference = useAppearance((state) => state.preference);
  const dark = preference === 'system' ? scheme === 'dark' : preference === 'dark';
  return useMemo(() => ({ dark, colors: dark ? darkColors : lightColors }), [dark]);
}

/**
 * Builds a themed style hook. Styles are created once per color scheme, so components can call
 * the hook on every render for free:
 *
 *   const useStyles = makeStyles((c) => ({ title: { color: c.text } }));
 *   const styles = useStyles();
 */
type StyleMap = { readonly [key: string]: ViewStyle | TextStyle | ImageStyle };

export function makeStyles<T extends StyleMap>(factory: (colors: Palette, dark: boolean) => T & StyleMap) {
  const cache: { light?: Readonly<T>; dark?: Readonly<T> } = {};
  return function useStyles(): Readonly<T> {
    const { dark, colors } = useTheme();
    const key = dark ? 'dark' : 'light';
    if (!cache[key]) {
      cache[key] = StyleSheet.create(factory(colors, dark));
    }
    return cache[key] as Readonly<T>;
  };
}

/** Soft drop shadow for cards and floating elements. */
export function elevation(colors: Palette, level: 1 | 2 | 3 = 1): string {
  const blur = { 1: 6, 2: 16, 3: 30 }[level];
  const y = { 1: 1, 2: 6, 3: 12 }[level];
  return `0px ${y}px ${blur}px ${colors.shadow}`;
}
