import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';

import { Badge, Button, EmptyState, ErrorBanner, Group, LoadingView, Row, Screen } from '@/components/ui';
import { ApiError, clientAdminApi, errorMessage, type CompanySummary } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';

function companyLine(company: CompanySummary): string {
  return [company.code, company.mode_label, `${company.locations_count} lokasi`, `${company.divisions_count} divisi`, `${company.workers_count} pekerja`].join(' · ');
}

/** Kelola Klien: the companies a PIC or Admin runs, plus their accounts, same rules as the web panel. */
export default function ClientHubScreen() {
  const overview = useQuery({ queryKey: queryKeys.clientOverview, queryFn: clientAdminApi.overview });

  if (overview.isPending) {
    return (
      <Screen>
        <LoadingView />
      </Screen>
    );
  }
  if (overview.isError && overview.error instanceof ApiError && overview.error.status === 404) {
    return (
      <Screen>
        <EmptyState icon="cloud-offline-outline" title="Belum tersedia" message="Fitur ini belum tersedia di server." />
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

  const { permissions, tenant, companies } = overview.data;

  return (
    <Screen onRefresh={() => overview.refetch()}>
      {tenant ? (
        <Group title="Klien">
          <Row icon="business-outline" title={tenant.name} subtitle={permissions.is_pic ? 'Anda PIC Klien ini' : 'Anda Admin Klien ini'} />
        </Group>
      ) : null}

      {permissions.can_manage_accounts ? (
        <Group title="Akses">
          <Row icon="people-outline" title="Akun & Akses" subtitle="Buat akun, atur peran dan cakupan data" onPress={() => router.push('/client/accounts')} />
        </Group>
      ) : null}

      {permissions.can_manage_companies ? (
        companies.length > 0 ? (
          <Group title="Badan usaha">
            {companies.map((company) => (
              <Row
                key={company.id}
                icon="storefront-outline"
                title={company.name}
                subtitle={companyLine(company)}
                right={company.is_active ? undefined : <Badge label="Nonaktif" tone="gray" />}
                onPress={() => router.push({ pathname: '/client/company/[id]', params: { id: String(company.id) } })}
              />
            ))}
          </Group>
        ) : (
          <EmptyState icon="storefront-outline" title="Belum ada badan usaha" message={permissions.can_create_company ? 'Tambahkan badan usaha pertama untuk Klien ini.' : undefined} />
        )
      ) : null}

      {permissions.can_create_company ? (
        <Button title="Tambah Badan Usaha" icon="add" variant="secondary" onPress={() => router.push('/client/company/new')} />
      ) : null}
    </Screen>
  );
}
