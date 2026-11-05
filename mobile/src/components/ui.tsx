import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import {
  Children,
  type ComponentProps,
  createContext,
  Fragment,
  isValidElement,
  type ReactNode,
  useContext,
  useState,
} from 'react';
import {
  ActivityIndicator,
  Modal,
  Pressable,
  type PressableProps,
  RefreshControl,
  ScrollView,
  type StyleProp,
  StyleSheet,
  Text as RNText,
  type TextProps,
  TextInput,
  type TextInputProps,
  type TextStyle,
  View,
  type ViewStyle,
} from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';

import type { Tone } from '@/lib/format';
import { BRAND_GLOSS, BRAND_GRADIENT, elevation, fonts, fontSize, makeStyles, type Palette, radius, spacing, useTheme } from '@/theme';

export type IconName = ComponentProps<typeof Ionicons>['name'];

/* -------------------------------------------------------------------------------------------------
 * Text
 * -----------------------------------------------------------------------------------------------*/

const WEIGHT_TO_FAMILY: Record<string, string> = {
  '100': fonts.regular,
  '200': fonts.regular,
  '300': fonts.regular,
  '400': fonts.regular,
  normal: fonts.regular,
  '500': fonts.medium,
  '600': fonts.semibold,
  '700': fonts.bold,
  bold: fonts.bold,
  '800': fonts.extrabold,
  '900': fonts.extrabold,
};

/** Maps `fontWeight` to the matching Plus Jakarta Sans family, so callers keep writing weights. */
export function resolveFont(style: StyleProp<TextStyle>): TextStyle {
  const flat = (StyleSheet.flatten(style) ?? {}) as TextStyle;
  if (flat.fontFamily) {
    return flat;
  }
  const { fontWeight, ...rest } = flat;
  return { ...rest, fontFamily: WEIGHT_TO_FAMILY[String(fontWeight ?? '400')] ?? fonts.regular };
}

/**
 * Drop-in replacement for React Native's Text: applies the app font and the theme text color.
 * Use `fontWeight` as usual; it is turned into the right font family.
 */
export function Text({ style, ...rest }: TextProps) {
  const { colors } = useTheme();
  return <RNText {...rest} style={resolveFont([{ color: colors.text, fontSize: fontSize.md }, style])} />;
}

/** Shared text styles. Call inside a component: `const t = useTextStyles();`. */
export const useTextStyles = makeStyles((c) => ({
  display: { fontSize: 34, fontWeight: '700', color: c.text, letterSpacing: -0.6, fontVariant: ['tabular-nums'] },
  largeTitle: { fontSize: fontSize.xxl, fontWeight: '700', color: c.text, letterSpacing: -0.4 },
  title: { fontSize: 22, fontWeight: '700', color: c.text, letterSpacing: -0.3 },
  heading: { fontSize: fontSize.lg, fontWeight: '600', color: c.text },
  subtitle: { fontSize: fontSize.md, color: c.textMuted },
  body: { fontSize: fontSize.md, color: c.text, lineHeight: 22 },
  strong: { fontSize: fontSize.md, fontWeight: '600', color: c.text },
  muted: { fontSize: fontSize.sm, color: c.textMuted },
  caption: { fontSize: fontSize.xs, color: c.textMuted },
  label: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500' },
}));

/* -------------------------------------------------------------------------------------------------
 * Screen
 * -----------------------------------------------------------------------------------------------*/

/** Set by the tab layout so screens leave room for the floating tab bar. */
export const FloatingTabBarContext = createContext(false);

/** Height the floating tab bar covers above the bottom safe area. */
export const FLOATING_TAB_BAR_SPACE = 96;

interface ScreenProps {
  children: ReactNode;
  scroll?: boolean;
  padded?: boolean;
  /** Pull-to-refresh handler. The spinner shows only for a pull, until the returned promise settles. */
  onRefresh?: () => Promise<unknown> | void;
  style?: ViewStyle;
  edges?: ComponentProps<typeof SafeAreaView>['edges'];
  /** Large title at the top of a tab screen. Adds the top safe area automatically. */
  title?: string;
  /** Small line under the large title. */
  subtitle?: string;
  /** Element on the right of the large title, usually an IconButton. */
  headerRight?: ReactNode;
  /** Element pinned to the bottom, outside the scroll view, for primary actions. */
  footer?: ReactNode;
}

