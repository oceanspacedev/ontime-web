import { Ionicons } from '@expo/vector-icons';

import { type IconName, Group, Row, Screen } from '@/components/ui';
import { type AppearancePreference, useAppearance, useTheme } from '@/theme';

const OPTIONS: { value: AppearancePreference; label: string; icon: IconName }[] = [
  { value: 'system', label: 'Ikuti sistem', icon: 'phone-portrait-outline' },
  { value: 'light', label: 'Terang', icon: 'sunny-outline' },
  { value: 'dark', label: 'Gelap', icon: 'moon-outline' },
];

/** Light, dark or follow the phone. The choice is stored on the device and applied right away. */
export default function AppearanceScreen() {
  const { colors } = useTheme();
  const preference = useAppearance((state) => state.preference);
  const setPreference = useAppearance((state) => state.setPreference);

  return (
    <Screen>
      <Group title="Tema" footer="Ikuti sistem memakai tema terang atau gelap sesuai pengaturan ponsel Anda.">
        {OPTIONS.map((option) => (
          <Row
            key={option.value}
            icon={option.icon}
            title={option.label}
            chevron={false}
            right={option.value === preference ? <Ionicons name="checkmark" size={22} color={colors.primary} accessibilityLabel="Dipilih" /> : null}
            onPress={() => void setPreference(option.value)}
          />
        ))}
      </Group>
    </Screen>
  );
}
