import { Ionicons } from '@expo/vector-icons';
import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { Alert, View } from 'react-native';

import { Button, IconButton, Text } from '@/components/ui';
import type { UploadFile } from '@/lib/api';
import { guessMimeType, prepareUpload } from '@/lib/image';
import { fontSize, makeStyles, radius, spacing, useTheme } from '@/theme';

const MAX_BYTES = 5 * 1024 * 1024;

interface Props {
  label?: string;
  value: UploadFile | null;
  onChange: (file: UploadFile | null) => void;
  error?: string | null;
}

/** Optional attachment for leave and correction forms: a photo (compressed) or a PDF/Word document. */
export function AttachmentField({ label = 'Lampiran (opsional)', value, onChange, error }: Props) {
  const styles = useStyles();
  const { colors } = useTheme();

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
        <View style={[styles.selected, error ? styles.selectedError : null]}>
          <View style={styles.fileIcon}>
            <Ionicons name={value.type.startsWith('image/') ? 'image-outline' : 'document-outline'} size={20} color={colors.primary} />
          </View>
          <Text style={styles.selectedName} numberOfLines={1}>
            {value.name}
          </Text>
          <IconButton icon="close" size={32} onPress={() => onChange(null)} accessibilityLabel="Hapus lampiran" />
        </View>
      ) : (
        <View style={styles.buttons}>
          <Button title="Pilih berkas" icon="attach-outline" variant="secondary" size="sm" onPress={pickDocument} />
          <Button title="Foto" icon="camera-outline" variant="secondary" size="sm" onPress={pickPhoto} />
        </View>
      )}
      {error ? <Text style={styles.error}>{error}</Text> : <Text style={styles.hint}>JPG, PNG, PDF, atau Word, maksimal 5 MB.</Text>}
    </View>
  );
}

const useStyles = makeStyles((c) => ({
  field: { gap: 8 },
  label: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500', paddingHorizontal: 4 },
  buttons: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  selected: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    minHeight: 54,
    paddingLeft: 10,
    paddingRight: 11,
    paddingVertical: 10,
    borderRadius: radius.lg,
    borderWidth: 1.5,
    borderColor: 'transparent',
    backgroundColor: c.fill,
  },
  selectedError: { borderColor: c.danger },
  fileIcon: {
    width: 34,
    height: 34,
    borderRadius: 17,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: c.primarySoft,
  },
  selectedName: { flex: 1, fontSize: fontSize.md, color: c.text, fontWeight: '500' },
  hint: { fontSize: fontSize.xs, color: c.textMuted, paddingHorizontal: 4 },
  error: { fontSize: fontSize.xs, color: c.danger, paddingHorizontal: 4 },
}));