export function Screen({
  children,
  scroll = true,
  padded = true,
  onRefresh,
  style,
  edges,
  title,
  subtitle,
  headerRight,
  footer,
}: ScreenProps) {
  const styles = useScreenStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const inTabs = useContext(FloatingTabBarContext);
  const [refreshing, setRefreshing] = useState(false);

  const safeEdges = edges ?? (title ? (['top'] as const) : ([] as const));
  const bottomSpace = inTabs ? FLOATING_TAB_BAR_SPACE + insets.bottom : footer ? spacing.lg : Math.max(insets.bottom, spacing.lg) + spacing.sm;

  const header = title ? (
    <View style={styles.largeHeader}>
      <View style={styles.largeHeaderText}>
        <Text style={styles.largeTitle} numberOfLines={1}>
          {title}
        </Text>
        {subtitle ? (
          <Text style={styles.largeSubtitle} numberOfLines={1}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {headerRight}
    </View>
  ) : null;

  const content = padded ? (
    <View style={[styles.padded, style]}>
      {header}
      {children}
    </View>
  ) : (
    <>
      {header}
      {children}
    </>
  );

  const handleRefresh = async () => {
    if (!onRefresh) {
      return;
    }
    setRefreshing(true);
    try {
      await onRefresh();
    } finally {
      setRefreshing(false);
    }
  };

  return (
    <SafeAreaView style={styles.screen} edges={safeEdges}>
      {scroll ? (
        <ScrollView
          contentContainerStyle={[styles.scrollContent, { paddingBottom: bottomSpace }]}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
          refreshControl={onRefresh ? <RefreshControl refreshing={refreshing} onRefresh={handleRefresh} tintColor={colors.primary} /> : undefined}>
          {content}
        </ScrollView>
      ) : (
        <View style={[styles.fill, { paddingBottom: footer ? 0 : bottomSpace }]}>{content}</View>
      )}
      {footer ? <View style={[styles.footer, { paddingBottom: Math.max(insets.bottom, spacing.lg) }]}>{footer}</View> : null}
    </SafeAreaView>
  );
}

const useScreenStyles = makeStyles((c) => ({
  screen: { flex: 1, backgroundColor: c.background },
  fill: { flex: 1 },
  scrollContent: { flexGrow: 1 },
  padded: { paddingHorizontal: spacing.lg, paddingTop: spacing.md, gap: spacing.lg },
  largeHeader: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingTop: spacing.sm, paddingHorizontal: spacing.xs },
  largeHeaderText: { flex: 1 },
  largeTitle: { fontSize: fontSize.xxl, fontWeight: '700', color: c.text, letterSpacing: -0.5 },
  largeSubtitle: { fontSize: fontSize.sm, color: c.textMuted, marginTop: 2 },
  footer: {
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.md,
    gap: spacing.sm,
    backgroundColor: c.background,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: c.separator,
  },
}));

/* -------------------------------------------------------------------------------------------------
 * Grouped sections
 * -----------------------------------------------------------------------------------------------*/

interface GroupProps {
  /** Small muted label on the tinted frame, like "Settings". */
  title?: string;
  /** Text button on the right of the label. */
  action?: string;
  onAction?: () => void;
  children: ReactNode;
  /** Gives the inner card padding, for free-form content instead of rows. */
  padded?: boolean;
  /** Draws a hairline between children. On by default for rows. */
  separated?: boolean;
  /** Muted note under the card. */
  footer?: string;
  style?: StyleProp<ViewStyle>;
  cardStyle?: StyleProp<ViewStyle>;
}

/**
 * The signature container: a tinted frame with a small label, holding a raised card. Put Rows,
 * InfoRows or any content inside; separators are added between children automatically.
 */
export function Group({ title, action, onAction, children, padded = false, separated, footer, style, cardStyle }: GroupProps) {
  const styles = useGroupStyles();
  const items = Children.toArray(children).filter((child) => isValidElement(child) || typeof child === 'string');
  const withSeparators = separated ?? !padded;

  return (
    <View style={[styles.group, style]}>
      {title || action ? (
        <View style={styles.header}>
          {title ? <Text style={styles.title}>{title}</Text> : <View />}
          {action && onAction ? (
            <Pressable onPress={onAction} hitSlop={10}>
              <Text style={styles.action}>{action}</Text>
            </Pressable>
          ) : null}
        </View>
      ) : (
        <View style={styles.headerless} />
      )}
      <View style={[styles.card, padded && styles.cardPadded, cardStyle]}>
        {withSeparators
          ? items.map((child, index) => (
              <Fragment key={index}>
                {index > 0 ? <View style={styles.separator} /> : null}
                {child}
              </Fragment>
            ))
          : items}
      </View>
      {footer ? <Text style={styles.footer}>{footer}</Text> : null}
    </View>
  );
}

const useGroupStyles = makeStyles((c, dark) => ({
  group: { backgroundColor: c.group, borderRadius: radius.group, padding: 6 },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingTop: 10,
    paddingBottom: 10,
  },
  headerless: { height: 0 },
  title: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500' },
  action: { fontSize: fontSize.sm, color: c.primary, fontWeight: '600' },
  card: {
    backgroundColor: c.card,
    borderRadius: radius.card,
    overflow: 'hidden',
    boxShadow: dark ? `0px 1px 0px rgba(255,255,255,0.04) inset, 0px 2px 6px ${c.shadow}` : `0px 2px 6px ${c.shadow}`,
  },
  cardPadded: { padding: 18, gap: spacing.md },
  separator: { height: StyleSheet.hairlineWidth, backgroundColor: c.separator, marginLeft: 16 },
  footer: { fontSize: fontSize.xs, color: c.textMuted, paddingHorizontal: 16, paddingTop: 10, paddingBottom: 6, lineHeight: 17 },
}));

