import { Ionicons } from '@expo/vector-icons';
import { useQuery } from '@tanstack/react-query';
import { Tabs } from 'expo-router/js-tabs';
import type { ColorValue } from 'react-native';

import { FloatingTabBar } from '@/components/navigation';
import { FloatingTabBarContext, type IconName } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { approvalsApi } from '@/lib/api';
import { countPending } from '@/lib/approvals';
import { queryKeys } from '@/lib/queryClient';
import { useTheme } from '@/theme';

function tabIcon(active: IconName, inactive: IconName) {
  const TabIcon = ({ color, focused }: { color: ColorValue; focused: boolean }) => (
    <Ionicons name={focused ? active : inactive} size={25} color={typeof color === 'string' ? color : undefined} />
  );
  TabIcon.displayName = `TabIcon(${active})`;
  return TabIcon;
}

export default function AppTabsLayout() {
  const access = useAccess();
  const { colors } = useTheme();
  const pending = useQuery({
    queryKey: queryKeys.approvals,
    queryFn: approvalsApi.pending,
    enabled: access.canApprove,
    refetchInterval: 60 * 1000,
  });
  const pendingCount = countPending(pending.data);

  return (
    <FloatingTabBarContext.Provider value>
      <Tabs
        tabBar={(props) => <FloatingTabBar {...props} />}
        screenOptions={{
          headerShown: false,
          sceneStyle: { backgroundColor: colors.background },
        }}>
        <Tabs.Screen name="index" options={{ title: 'Beranda', tabBarIcon: tabIcon('home', 'home-outline') }} />
        <Tabs.Screen name="history" options={{ title: 'Riwayat', tabBarIcon: tabIcon('time', 'time-outline') }} />
        <Tabs.Screen name="requests" options={{ title: 'Pengajuan', tabBarIcon: tabIcon('document-text', 'document-text-outline') }} />
        <Tabs.Protected guard={access.canApprove}>
          <Tabs.Screen
            name="approvals"
            options={{
              title: 'Persetujuan',
              tabBarIcon: tabIcon('checkmark-done-circle', 'checkmark-done-circle-outline'),
              tabBarBadge: pendingCount > 0 ? pendingCount : undefined,
            }}
          />
        </Tabs.Protected>
        <Tabs.Screen name="profile" options={{ title: 'Profil', tabBarIcon: tabIcon('person-circle', 'person-circle-outline') }} />
      </Tabs>
    </FloatingTabBarContext.Provider>
  );
}
