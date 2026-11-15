import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { Alert } from 'react-native';

import { CompanyForm } from '@/components/client/CompanyForm';
import { EmptyState, ErrorBanner, LoadingView, Screen } from '@/components/ui';
import { clientAdminApi, errorMessage } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';

/** Adds a company to the Klien; only a PIC (or whoever may create companies) gets here. */
export default function NewClientCompanyScreen() {
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
  if (!overview.data.permissions.can_create_company) {
    return (
      <Screen>
        <EmptyState icon="lock-closed-outline" title="Tidak bisa menambah badan usaha" message="Hanya PIC Klien yang bisa menambah badan usaha baru." />
      </Screen>
    );
  }

  return (
    <CompanyForm
      submitLabel="Tambah Badan Usaha"
      onSubmit={clientAdminApi.createCompany}
      onSaved={(response) => {
        Alert.alert('Badan usaha ditambahkan', response.message, [
          { text: 'OK', onPress: () => router.replace({ pathname: '/client/company/[id]', params: { id: String(response.data.id) } }) },
        ]);
      }}
    />
  );
}
