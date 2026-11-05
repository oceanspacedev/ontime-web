import { Ionicons } from '@expo/vector-icons';
import { type StyleProp, View, type ViewStyle } from 'react-native';

import { type IconName, Text } from '@/components/ui';
import { attendanceApi, type CompanyPolicy } from '@/lib/api';
import { fontSize, makeStyles, radius, spacing, useTheme } from '@/theme';

interface Requirement {
  key: string;
  label: string;
  icon: IconName;
}

/** The checks a policy asks for on every check in and check out, in the order the user meets them. */
export function attendanceRequirements(policy: CompanyPolicy | null | undefined): Requirement[] {
  const selfieMode = attendanceApi.selfieModeOf(policy);
  const requirements: Requirement[] = [];
  if (policy?.require_gps) {
    requirements.push({ key: 'gps', label: 'GPS', icon: 'location-outline' });
  }
  if (selfieMode === 'photo' || selfieMode === 'face') {
    requirements.push({ key: 'photo', label: 'Foto', icon: 'camera-outline' });
  }
  if (selfieMode === 'liveness') {
    requirements.push({ key: 'liveness', label: 'Liveness', icon: 'swap-horizontal-outline' });
  }
  if (selfieMode === 'face' || selfieMode === 'liveness') {
    requirements.push({ key: 'face', label: 'Wajah', icon: 'scan-outline' });
  }
  if (requirements.length === 0) {
    requirements.push({ key: 'click', label: 'Klik saja', icon: 'finger-print-outline' });
  }
  return requirements;
}

/** Small inline chips naming what the company's attendance mode asks for. */
export function AttendanceModeChips({ policy, style }: { policy: CompanyPolicy | null | undefined; style?: StyleProp<ViewStyle> }) {
  const styles = useStyles();
  const { colors } = useTheme();
  const requirements = attendanceRequirements(policy);
  const label = policy?.mode_label ?? requirements.map((requirement) => requirement.label).join(' + ');

  return (
    <View style={[styles.row, style]} accessible accessibilityLabel={`Mode presensi: ${label}`}>
      {requirements.map((requirement) => (
        <View key={requirement.key} style={styles.chip}>
          <Ionicons name={requirement.icon} size={13} color={colors.textMuted} />
          <Text style={styles.text}>{requirement.label}</Text>
        </View>
      ))}
    </View>
  );
}

const useStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: radius.pill,
    backgroundColor: c.fill,
  },
  text: { fontSize: fontSize.xs, color: c.textMuted, fontWeight: '600' },
}));
