import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';

import { Badge, EmptyState, ErrorBanner, Group, InfoRow, LoadingView, Row, Screen } from '@/components/ui';
import { clientAdminApi, errorMessage } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';

/** One company of the Klien: its details and the settings a PIC or Admin runs from the app. */
export default function ClientCompanyScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const companyId = Number(id);
  const overview = useQuery({ queryKey: queryKeys.clientOverview, queryFn: clientAdminApi.overview });

  if (overview.isPending) {
    return (
      <Screen>
        <LoadingView />
      </Screen>
    );
  }
  if (overview.isError) {
    return (
      <Screen>
        <ErrorBanner message={errorMessage(overview.error)} onRetry={() => overview.refetch()} />
      </Screen>
    );
  }

  const company = overview.data.companies.find((item) => item.id === companyId);
  if (!company) {
    return (
      <Screen>
        <EmptyState icon="storefront-outline" title="Badan usaha tidak ditemukan" message="Badan usaha ini tidak ada atau bukan wewenang Anda." />
      </Screen>
    );
  }

  const params = { id: String(company.id) };

  return (
    <Screen onRefresh={() => overview.refetch()}>
      <Group title="Badan usaha">
        <Row icon="storefront-outline" title={company.name} subtitle={company.code} right={<Badge label={company.is_active ? 'Aktif' : 'Nonaktif'} tone={company.is_active ? 'success' : 'gray'} />} />
        <InfoRow label="Email" value={company.email ?? '-'} />
        <InfoRow label="Telepon" value={company.phone ?? '-'} />
        <InfoRow label="Alamat" value={company.address ?? '-'} />
        <InfoRow label="Pekerja" value={String(company.workers_count)} />
      </Group>

      <Group title="Pengaturan">
        <Row icon="shield-checkmark-outline" title="Kebijakan Absensi" subtitle={company.mode_label} onPress={() => router.push({ pathname: '/client/company/[id]/policy', params })} />
        <Row icon="location-outline" title="Lokasi Absensi" value={String(company.locations_count)} onPress={() => router.push({ pathname: '/client/company/[id]/locations', params })} />
        <Row icon="git-network-outline" title="Divisi" value={String(company.divisions_count)} onPress={() => router.push({ pathname: '/client/company/[id]/divisions', params })} />
        <Row icon="create-outline" title="Ubah Data Badan Usaha" onPress={() => router.push({ pathname: '/client/company/[id]/edit', params })} />
      </Group>
    </Screen>
  );
}
