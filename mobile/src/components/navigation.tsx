import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import type { Tabs } from 'expo-router/js-tabs';
import type { ComponentProps, ReactNode } from 'react';
import { Platform, Pressable, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { IconButton, Text } from '@/components/ui';
import { elevation, fontSize, makeStyles, radius, spacing, useTheme } from '@/theme';

/* -------------------------------------------------------------------------------------------------
 * Stack header
 * -----------------------------------------------------------------------------------------------*/

interface StackHeaderProps {
  /** Title shown in the pill next to the back button. */
  title?: string;
  /** Whether a previous screen exists; when false the button closes a modal instead. */
  canGoBack: boolean;
  /** Close icon instead of a back chevron, for modals. */
  modal?: boolean;
  right?: ReactNode;
  onBack?: () => void;
}

/**
 * Round back button and a pill holding the title, from the reference "Space Setting" screen.
 * Used as the `header` of every stack screen.
 */
export function StackHeader({ title, canGoBack, modal = false, right, onBack }: StackHeaderProps) {
  const styles = useHeaderStyles();
  const insets = useSafeAreaInsets();
  const goBack = () => {
    if (onBack) {
      onBack();
      return;
    }
    if (router.canGoBack()) {
      router.back();
    }
  };

  return (
    <View style={[styles.header, { paddingTop: modal && Platform.OS === 'ios' ? spacing.lg : insets.top + spacing.sm }]}>
      {canGoBack || modal ? (
        <IconButton icon={modal ? 'close' : 'chevron-back'} onPress={goBack} accessibilityLabel={modal ? 'Tutup' : 'Kembali'} />
      ) : null}
      <View style={styles.titlePill}>
        <Text style={styles.title} numberOfLines={1}>
          {title}
        </Text>
      </View>
      {right}
    </View>
  );
}

const useHeaderStyles = makeStyles((c) => ({
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.sm,
    backgroundColor: c.background,
  },
  titlePill: {
    flex: 1,
    height: 46,
    borderRadius: radius.pill,
    backgroundColor: c.fill,
    justifyContent: 'center',
    paddingHorizontal: 20,
  },
  title: { fontSize: fontSize.md, color: c.textMuted, fontWeight: '500' },
}));

/* -------------------------------------------------------------------------------------------------
 * Floating tab bar
 * -----------------------------------------------------------------------------------------------*/

type TabBarProps = Parameters<NonNullable<ComponentProps<typeof Tabs>['tabBar']>>[0];

/**
 * Space under the floating tab bar. iOS can tuck it slightly into the home indicator area; Android's
 * navigation buttons cannot be overlapped, so the bar sits fully above them with a small gap.
 */
function tabBarBottomGap(bottomInset: number): number {
  return Platform.OS === 'android' ? bottomInset + spacing.sm : Math.max(bottomInset - 6, spacing.md);
}

/**
 * Pill-shaped tab bar floating above the content, icons only. The active tab sits on a soft red
 * pill. Screens inside the tabs add bottom space through FloatingTabBarContext.
 */
export function FloatingTabBar({ state, descriptors, navigateToTab, emitter }: TabBarProps) {
  const styles = useTabBarStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();

  return (
    <View pointerEvents="box-none" style={[styles.wrap, { paddingBottom: tabBarBottomGap(insets.bottom) }]}>
      <View style={styles.bar}>
        {state.routes.map((route, index) => {
          const descriptor = descriptors[route.key];
          if (!descriptor) {
            return null;
          }
          const options = descriptor.options;
          // A tab with href null stays a route (reachable from links) but is left out of the bar.
          if ((options as { href?: unknown }).href === null) {
            return null;
          }
          const focused = state.index === index;
          const label = options.title ?? route.name;
          const badge = options.tabBarBadge;
          const color = focused ? colors.primary : colors.text;

          const onPress = () => {
            const event = emitter.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
            if (!focused && !event.defaultPrevented) {
              navigateToTab(route.key);
            }
          };

          return (
            <Pressable
              key={route.key}
              onPress={onPress}
              accessibilityRole="tab"
              accessibilityState={{ selected: focused }}
              accessibilityLabel={label}
              style={({ pressed }) => [styles.item, focused && styles.itemActive, pressed && !focused && styles.itemPressed]}>
              {options.tabBarIcon ? options.tabBarIcon({ focused, color, size: 26 }) : <Ionicons name="ellipse" size={8} color={color} />}
              {badge !== undefined && badge !== null && badge !== '' ? (
                <View style={styles.badge}>
                  <Text style={styles.badgeText}>{typeof badge === 'number' && badge > 99 ? '99+' : String(badge)}</Text>
                </View>
              ) : null}
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const useTabBarStyles = makeStyles((c, dark) => ({
  wrap: { position: 'absolute', left: 0, right: 0, bottom: 0, paddingHorizontal: spacing.xl },
  bar: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    padding: 6,
    borderRadius: radius.pill,
    backgroundColor: dark ? '#232325' : '#FFFFFF',
    borderWidth: 1,
    borderColor: c.border,
    boxShadow: elevation(c, 3),
  },
  item: { flex: 1, height: 56, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center' },
  itemActive: { backgroundColor: c.primarySoft },
  itemPressed: { backgroundColor: c.fill },
  badge: {
    position: 'absolute',
    top: 6,
    right: '22%',
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    paddingHorizontal: 4,
    backgroundColor: c.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { color: c.onPrimary, fontSize: 10, fontWeight: '700' },
}));
