import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { type ComponentProps, type ReactNode, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  type PressableProps,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  type TextInputProps,
  type TextStyle,
  View,
  type ViewStyle,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import type { Tone } from '@/lib/format';
import { colors, fontSize, radius, spacing } from '@/theme';

export type IconName = ComponentProps<typeof Ionicons>['name'];

interface ScreenProps {
  children: ReactNode;
  scroll?: boolean;
  padded?: boolean;
  /** Pull-to-refresh handler. The spinner shows only for a pull, until the returned promise settles. */
  onRefresh?: () => Promise<unknown> | void;
  style?: ViewStyle;
  edges?: ComponentProps<typeof SafeAreaView>['edges'];
}

export function Screen({ children, scroll = true, padded = true, onRefresh, style, edges = ['bottom'] }: ScreenProps) {
  const [refreshing, setRefreshing] = useState(false);
  const content = padded ? <View style={[styles.padded, style]}>{children}</View> : children;

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
    <SafeAreaView style={styles.screen} edges={edges}>
      {scroll ? (
        <ScrollView
          contentContainerStyle={styles.scrollContent}
          keyboardShouldPersistTaps="handled"
          refreshControl={onRefresh ? <RefreshControl refreshing={refreshing} onRefresh={handleRefresh} tintColor={colors.primary} /> : undefined}>
          {content}
        </ScrollView>
      ) : (
        content
      )}
    </SafeAreaView>
  );
}

type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost';

interface ButtonProps extends Omit<PressableProps, 'style' | 'children'> {
  title: string;
  variant?: ButtonVariant;
  loading?: boolean;
  icon?: IconName;
  style?: ViewStyle;
  size?: 'md' | 'lg';
}

export function Button({ title, variant = 'primary', loading = false, icon, disabled, style, size = 'md', ...rest }: ButtonProps) {
  const isDisabled = disabled || loading;
  const background = {
    primary: colors.primary,
    secondary: colors.primarySoft,
    danger: colors.dangerSoft,
    ghost: 'transparent',
  }[variant];
  const foreground = {
    primary: colors.textOnPrimary,
    secondary: colors.primary,
    danger: colors.danger,
    ghost: colors.primary,
  }[variant];
  return (
    <Pressable
      accessibilityRole="button"
      disabled={isDisabled}
      style={({ pressed }) => [
        styles.button,
        size === 'lg' && styles.buttonLarge,
        { backgroundColor: background, opacity: isDisabled ? 0.6 : pressed ? 0.85 : 1 },
        style,
      ]}
      {...rest}>
      {loading ? (
        <ActivityIndicator color={foreground} />
      ) : (
        <>
          {icon ? <Ionicons name={icon} size={18} color={foreground} style={styles.buttonIcon} /> : null}
          <Text style={[styles.buttonText, size === 'lg' && styles.buttonTextLarge, { color: foreground }]}>{title}</Text>
        </>
      )}
    </Pressable>
  );
}

export function Card({ children, style, onPress }: { children: ReactNode; style?: ViewStyle; onPress?: () => void }) {
  if (onPress) {
    return (
      <Pressable onPress={onPress} style={({ pressed }) => [styles.card, style, pressed && styles.cardPressed]}>
        {children}
      </Pressable>
    );
  }
  return <View style={[styles.card, style]}>{children}</View>;
}

const toneStyles: Record<Tone, { bg: string; fg: string }> = {
  success: { bg: colors.successSoft, fg: colors.success },
  warning: { bg: colors.warningSoft, fg: colors.warning },
  danger: { bg: colors.dangerSoft, fg: colors.danger },
  info: { bg: colors.infoSoft, fg: colors.info },
  gray: { bg: colors.graySoft, fg: colors.gray },
};

export function Badge({ label, tone = 'gray', style }: { label: string; tone?: Tone; style?: ViewStyle }) {
  const palette = toneStyles[tone];
  return (
    <View style={[styles.badge, { backgroundColor: palette.bg }, style]}>
      <Text style={[styles.badgeText, { color: palette.fg }]}>{label}</Text>
    </View>
  );
}