interface RowProps {
  title: string;
  subtitle?: string;
  icon?: IconName;
  /** Tints the icon; defaults to the text color like the reference settings list. */
  iconColor?: string;
  /** Muted text on the right, like "Day 1" or "EN". */
  value?: string;
  /** Any element on the right, before the chevron (a Pill, a Badge, a Switch). */
  right?: ReactNode;
  /** Element on the left instead of an icon, like an Avatar. */
  left?: ReactNode;
  onPress?: () => void;
  /** Shows the chevron. Defaults to true when the row is pressable. */
  chevron?: boolean;
  destructive?: boolean;
  disabled?: boolean;
}

/** One line in a Group: icon, label, optional value and chevron. */
export function Row({ title, subtitle, icon, iconColor, value, right, left, onPress, chevron, destructive, disabled }: RowProps) {
  const styles = useRowStyles();
  const { colors } = useTheme();
  const showChevron = chevron ?? Boolean(onPress);
  const tint = destructive ? colors.danger : (iconColor ?? colors.text);

  return (
    <Pressable
      onPress={onPress}
      disabled={!onPress || disabled}
      style={({ pressed }) => [styles.row, pressed && styles.pressed, disabled && styles.disabled]}>
      {left ?? (icon ? <Ionicons name={icon} size={24} color={tint} style={styles.icon} /> : null)}
      <View style={styles.body}>
        <Text style={[styles.title, destructive && { color: colors.danger }]} numberOfLines={2}>
          {title}
        </Text>
        {subtitle ? (
          <Text style={styles.subtitle} numberOfLines={2}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {value ? (
        <Text style={styles.value} numberOfLines={1}>
          {value}
        </Text>
      ) : null}
      {right}
      {showChevron ? <Ionicons name="chevron-forward" size={20} color={colors.textSubtle} /> : null}
    </Pressable>
  );
}

/** Older name for Row, kept for existing screens. */
export const ListRow = Row;

const useRowStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, minHeight: 60, paddingHorizontal: 16, paddingVertical: 12 },
  pressed: { backgroundColor: c.fill },
  disabled: { opacity: 0.5 },
  icon: { width: 26, textAlign: 'center' },
  body: { flex: 1, gap: 2 },
  title: { fontSize: fontSize.lg, color: c.text, fontWeight: '500' },
  subtitle: { fontSize: fontSize.sm, color: c.textMuted },
  value: { fontSize: fontSize.md, color: c.textMuted, maxWidth: '45%' },
}));

/** Label and value on one line inside a Group, for read-only details. */
export function InfoRow({ label, value, valueStyle }: { label: string; value: ReactNode; valueStyle?: StyleProp<TextStyle> }) {
  const styles = useInfoRowStyles();
  return (
    <View style={styles.row}>
      <Text style={styles.label}>{label}</Text>
      {typeof value === 'string' || typeof value === 'number' ? (
        <Text style={[styles.value, valueStyle]}>{value}</Text>
      ) : (
        <View style={styles.valueWrap}>{value}</View>
      )}
    </View>
  );
}

const useInfoRowStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.md, paddingHorizontal: 16, paddingVertical: 14 },
  label: { fontSize: fontSize.md, color: c.textMuted },
  value: { fontSize: fontSize.md, color: c.text, fontWeight: '500', flex: 1, textAlign: 'right' },
  valueWrap: { flex: 1, alignItems: 'flex-end' },
}));

/** A standalone raised card. Prefer Group; use Card for content that needs no frame label. */
export function Card({ children, style, onPress }: { children: ReactNode; style?: StyleProp<ViewStyle>; onPress?: () => void }) {
  const styles = useCardStyles();
  if (onPress) {
    return (
      <Pressable onPress={onPress} style={({ pressed }) => [styles.card, style, pressed && styles.pressed]}>
        {children}
      </Pressable>
    );
  }
  return <View style={[styles.card, style]}>{children}</View>;
}

const useCardStyles = makeStyles((c) => ({
  card: { backgroundColor: c.card, borderRadius: radius.card, padding: 18, gap: spacing.sm, boxShadow: elevation(c, 1) },
  pressed: { opacity: 0.85 },
}));

