import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';

import { DivisionTreeRow, flattenDivisions } from '@/components/client/DivisionTree';
import { Button, EmptyState, ErrorBanner, Group, LoadingView, Screen } from '@/components/ui';
import { clientAdminApi, errorMessage } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';

/** The divisions of one company as a tree; tap one to edit it. */
export default function ClientDivisionsScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const companyId = Number(id);
  const divisions = useQuery({ queryKey: queryKeys.clientDivisions(companyId), queryFn: () => clientAdminApi.divisions(companyId) });
  const nodes = useMemo(() => flattenDivisions(divisions.data?.data ?? []), [divisions.data]);

  const openEditor = (divisionId?: number) =>
    router.push({ pathname: '/client/company/[id]/division', params: divisionId ? { id: String(companyId), divisionId: String(divisionId) } : { id: String(companyId) } });

  if (divisions.isPending) {
    return (
      <Screen>
        <LoadingView />
      </Screen>
    );
  }
  if (divisions.isError) {
    return (
      <Screen>
        <ErrorBanner message={errorMessage(divisions.error)} onRetry={() => divisions.refetch()} />
      </Screen>
    );
  }

  return (
    <Screen onRefresh={() => divisions.refetch()} footer={<Button title="Tambah Divisi" icon="add" size="lg" onPress={() => openEditor()} />}>
      {nodes.length === 0 ? (
        <EmptyState icon="git-network-outline" title="Belum ada divisi" message="Tambahkan divisi untuk mengelompokkan pekerja dan cakupan akses." />
      ) : (
        <Group title={`${nodes.length} divisi`}>
          {nodes.map((node) => (
            <DivisionTreeRow key={node.division.id} node={node} onPress={() => openEditor(node.division.id)} />
          ))}
        </Group>
      )}
    </Screen>
  );
}