export function SectionTitle({ title, action, onAction }: { title: string; action?: string; onAction?: () => void }) {
  return (
    <View style={styles.sectionTitleRow}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {action && onAction ? (
        <Pressable onPress={onAction} hitSlop={8}>
          <Text style={styles.sectionAction}>{action}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

interface FieldProps extends TextInputProps {
  label: string;
  error?: string | null;
  hint?: string;
}

export function Field({ label, error, hint, style, ...rest }: FieldProps) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <TextInput
        placeholderTextColor={colors.textMuted}
        style={[styles.input, rest.multiline && styles.inputMultiline, error ? styles.inputError : null, style]}
        {...rest}
      />
      {error ? <Text style={styles.fieldError}>{error}</Text> : hint ? <Text style={styles.fieldHint}>{hint}</Text> : null}
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
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <Pressable onPress={onPress} style={[styles.input, styles.pickerInput, error ? styles.inputError : null]}>
        <Text style={[styles.pickerText, !value && styles.pickerPlaceholder]}>{value || placeholder}</Text>
        <Ionicons name={icon} size={18} color={colors.textMuted} />
      </Pressable>
      {error ? <Text style={styles.fieldError}>{error}</Text> : hint ? <Text style={styles.fieldHint}>{hint}</Text> : null}
    </View>
  );
}

export function Choice<T extends string>({
  options,
  value,
  onChange,
}: {
  options: { value: T; label: string }[];
  value: T | null;
  onChange: (value: T) => void;
}) {
  return (
    <View style={styles.choiceRow}>
      {options.map((option) => {
        const active = option.value === value;
        return (
          <Pressable
            key={option.value}
            onPress={() => onChange(option.value)}
            style={[styles.choice, active && styles.choiceActive]}>
            <Text style={[styles.choiceText, active && styles.choiceTextActive]}>{option.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export function InfoRow({ label, value, valueStyle }: { label: string; value: ReactNode; valueStyle?: TextStyle }) {
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      {typeof value === 'string' || typeof value === 'number' ? (
        <Text style={[styles.infoValue, valueStyle]}>{value}</Text>
      ) : (
        <View style={styles.infoValueWrap}>{value}</View>
      )}
    </View>
  );
}

export function EmptyState({ icon = 'file-tray-outline', title, message }: { icon?: IconName; title: string; message?: string }) {
  return (
    <View style={styles.empty}>
      <Ionicons name={icon} size={40} color={colors.textMuted} />
      <Text style={styles.emptyTitle}>{title}</Text>
      {message ? <Text style={styles.emptyMessage}>{message}</Text> : null}
    </View>
  );
}

export function LoadingView({ message }: { message?: string }) {
  return (
    <View style={styles.loading}>
      <ActivityIndicator size="large" color={colors.primary} />
      {message ? <Text style={styles.loadingText}>{message}</Text> : null}
    </View>
  );
}

export function ErrorBanner({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={styles.errorBanner}>
      <Ionicons name="alert-circle" size={20} color={colors.danger} />
      <Text style={styles.errorBannerText}>{message}</Text>
      {onRetry ? (
        <Pressable onPress={onRetry} hitSlop={8}>
          <Text style={styles.errorRetry}>Coba lagi</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

export function Notice({ tone = 'info', message, icon }: { tone?: Tone; message: string; icon?: IconName }) {
  const palette = toneStyles[tone];
  return (
    <View style={[styles.notice, { backgroundColor: palette.bg }]}>
      <Ionicons name={icon ?? (tone === 'success' ? 'checkmark-circle' : tone === 'danger' ? 'alert-circle' : 'information-circle')} size={20} color={palette.fg} />
      <Text style={[styles.noticeText, { color: palette.fg }]}>{message}</Text>
    </View>
  );
}

export function Avatar({ name, uri, size = 48 }: { name: string; uri?: string | null; size?: number }) {
  const initials = name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('');
  return (
    <View style={[styles.avatar, { width: size, height: size, borderRadius: size / 2 }]}>
      {uri ? (
        <AvatarImage uri={uri} size={size} />
      ) : (
        <Text style={[styles.avatarText, { fontSize: size * 0.38 }]}>{initials || '?'}</Text>
      )}
    </View>
  );
}

function AvatarImage({ uri, size }: { uri: string; size: number }) {
  return <Image source={{ uri }} style={{ width: size, height: size, borderRadius: size / 2 }} contentFit="cover" />;
}

export function ListRow({
  title,
  subtitle,
  right,
  onPress,
  icon,
}: {
  title: string;
  subtitle?: string;
  right?: ReactNode;
  onPress?: () => void;
  icon?: IconName;
}) {
  return (
    <Pressable onPress={onPress} disabled={!onPress} style={({ pressed }) => [styles.listRow, pressed && styles.cardPressed]}>
      {icon ? (
        <View style={styles.listIcon}>
          <Ionicons name={icon} size={20} color={colors.primary} />
        </View>
      ) : null}
      <View style={styles.listBody}>
        <Text style={styles.listTitle} numberOfLines={2}>
          {title}
        </Text>
        {subtitle ? (
          <Text style={styles.listSubtitle} numberOfLines={2}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {right ? <View style={styles.listRight}>{right}</View> : null}
      {onPress ? <Ionicons name="chevron-forward" size={18} color={colors.textMuted} /> : null}
    </Pressable>
  );
}

export const text = StyleSheet.create({
  title: { fontSize: fontSize.xl, fontWeight: '700', color: colors.text },
  subtitle: { fontSize: fontSize.md, color: colors.textMuted },
  body: { fontSize: fontSize.md, color: colors.text, lineHeight: 22 },
  muted: { fontSize: fontSize.sm, color: colors.textMuted },
  strong: { fontSize: fontSize.md, fontWeight: '600', color: colors.text },
});

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background },
  scrollContent: { flexGrow: 1 },
  padded: { padding: spacing.lg, gap: spacing.md },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 12,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
    minHeight: 46,
  },
  buttonLarge: { minHeight: 56, borderRadius: radius.lg },
  buttonIcon: { marginRight: spacing.sm },
  buttonText: { fontSize: fontSize.md, fontWeight: '600' },
  buttonTextLarge: { fontSize: fontSize.lg },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
    gap: spacing.sm,
  },
  cardPressed: { opacity: 0.85 },
  badge: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: radius.pill, alignSelf: 'flex-start' },
  badgeText: { fontSize: fontSize.xs, fontWeight: '600' },
  sectionTitleRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: spacing.sm },
  sectionTitle: { fontSize: fontSize.lg, fontWeight: '700', color: colors.text },
  sectionAction: { fontSize: fontSize.sm, fontWeight: '600', color: colors.primary },
  field: { gap: 6 },
  fieldLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.text },
  input: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
    fontSize: fontSize.md,
    color: colors.text,
    minHeight: 46,
  },
  inputMultiline: { minHeight: 100, textAlignVertical: 'top' },
  inputError: { borderColor: colors.danger },
  fieldError: { fontSize: fontSize.xs, color: colors.danger },
  fieldHint: { fontSize: fontSize.xs, color: colors.textMuted },
  pickerInput: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  pickerText: { fontSize: fontSize.md, color: colors.text },
  pickerPlaceholder: { color: colors.textMuted },
  choiceRow: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  choice: {
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
  },
  choiceActive: { backgroundColor: colors.primary, borderColor: colors.primary },
  choiceText: { fontSize: fontSize.sm, color: colors.text, fontWeight: '500' },
  choiceTextActive: { color: colors.textOnPrimary },
  infoRow: { flexDirection: 'row', justifyContent: 'space-between', gap: spacing.md, paddingVertical: 6 },
  infoLabel: { fontSize: fontSize.sm, color: colors.textMuted, flexShrink: 0 },
  infoValue: { fontSize: fontSize.sm, color: colors.text, fontWeight: '500', flex: 1, textAlign: 'right' },
  infoValueWrap: { flex: 1, alignItems: 'flex-end' },
  empty: { alignItems: 'center', padding: spacing.xxl, gap: spacing.sm },
  emptyTitle: { fontSize: fontSize.md, fontWeight: '600', color: colors.text, textAlign: 'center' },
  emptyMessage: { fontSize: fontSize.sm, color: colors.textMuted, textAlign: 'center' },
  loading: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xxl, gap: spacing.md },
  loadingText: { fontSize: fontSize.sm, color: colors.textMuted },
  errorBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.dangerSoft,
    padding: spacing.md,
    borderRadius: radius.md,
  },
  errorBannerText: { flex: 1, color: colors.danger, fontSize: fontSize.sm },
  errorRetry: { color: colors.danger, fontWeight: '700', fontSize: fontSize.sm },
  notice: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.sm, padding: spacing.md, borderRadius: radius.md },
  noticeText: { flex: 1, fontSize: fontSize.sm, lineHeight: 20 },
  avatar: { backgroundColor: colors.primarySoft, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  avatarText: { color: colors.primary, fontWeight: '700' },
  listRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    padding: spacing.lg,
    borderRadius: radius.lg,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
  },
  listIcon: {
    width: 40,
    height: 40,
    borderRadius: radius.md,
    backgroundColor: colors.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  listBody: { flex: 1, gap: 2 },
  listTitle: { fontSize: fontSize.md, fontWeight: '600', color: colors.text },
  listSubtitle: { fontSize: fontSize.sm, color: colors.textMuted },
  listRight: { alignItems: 'flex-end' },
});