/** Small muted label above free content, matching the Group label style. */
export function SectionTitle({ title, action, onAction }: { title: string; action?: string; onAction?: () => void }) {
  const styles = useGroupStyles();
  return (
    <View style={[styles.header, { paddingHorizontal: spacing.xs, paddingBottom: 0 }]}>
      <Text style={styles.title}>{title}</Text>
      {action && onAction ? (
        <Pressable onPress={onAction} hitSlop={10}>
          <Text style={styles.action}>{action}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

/* -------------------------------------------------------------------------------------------------
 * Buttons
 * -----------------------------------------------------------------------------------------------*/

/**
 * `primary` red, glossy, for the one main action. `inverse` is the near-black (light) or white
 * (dark) pill from the reference screens. `secondary` is a neutral fill. `danger` is a soft red.
 */
export type ButtonVariant = 'primary' | 'inverse' | 'secondary' | 'danger' | 'ghost';

interface ButtonProps extends Omit<PressableProps, 'style' | 'children'> {
  title: string;
  variant?: ButtonVariant;
  loading?: boolean;
  icon?: IconName;
  style?: StyleProp<ViewStyle>;
  size?: 'sm' | 'md' | 'lg';
}

export function Button({ title, variant = 'primary', loading = false, icon, disabled, style, size = 'md', ...rest }: ButtonProps) {
  const styles = useButtonStyles();
  const { colors } = useTheme();
  const isDisabled = disabled || loading;
  const foreground = buttonForeground(colors, variant);

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      disabled={isDisabled}
      style={({ pressed }) => [
        styles.base,
        styles[size],
        styles[variant],
        pressed && styles[`${variant}Pressed`],
        isDisabled && styles.disabled,
        style,
      ]}
      {...rest}>
      {loading ? (
        <ActivityIndicator color={foreground} />
      ) : (
        <>
          {icon ? <Ionicons name={icon} size={size === 'sm' ? 16 : 20} color={foreground} /> : null}
          <Text style={[styles.label, size === 'sm' && styles.labelSm, size === 'lg' && styles.labelLg, { color: foreground }]} numberOfLines={1}>
            {title}
          </Text>
        </>
      )}
    </Pressable>
  );
}

function buttonForeground(c: Palette, variant: ButtonVariant): string {
  switch (variant) {
    case 'primary':
      return c.onPrimary;
    case 'inverse':
      return c.onInverse;
    case 'danger':
      return c.danger;
    case 'ghost':
      return c.primary;
    default:
      return c.text;
  }
}

const useButtonStyles = makeStyles((c, dark) => ({
  base: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: spacing.sm, borderRadius: radius.pill, paddingHorizontal: 22 },
  sm: { minHeight: 38, paddingHorizontal: 16 },
  md: { minHeight: 50 },
  lg: { minHeight: 58 },
  label: { fontSize: fontSize.md, fontWeight: '600' },
  labelSm: { fontSize: fontSize.sm },
  labelLg: { fontSize: fontSize.lg },
  disabled: { opacity: 0.45 },

  primary: {
    backgroundColor: c.primary,
    backgroundImage: BRAND_GRADIENT,
    boxShadow: `${BRAND_GLOSS}, 0px 8px 18px rgba(242,41,41,${dark ? 0.35 : 0.3})`,
  },
  primaryPressed: { backgroundColor: c.primaryPressed, backgroundImage: 'none', transform: [{ scale: 0.98 }] },

  inverse: {
    backgroundColor: c.inverse,
    backgroundImage: dark
      ? 'linear-gradient(180deg, #FFFFFF 0%, #EDEDF0 100%)'
      : 'linear-gradient(180deg, #48484C 0%, #2A2A2D 55%, #1E1E20 100%)',
    boxShadow: dark
      ? 'inset 0px -2px 0px rgba(0,0,0,0.10), 0px 6px 16px rgba(0,0,0,0.5)'
      : 'inset 0px 1px 0px rgba(255,255,255,0.18), inset 0px -2px 0px rgba(0,0,0,0.35), 0px 8px 18px rgba(0,0,0,0.22)',
  },
  inversePressed: { opacity: 0.9, transform: [{ scale: 0.98 }] },

  secondary: { backgroundColor: c.fill },
  secondaryPressed: { backgroundColor: c.fillPressed },

  danger: { backgroundColor: c.dangerSoft },
  dangerPressed: { opacity: 0.8 },

  ghost: { backgroundColor: 'transparent' },
  ghostPressed: { backgroundColor: c.fill },
}));

/** Round icon-only button, like the close and back buttons in the reference sheets. */
export function IconButton({
  icon,
  onPress,
  size = 46,
  tone = 'neutral',
  accessibilityLabel,
  badge,
  disabled,
}: {
  icon: IconName;
  onPress?: () => void;
  size?: number;
  tone?: 'neutral' | 'primary' | 'onDark';
  accessibilityLabel?: string;
  /** Small red dot or count on the corner. */
  badge?: number | boolean;
  disabled?: boolean;
}) {
  const styles = useIconButtonStyles();
  const { colors } = useTheme();
  const background = tone === 'primary' ? colors.primarySoft : tone === 'onDark' ? 'rgba(255,255,255,0.16)' : colors.fill;
  const foreground = tone === 'primary' ? colors.primary : tone === 'onDark' ? '#FFFFFF' : colors.text;
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      hitSlop={6}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      style={({ pressed }) => [
        styles.button,
        { width: size, height: size, borderRadius: size / 2, backgroundColor: background },
        pressed && styles.pressed,
        disabled && styles.disabled,
      ]}>
      <Ionicons name={icon} size={Math.round(size * 0.48)} color={foreground} />
      {badge ? (
        <View style={styles.badge}>
          {typeof badge === 'number' ? <Text style={styles.badgeText}>{badge > 99 ? '99+' : badge}</Text> : null}
        </View>
      ) : null}
    </Pressable>
  );
}

const useIconButtonStyles = makeStyles((c) => ({
  button: { alignItems: 'center', justifyContent: 'center', boxShadow: elevation(c, 1) },
  pressed: { transform: [{ scale: 0.94 }], opacity: 0.85 },
  disabled: { opacity: 0.4 },
  badge: {
    position: 'absolute',
    top: -2,
    right: -2,
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    paddingHorizontal: 4,
    backgroundColor: c.primary,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: c.background,
  },
  badgeText: { color: c.onPrimary, fontSize: 10, fontWeight: '700' },
}));

/** Rounded tile with an icon and a label underneath, for the home shortcuts. */
export function QuickAction({ icon, label, onPress, badge }: { icon: IconName; label: string; onPress: () => void; badge?: string | number }) {
  const styles = useQuickActionStyles();
  const { colors } = useTheme();
  return (
    <Pressable onPress={onPress} style={styles.wrap} accessibilityRole="button" accessibilityLabel={label}>
      {({ pressed }) => (
        <>
          <View style={[styles.tile, pressed && styles.pressed]}>
            <Ionicons name={icon} size={26} color={colors.text} />
          </View>
          {badge !== undefined && badge !== 0 && badge !== '' ? (
            <View style={styles.badge}>
              <Text style={styles.badgeText}>{badge}</Text>
            </View>
          ) : null}
          <Text style={styles.label} numberOfLines={2}>
            {label}
          </Text>
        </>
      )}
    </Pressable>
  );
}

const useQuickActionStyles = makeStyles((c) => ({
  wrap: { flex: 1, alignItems: 'center', gap: spacing.sm, minWidth: 0 },
  tile: {
    width: '100%',
    height: 64,
    borderRadius: radius.pill,
    backgroundColor: c.group,
    alignItems: 'center',
    justifyContent: 'center',
  },
  pressed: { backgroundColor: c.fillPressed, transform: [{ scale: 0.96 }] },
  label: { fontSize: fontSize.sm, color: c.text, textAlign: 'center', fontWeight: '500' },
  badge: {
    position: 'absolute',
    top: -6,
    right: 0,
    minWidth: 22,
    height: 22,
    paddingHorizontal: 7,
    borderRadius: 11,
    backgroundColor: c.primary,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: c.background,
  },
  badgeText: { color: c.onPrimary, fontSize: 11, fontWeight: '700' },
}));

/* -------------------------------------------------------------------------------------------------
 * Pills and badges
 * -----------------------------------------------------------------------------------------------*/

function toneColors(c: Palette, tone: Tone): { bg: string; fg: string } {
  switch (tone) {
    case 'success':
      return { bg: c.successSoft, fg: c.success };
    case 'warning':
      return { bg: c.warningSoft, fg: c.warning };
    case 'danger':
      return { bg: c.dangerSoft, fg: c.danger };
    case 'info':
      return { bg: c.infoSoft, fg: c.info };
    default:
      return { bg: c.graySoft, fg: c.gray };
  }
}

/** Status label with a tinted background. Pass `dot` for the neutral pill with a colored dot. */
export function Badge({ label, tone = 'gray', dot = false, style }: { label: string; tone?: Tone; dot?: boolean; style?: StyleProp<ViewStyle> }) {
  const styles = usePillStyles();
  const { colors } = useTheme();
  const palette = toneColors(colors, tone);
  if (dot) {
    return (
      <View style={[styles.pill, { backgroundColor: colors.fill }, style]}>
        <View style={[styles.dot, { backgroundColor: palette.fg }]} />
        <Text style={[styles.text, { color: colors.text }]}>{label}</Text>
      </View>
    );
  }
  return (
    <View style={[styles.pill, { backgroundColor: palette.bg }, style]}>
      <Text style={[styles.text, { color: palette.fg }]}>{label}</Text>
    </View>
  );
}

/** Neutral pill, like the "Free" plan label. */
export function Pill({ label, style }: { label: string; style?: StyleProp<ViewStyle> }) {
  const styles = usePillStyles();
  const { colors } = useTheme();
  return (
    <View style={[styles.pill, { backgroundColor: colors.fill }, style]}>
      <Text style={[styles.text, { color: colors.text }]}>{label}</Text>
    </View>
  );
}

/**
 * Thin rounded track with a red fill, for quotas. `progress` runs from 0 to 1 and is clamped.
 * Pass `accessibilityValue` when the bar is not already described by an accessible parent.
 */
export function ProgressBar({
  progress,
  height = 8,
  accessibilityValue,
}: {
  progress: number;
  height?: number;
  accessibilityValue?: { min: number; max: number; now: number };
}) {
  const styles = useProgressStyles();
  const clamped = Math.min(Math.max(progress, 0), 1);
  return (
    <View
      style={[styles.track, { height }]}
      accessibilityRole={accessibilityValue ? 'progressbar' : undefined}
      accessibilityValue={accessibilityValue}>
      <View style={[styles.fill, { width: `${clamped * 100}%` }]} />
    </View>
  );
}

const useProgressStyles = makeStyles((c) => ({
  track: { borderRadius: radius.pill, backgroundColor: c.fill, overflow: 'hidden' },
  fill: {
    height: '100%',
    borderRadius: radius.pill,
    backgroundColor: c.primary,
    backgroundImage: 'linear-gradient(90deg, #FF4D4D 0%, #F22929 100%)',
  },
}));

const usePillStyles = makeStyles(() => ({
  pill: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 12, paddingVertical: 5, borderRadius: radius.pill, alignSelf: 'flex-start' },
  dot: { width: 8, height: 8, borderRadius: 4 },
  text: { fontSize: fontSize.sm, fontWeight: '500' },
}));

