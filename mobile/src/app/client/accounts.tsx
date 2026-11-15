import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { View } from 'react-native';

import { workerLine } from '@/components/client/AccountForm';
import { Badge, Button, EmptyState, ErrorBanner, Field, Group, LoadingView, Pill, Row, Screen, Text } from '@/components/ui';
import { type ClientAccount, clientAdminApi, errorMessage } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import { fontSize, makeStyles, spacing } from '@/theme';

function AccountRow({ account }: { account: ClientAccount }) {
  const styles = useStyles();
  const worker = workerLine(account.worker);
  return (
    <View>
      <Row
        title={account.name}
        subtitle={[account.email, account.data_scope_label, worker].filter(Boolean).join(' · ')}
        right={account.is_active ? undefined : <Badge label="Nonaktif" tone="gray" />}
        onPress={() => router.push({ pathname: '/client/account/[id]', params: { id: String(account.id) } })}
      />
      {account.roles.length > 0 ? (
        <View style={styles.roles}>
          {account.roles.map((role) => (
            <Pill key={role} label={role} />
          ))}
        </View>
      ) : null}
    </View>
  );
}

/** Akun & Akses: every login of the Klien this PIC or Admin may manage, same scope as the web panel. */
export default function ClientAccountsScreen() {
  const styles = useStyles();
  const [search, setSearch] = useState('');
  const [term, setTerm] = useState('');
  const [page, setPage] = useState(1);

  useEffect(() => {
    const timer = setTimeout(() => {
      setTerm(search.trim());
      setPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [search]);

  const list = useQuery({
    queryKey: queryKeys.clientAccounts(term, page),
    queryFn: () => clientAdminApi.accounts(term, page),
    placeholderData: keepPreviousData,
  });

  const pagination = list.data?.pagination;

  return (
    <Screen onRefresh={() => list.refetch()}>
      <Button title="Tambah Akun" icon="person-add" onPress={() => router.push('/client/account/new')} />
      <Field label="Cari" value={search} onChangeText={setSearch} placeholder="Nama, email, atau HP" autoCapitalize="none" autoCorrect={false} />

      {list.isPending ? (
        <LoadingView />
      ) : list.isError ? (
        <ErrorBanner message={errorMessage(list.error)} onRetry={() => list.refetch()} />
      ) : list.data.data.length === 0 ? (
        <EmptyState icon="people-outline" title={term ? 'Akun tidak ditemukan' : 'Belum ada akun'} message={term ? 'Coba kata kunci lain.' : 'Tambahkan akun untuk tim Klien ini.'} />
      ) : (
        <>
          <Group title={`${list.data.pagination.total} akun`} separated>
            {list.data.data.map((account) => (
              <AccountRow key={account.id} account={account} />
            ))}
          </Group>
          {pagination && pagination.last_page > 1 ? (
            <View style={styles.pager}>
              <Button title="Sebelumnya" icon="chevron-back" variant="secondary" size="sm" disabled={page <= 1 || list.isFetching} onPress={() => setPage((current) => Math.max(1, current - 1))} />
              <Text style={styles.pageText}>
                {pagination.current_page} / {pagination.last_page}
              </Text>
              <Button
                title="Berikutnya"
                icon="chevron-forward"
                variant="secondary"
                size="sm"
                disabled={page >= pagination.last_page || list.isFetching}
                onPress={() => setPage((current) => current + 1)}
              />
            </View>
          ) : null}
        </>
      )}
    </Screen>
  );
}

const useStyles = makeStyles((c) => ({
  roles: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, paddingHorizontal: 16, paddingBottom: 12, marginTop: -6 },
  pager: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
  pageText: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '500' },
}));
