import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { View } from 'react-native';

import { Badge, Button, EmptyState, ErrorBanner, Group, LoadingView, Screen, Text } from '@/components/ui';
import { clientAdminApi, type ClientLocation, errorMessage } from '@/lib/api';
import { openMaps } from '@/lib/geo';
import { queryKeys } from '@/lib/queryClient';
import { fontSize, makeStyles, spacing } from '@/theme';

/** The places a company's workers may clock in from. Locations are switched off, never deleted. */
export default function ClientLocationsScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const companyId = Number(id);
  const locations = useQuery({ queryKey: queryKeys.clientLocations(companyId), queryFn: () => clientAdminApi.locations(companyId) });
  const policy = useQuery({ queryKey: queryKeys.clientPolicy(companyId), queryFn: () => clientAdminApi.policy(companyId) });
  const policyRadius = policy.data?.data.geofence_radius_meters ?? null;

  const openEditor = (locationId?: number) =>
    router.push({ pathname: '/client/company/[id]/location', params: locationId ? { id: String(companyId), locationId: String(locationId) } : { id: String(companyId) } });

  const footer = <Button title="Tambah Lokasi" icon="add" size="lg" onPress={() => openEditor()} />;

  if (locations.isPending) {
    return (
      <Screen>
        <LoadingView />
      </Screen>
    );
  }
  if (locations.isError) {
    return (
      <Screen>
        <ErrorBanner message={errorMessage(locations.error)} onRetry={() => locations.refetch()} />
      </Screen>
    );
  }

  const items = locations.data.data;

  return (
    <Screen onRefresh={() => locations.refetch()} footer={footer}>
      {items.length === 0 ? (
        <EmptyState icon="location-outline" title="Belum ada lokasi absensi" message="Tambahkan titik kantor atau outlet agar pekerja bisa absen dengan GPS." />
      ) : (
        <Group title={`${items.length} lokasi`} footer="Lokasi tidak bisa dihapus dari aplikasi. Nonaktifkan lokasi yang sudah tidak dipakai.">
          {items.map((location) => (
            <LocationItem key={location.id} location={location} policyRadius={policyRadius} onEdit={() => openEditor(location.id)} />
          ))}
        </Group>
      )}
    </Screen>
  );
}

/** `policyRadius` is the company policy's geofence radius, which applies when the location has none of its own. */
function LocationItem({ location, policyRadius, onEdit }: { location: ClientLocation; policyRadius: number | null; onEdit: () => void }) {
  const styles = useStyles();
  const radiusLabel = location.radius_meters !== null ? `${location.radius_meters} m` : policyRadius !== null ? `${policyRadius} m (bawaan)` : 'radius kebijakan';

  return (
    <View style={styles.item}>
      <View style={styles.header}>
        <Text style={styles.name} numberOfLines={2}>
          {location.name}
        </Text>
        <Badge label={location.is_active ? 'Aktif' : 'Nonaktif'} tone={location.is_active ? 'success' : 'gray'} />
      </View>
      {location.address ? <Text style={styles.muted}>{location.address}</Text> : null}
      <Text style={styles.muted}>
        Radius {radiusLabel} · {location.latitude.toFixed(6)}, {location.longitude.toFixed(6)}
      </Text>
      <View style={styles.actions}>
        <Button title="Ubah" icon="create-outline" variant="secondary" size="sm" style={styles.action} onPress={onEdit} />
        <Button title="Lihat di Maps" icon="map-outline" variant="secondary" size="sm" style={styles.action} onPress={() => openMaps(location.latitude, location.longitude)} />
      </View>
    </View>
  );
}

const useStyles = makeStyles((c) => ({
  item: { padding: 16, gap: spacing.xs },
  header: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  name: { flex: 1, fontSize: fontSize.md, fontWeight: '600', color: c.text },
  muted: { fontSize: fontSize.sm, color: c.textMuted },
  actions: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.sm },
  action: { flex: 1 },
}));
