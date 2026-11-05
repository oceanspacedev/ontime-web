import { Ionicons } from '@expo/vector-icons';
import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';

import type { UploadFile } from '@/lib/api';
import { guessMimeType, prepareUpload } from '@/lib/image';
import { colors, fontSize, radius, spacing } from '@/theme';

const MAX_BYTES = 5 * 1024 * 1024;

interface Props {
  label?: string;
  value: UploadFile | null;
  onChange: (file: UploadFile | null) => void;
  error?: string | null;
}

/** Optional attachment for leave and correction forms: a photo (compressed) or a PDF/Word document. */
export function AttachmentField({ label = 'Lampiran (opsional)', value, onChange, error }: Props) {
  const pickDocument = async () => {
    const result = await DocumentPicker.getDocumentAsync({
      type: ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/*'],
      copyToCacheDirectory: true,
      multiple: false,
    });
    if (result.canceled || !result.assets[0]) {
      return;
    }
    const asset = result.assets[0];
    const type = asset.mimeType ?? guessMimeType(asset.name);
    if (type.startsWith('image/')) {
      onChange(await prepareUpload(asset.uri, 'lampiran'));
      return;
    }
    if (asset.size && asset.size > MAX_BYTES) {
      Alert.alert('Berkas terlalu besar', 'Lampiran maksimal 5 MB.');
      return;
    }
    onChange({ uri: asset.uri, name: asset.name, type });
  };

  const pickPhoto = async () => {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) {
      Alert.alert('Izin dibutuhkan', 'Izinkan akses kamera untuk memotret lampiran.');
      return;
    }
    const result = await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 0.9 });
    if (result.canceled || !result.assets[0]) {
      return;
    }
    const asset = result.assets[0];
    onChange(await prepareUpload(asset.uri, 'lampiran', asset.width, asset.height));
  };

  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      {value ? (
        <View style={styles.selected}>
          <Ionicons name={value.type.startsWith('image/') ? 'image-outline' : 'document-outline'} size={20} color={colors.primary} />
          <Text style={styles.selectedName} numberOfLines={1}>
            {value.name}
          </Text>
          <Pressable onPress={() => onChange(null)} hitSlop={8}>
            <Ionicons name="close-circle" size={22} color={colors.textMuted} />
          </Pressable>
        </View>
      ) : (
        <View style={styles.buttons}>
          <Pressable style={styles.button} onPress={pickDocument}>
            <Ionicons name="attach-outline" size={18} color={colors.primary} />
            <Text style={styles.buttonText}>Pilih berkas</Text>
          </Pressable>
          <Pressable style={styles.button} onPress={pickPhoto}>
            <Ionicons name="camera-outline" size={18} color={colors.primary} />
            <Text style={styles.buttonText}>Foto</Text>
          </Pressable>
        </View>
      )}
      {error ? <Text style={styles.error}>{error}</Text> : <Text style={styles.hint}>JPG, PNG, PDF, atau Word, maksimal 5 MB.</Text>}
    </View>
  );
}

const styles = StyleSheet.create({
  field: { gap: 6 },
  label: { fontSize: fontSize.sm, fontWeight: '600', color: colors.text },
  buttons: { flexDirection: 'row', gap: spacing.sm },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: spacing.md,
    paddingVertical: 10,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  buttonText: { fontSize: fontSize.sm, color: colors.primary, fontWeight: '600' },
  selected: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    padding: spacing.md,
    borderRadius: radius.md,
    backgroundColor: colors.primarySoft,
  },
  selectedName: { flex: 1, fontSize: fontSize.sm, color: colors.text },
  hint: { fontSize: fontSize.xs, color: colors.textMuted },
  error: { fontSize: fontSize.xs, color: colors.danger },
});