/* -------------------------------------------------------------------------------------------------
 * Inputs
 * -----------------------------------------------------------------------------------------------*/

interface FieldProps extends TextInputProps {
  label: string;
  error?: string | null;
  hint?: string;
  /** Element inside the input on the right, like a show-password toggle. */
  trailing?: ReactNode;
}

export function Field({ label, error, hint, style, trailing, onFocus, onBlur, ...rest }: FieldProps) {
  const styles = useFieldStyles();
  const { colors } = useTheme();
  const [focused, setFocused] = useState(false);
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <View style={[styles.box, rest.multiline && styles.boxMultiline, focused && styles.boxFocused, error ? styles.boxError : null]}>
        <TextInput
          placeholderTextColor={colors.textSubtle}
          selectionColor={colors.primary}
          cursorColor={colors.primary}
          keyboardAppearance={undefined}
          style={[styles.input, rest.multiline && styles.inputMultiline, style]}
          onFocus={(event) => {
            setFocused(true);
            onFocus?.(event);
          }}
          onBlur={(event) => {
            setFocused(false);
            onBlur?.(event);
          }}
          {...rest}
        />
        {trailing ? <View style={styles.trailing}>{trailing}</View> : null}
      </View>
      {error ? <Text style={styles.error}>{error}</Text> : hint ? <Text style={styles.hint}>{hint}</Text> : null}
    </View>
  );
}

