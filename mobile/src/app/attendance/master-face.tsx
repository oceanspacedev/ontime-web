import { Ionicons } from '@expo/vector-icons';
import { useMutation } from '@tanstack/react-query';
import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { useState } from 'react';
import { Alert, View } from 'react-native';

import { Button, Group, Notice, Screen, Text } from '@/components/ui';
import { useAccess } from '@/hooks/useAccess';
import { useRefreshUser } from '@/hooks/useRefreshUser';
import { attendanceApi, errorMessage } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { prepareUpload } from '@/lib/image';
import { fontSize, makeStyles, spacing, useTheme } from '@/theme';

interface Picked {
  uri: string;
  width: number;
  height: number;
}

export default function MasterFaceScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const access = useAccess();
  const refreshUser = useRefreshUser();
  const [picked, setPicked] = useState<Picked | null>(null);

  const pick = async (source: 'camera' | 'library') => {
    const permission = source === 'camera' ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) {
      Alert.alert('Izin dibutuhkan', source === 'camera' ? 'Izinkan akses kamera untuk mengambil foto.' : 'Izinkan akses galeri untuk memilih foto.');
      return;
    }
    const options: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], allowsEditing: true, aspect: [1, 1], quality: 0.9 };
    const result = source === 'camera'
      ? await ImagePicker.launchCameraAsync({ ...options, cameraType: ImagePicker.CameraType.front })
      : await ImagePicker.launchImageLibraryAsync(options);
    if (!result.canceled && result.assets[0]) {
      const asset = result.assets[0];
      setPicked({ uri: asset.uri, width: asset.width, height: asset.height });
    }
  };

  const upload = useMutation({
    mutationFn: async () => {
      if (!picked) {
        throw new Error('Pilih foto dulu.');
      }
      const file = await prepareUpload(picked.uri, 'master-face', picked.width, picked.height);
      return attendanceApi.registerMasterFace(file);
    },
    onSuccess: (response) => {
      setPicked(null);
      refreshUser.mutate();
      Alert.alert('Berhasil', response.message);
    },
  });

  const remove = useMutation({
    mutationFn: attendanceApi.deleteMasterFace,
    onSuccess: (response) => {
      refreshUser.mutate();
      Alert.alert('Berhasil', response.message);
    },
    onError: (error) => Alert.alert('Gagal', errorMessage(error)),
  });

  const confirmRemove = () => {
    Alert.alert('Hapus foto master wajah?', 'Setelah dihapus, presensi dengan verifikasi wajah tidak bisa dilakukan sampai foto baru didaftarkan.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Hapus', style: 'destructive', onPress: () => remove.mutate() },
    ]);
  };

  const current = access.masterFacePhoto;
  const requiresFace = access.company?.policy?.require_face_recognition ?? false;

  return (
    <Screen>
      <Notice
        tone={requiresFace ? 'warning' : 'info'}
        icon="scan-outline"
        message={
          requiresFace
            ? 'Perusahaan mewajibkan verifikasi wajah saat absen. Foto selfie presensi akan dibandingkan dengan foto ini.'
            : 'Foto master wajah dipakai sebagai pembanding bila perusahaan mengaktifkan verifikasi wajah.'
        }
      />

      <Group title={picked ? 'Foto baru' : 'Foto terdaftar'} padded cardStyle={styles.photoCard}>
        {picked || current ? (
          <Image source={{ uri: picked?.uri ?? current ?? undefined }} style={styles.photo} contentFit="cover" />
        ) : (
          <View style={styles.placeholder}>
            <View style={styles.placeholderIcon}>
              <Ionicons name="person-outline" size={56} color={colors.textMuted} />
            </View>
            <Text style={styles.muted}>Belum ada foto master wajah.</Text>
          </View>
        )}
        {!picked && access.masterFaceVerifiedAt ? <Text style={styles.muted}>Didaftarkan {formatDateTime(access.masterFaceVerifiedAt)}</Text> : null}
        {upload.isError ? (
          <View style={styles.fullWidth}>
            <Notice tone="danger" message={errorMessage(upload.error)} />
          </View>
        ) : null}
      </Group>

      <Text style={styles.tips}>Gunakan wajah menghadap depan, pencahayaan cukup, tanpa masker atau kacamata hitam.</Text>

      {picked ? (
        <View style={styles.row}>
          <Button title="Batal" variant="secondary" onPress={() => setPicked(null)} disabled={upload.isPending} style={styles.flex} />
          <Button title="Simpan foto" icon="cloud-upload-outline" onPress={() => upload.mutate()} loading={upload.isPending} style={styles.flex} />
        </View>
      ) : (
        <View style={styles.actions}>
          <Button title="Ambil dengan kamera" icon="camera-outline" size="lg" onPress={() => pick('camera')} />
          <Button title="Pilih dari galeri" icon="images-outline" variant="secondary" onPress={() => pick('library')} />
          {current ? <Button title="Hapus foto master wajah" icon="trash-outline" variant="danger" onPress={confirmRemove} loading={remove.isPending} /> : null}
        </View>
      )}
    </Screen>
  );
}

const useStyles = makeStyles((c) => ({
  photoCard: { alignItems: 'center', gap: spacing.lg, paddingVertical: spacing.xl },
  photo: { width: 220, height: 220, borderRadius: 28, backgroundColor: c.fill },
  placeholder: { alignItems: 'center', gap: spacing.md, paddingVertical: spacing.lg },
  placeholderIcon: { width: 112, height: 112, borderRadius: 56, backgroundColor: c.fill, alignItems: 'center', justifyContent: 'center' },
  muted: { fontSize: fontSize.sm, color: c.textMuted, textAlign: 'center' },
  fullWidth: { alignSelf: 'stretch' },
  tips: { fontSize: fontSize.sm, color: c.textMuted, textAlign: 'center', lineHeight: 20, paddingHorizontal: spacing.lg },
  row: { flexDirection: 'row', gap: spacing.sm },
  flex: { flex: 1 },
  actions: { gap: spacing.sm },
}));
