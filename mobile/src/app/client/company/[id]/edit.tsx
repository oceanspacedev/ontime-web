import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { Alert } from 'react-native';

import { CompanyForm } from '@/components/client/CompanyForm';
import { EmptyState, ErrorBanner, LoadingView, Screen } from '@/components/ui';
import { clientAdminApi, errorMessage, type CompanyInput } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';

/** Edits a company's details, prefilled from the overview since there is no single-company endpoint. */
export default function EditClientCompanyScreen() {
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

  return (
    <CompanyForm
      key={company.id}
      company={company}
      submitLabel="Simpan"
      onSubmit={(input: CompanyInput) => clientAdminApi.updateCompany(company.id, input)}
      onSaved={(response) => {
        Alert.alert('Tersimpan', response.message, [{ text: 'OK', onPress: () => router.back() }]);
      }}
    />
  );
}