/** A read-only field that opens something on press, used for dates, times and pickers. */
export function PickerField({
  label,
  value,
  placeholder = 'Pilih',
  onPress,
  error,
  icon = 'chevron-down',
  hint,
}: {
  label: string;
  value?: string | null;
  placeholder?: string;
  onPress: () => void;
  error?: string | null;
  icon?: IconName;
  hint?: string;
}) {
  const styles = useFieldStyles();
  const { colors } = useTheme();
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <Pressable
        onPress={onPress}
        accessibilityRole="button"
        style={({ pressed }) => [styles.box, styles.picker, pressed && styles.boxPressed, error ? styles.boxError : null]}>
        <Text style={[styles.pickerText, !value && { color: colors.textSubtle }]} numberOfLines={1}>
          {value || placeholder}
        </Text>
        <Ionicons name={icon} size={20} color={colors.textMuted} />
      </Pressable>
      {error ? <Text style={styles.error}>{error}</Text> : hint ? <Text style={styles.hint}>{hint}</Text> : null}
    </View>
  );
}

const useFieldStyles = makeStyles((c) => ({
  field: { gap: 8 },
  label: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500', paddingHorizontal: 4 },
  box: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: c.fill,
    borderRadius: radius.lg,
    borderWidth: 1.5,
    borderColor: 'transparent',
    minHeight: 54,
  },
  boxMultiline: { alignItems: 'flex-start' },
  boxFocused: { borderColor: c.primary, backgroundColor: c.card },
  boxPressed: { backgroundColor: c.fillPressed },
  boxError: { borderColor: c.danger },
  input: {
    flex: 1,
    paddingHorizontal: 16,
    paddingVertical: 14,
    fontSize: fontSize.md,
    color: c.text,
    fontFamily: fonts.medium,
  },
  inputMultiline: { minHeight: 110, textAlignVertical: 'top' },
  trailing: { paddingRight: 14 },
  picker: { justifyContent: 'space-between', paddingHorizontal: 16 },
  pickerText: { flex: 1, fontSize: fontSize.md, color: c.text, fontWeight: '500' },
  error: { fontSize: fontSize.xs, color: c.danger, paddingHorizontal: 4 },
  hint: { fontSize: fontSize.xs, color: c.textMuted, paddingHorizontal: 4 },
}));

/** Wrapping chips for picking one option out of several. */
export function Choice<T extends string>({
  options,
  value,
  onChange,
}: {
  options: { value: T; label: string }[];
  value: T | null;
  onChange: (value: T) => void;
}) {
  const styles = useChoiceStyles();
  return (
    <View style={styles.row}>
      {options.map((option) => {
        const active = option.value === value;
        return (
          <Pressable
            key={option.value}
            onPress={() => onChange(option.value)}
            accessibilityRole="button"
            accessibilityState={{ selected: active }}
            style={({ pressed }) => [styles.chip, active && styles.chipActive, pressed && !active && styles.chipPressed]}>
            <Text style={[styles.text, active && styles.textActive]}>{option.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const useChoiceStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  chip: { paddingHorizontal: 16, paddingVertical: 10, borderRadius: radius.pill, backgroundColor: c.fill },
  chipPressed: { backgroundColor: c.fillPressed },
  chipActive: {
    backgroundColor: c.primary,
    boxShadow: 'inset 0px 1px 0px rgba(255,255,255,0.3), 0px 4px 10px rgba(242,41,41,0.28)',
  },
  text: { fontSize: fontSize.sm, color: c.text, fontWeight: '500' },
  textActive: { color: c.onPrimary, fontWeight: '600' },
}));

/**
 * Two to four equal segments in a tinted track; the active one lifts onto a card, like the
 * Expenses and Income switch in the reference.
 */
export function Segmented<T extends string>({
  options,
  value,
  onChange,
}: {
  options: { value: T; label: string; icon?: IconName }[];
  value: T;
  onChange: (value: T) => void;
}) {
  const styles = useSegmentedStyles();
  const { colors } = useTheme();
  return (
    <View style={styles.track}>
      {options.map((option) => {
        const active = option.value === value;
        return (
          <Pressable
            key={option.value}
            onPress={() => onChange(option.value)}
            accessibilityRole="tab"
            accessibilityState={{ selected: active }}
            style={[styles.segment, active && styles.segmentActive]}>
            {option.icon ? <Ionicons name={option.icon} size={16} color={active ? colors.primary : colors.textMuted} /> : null}
            <Text style={[styles.text, active && styles.textActive]} numberOfLines={1}>
              {option.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const useSegmentedStyles = makeStyles((c, dark) => ({
  track: { flexDirection: 'row', backgroundColor: c.fill, borderRadius: radius.pill, padding: 4, gap: 4 },
  segment: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, minHeight: 42, borderRadius: radius.pill, paddingHorizontal: 8 },
  segmentActive: { backgroundColor: dark ? c.card : c.card, boxShadow: elevation(c, 1) },
  text: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500' },
  textActive: { color: c.text, fontWeight: '600' },
}));

/* -------------------------------------------------------------------------------------------------
 * Feedback
 * -----------------------------------------------------------------------------------------------*/

export function EmptyState({ icon = 'file-tray-outline', title, message, action }: { icon?: IconName; title: string; message?: string; action?: ReactNode }) {
  const styles = useEmptyStyles();
  const { colors } = useTheme();
  return (
    <View style={styles.wrap}>
      <View style={styles.iconCircle}>
        <Ionicons name={icon} size={30} color={colors.textMuted} />
      </View>
      <Text style={styles.title}>{title}</Text>
      {message ? <Text style={styles.message}>{message}</Text> : null}
      {action ? <View style={styles.action}>{action}</View> : null}
    </View>
  );
}

const useEmptyStyles = makeStyles((c) => ({
  wrap: { alignItems: 'center', paddingVertical: spacing.xxl, paddingHorizontal: spacing.xl, gap: spacing.sm },
  iconCircle: { width: 72, height: 72, borderRadius: 36, backgroundColor: c.fill, alignItems: 'center', justifyContent: 'center', marginBottom: spacing.xs },
  title: { fontSize: fontSize.lg, fontWeight: '600', color: c.text, textAlign: 'center' },
  message: { fontSize: fontSize.sm, color: c.textMuted, textAlign: 'center', lineHeight: 20 },
  action: { marginTop: spacing.md, alignSelf: 'stretch' },
}));

export function LoadingView({ message }: { message?: string }) {
  const styles = useLoadingStyles();
  const { colors } = useTheme();
  return (
    <View style={styles.wrap}>
      <ActivityIndicator size="large" color={colors.primary} />
      {message ? <Text style={styles.text}>{message}</Text> : null}
    </View>
  );
}

const useLoadingStyles = makeStyles((c) => ({
  wrap: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xxl, gap: spacing.md, backgroundColor: 'transparent' },
  text: { fontSize: fontSize.sm, color: c.textMuted },
}));

export function ErrorBanner({ message, onRetry }: { message: string; onRetry?: () => void }) {
  const styles = useNoticeStyles();
  const { colors } = useTheme();
  return (
    <View style={[styles.notice, { backgroundColor: colors.dangerSoft }]}>
      <Ionicons name="alert-circle" size={20} color={colors.danger} />
      <Text style={[styles.text, { color: colors.danger }]}>{message}</Text>
      {onRetry ? (
        <Pressable onPress={onRetry} hitSlop={8}>
          <Text style={[styles.retry, { color: colors.danger }]}>Coba lagi</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

export function Notice({ tone = 'info', message, icon }: { tone?: Tone; message: string; icon?: IconName }) {
  const styles = useNoticeStyles();
  const { colors } = useTheme();
  const palette = toneColors(colors, tone);
  const fallbackIcon: IconName = tone === 'success' ? 'checkmark-circle' : tone === 'danger' ? 'alert-circle' : tone === 'warning' ? 'warning' : 'information-circle';
  return (
    <View style={[styles.notice, { backgroundColor: palette.bg }]}>
      <Ionicons name={icon ?? fallbackIcon} size={20} color={palette.fg} />
      <Text style={[styles.text, { color: palette.fg }]}>{message}</Text>
    </View>
  );
}

const useNoticeStyles = makeStyles(() => ({
  notice: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.sm, paddingHorizontal: 16, paddingVertical: 14, borderRadius: radius.lg },
  text: { flex: 1, fontSize: fontSize.sm, lineHeight: 20, fontWeight: '500' },
  retry: { fontSize: fontSize.sm, fontWeight: '700' },
}));

/* -------------------------------------------------------------------------------------------------
 * Avatar
 * -----------------------------------------------------------------------------------------------*/

export function Avatar({ name, uri, size = 48, shape = 'circle' }: { name: string; uri?: string | null; size?: number; shape?: 'circle' | 'rounded' }) {
  const styles = useAvatarStyles();
  const initials = name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('');
  const borderRadius = shape === 'circle' ? size / 2 : size * 0.32;
  return (
    <View style={[styles.avatar, { width: size, height: size, borderRadius }]}>
      {uri ? (
        <Image source={{ uri }} style={{ width: size, height: size, borderRadius }} contentFit="cover" />
      ) : (
        <Text style={[styles.text, { fontSize: size * 0.36 }]}>{initials || '?'}</Text>
      )}
    </View>
  );
}

const useAvatarStyles = makeStyles((c) => ({
  avatar: { backgroundColor: c.primarySoft, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  text: { color: c.primary, fontWeight: '600' },
}));

/* -------------------------------------------------------------------------------------------------
 * Bottom sheet
 * -----------------------------------------------------------------------------------------------*/

/**
 * Modal sheet with a drag handle, rising from the bottom. Tapping the dimmed backdrop closes it.
 * Put a Group inside for the reference look.
 */
export function Sheet({ visible, onClose, children, title }: { visible: boolean; onClose: () => void; children: ReactNode; title?: string }) {
  const styles = useSheetStyles();
  const insets = useSafeAreaInsets();
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <Pressable style={styles.backdrop} onPress={onClose} accessibilityLabel="Tutup" />
      <View style={[styles.sheet, { paddingBottom: Math.max(insets.bottom, spacing.lg) }]}>
        <View style={styles.handle} />
        {title ? <Text style={styles.title}>{title}</Text> : null}
        {children}
      </View>
    </Modal>
  );
}

const useSheetStyles = makeStyles((c) => ({
  backdrop: { flex: 1, backgroundColor: c.overlay },
  sheet: {
    backgroundColor: c.sheet,
    borderTopLeftRadius: 34,
    borderTopRightRadius: 34,
    paddingHorizontal: spacing.lg,
    paddingTop: 10,
    gap: spacing.lg,
    boxShadow: elevation(c, 3),
  },
  handle: { alignSelf: 'center', width: 44, height: 5, borderRadius: 3, backgroundColor: c.textSubtle, opacity: 0.6 },
  title: { fontSize: fontSize.lg, fontWeight: '600', color: c.text, textAlign: 'center' },
}));
