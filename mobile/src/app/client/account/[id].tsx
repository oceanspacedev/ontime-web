import { type QueryClient, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Alert, Switch } from 'react-native';

import { AccountCredentialsSheet } from '@/components/client/AccountCredentialsSheet';
import { accountFormFrom, AccountFormFields, accountPayload, validateAccountForm, workerLine } from '@/components/client/AccountForm';
import { Badge, Button, EmptyState, ErrorBanner, Group, InfoRow, LoadingView, Notice, Row, Screen } from '@/components/ui';
import { useClientAdminSaved } from '@/hooks/useClientAdminSaved';
import { type AccountCredentials, ApiError, type ClientAccount, type ClientAccountList, clientAdminApi, errorMessage } from '@/lib/api';
import { type FieldErrors, fieldErrorsFrom } from '@/lib/forms';
import { queryKeys } from '@/lib/queryClient';
import { useTheme } from '@/theme';

/** Any loaded page of the list, shown while the account itself loads. */
function findCachedAccount(queryClient: QueryClient, id: number): ClientAccount | null {
  for (const [, data] of queryClient.getQueriesData<ClientAccountList>({ queryKey: [...queryKeys.client, 'accounts'] })) {
    const found = Array.isArray(data?.data) ? data.data.find((account) => account.id === id) : undefined;
    if (found) {
      return found;
    }
  }
  return null;
}

/** Shown when the admin may see the account but not change it, like their own login. */
function ReadOnlyAccount({ account }: { account: ClientAccount }) {
  return (
    <>
      <Notice tone="info" message="Anda tidak bisa mengubah akun ini. Akun sendiri dan akun dengan wewenang lebih tinggi hanya bisa diubah oleh pihak lain." />
      <Group title="Akun">
        <Row icon="person-circle-outline" title={account.name} subtitle={account.email} right={<Badge label={account.is_active ? 'Aktif' : 'Nonaktif'} tone={account.is_active ? 'success' : 'gray'} />} />
        <InfoRow label="Nomor HP" value={account.phone ?? '-'} />
        <InfoRow label="Peran" value={account.roles.join(', ') || '-'} />
        <InfoRow label="Cakupan data" value={account.data_scope_label} />
        {account.companies.length > 0 ? <InfoRow label="Badan usaha" value={account.companies.map((company) => company.name).join(', ')} /> : null}
        {account.divisions.length > 0 ? <InfoRow label="Divisi" value={account.divisions.map((division) => division.name).join(', ')} /> : null}
        {account.worker ? <InfoRow label="Pekerja" value={workerLine(account.worker) ?? '-'} /> : null}
      </Group>
    </>
  );
}

function EditAccount({ account }: { account: ClientAccount }) {
  const onClientSaved = useClientAdminSaved();
  const { colors } = useTheme();
  const options = useQuery({ queryKey: queryKeys.clientAccountOptions, queryFn: clientAdminApi.accountOptions });

  const [form, setForm] = useState(() => accountFormFrom(account));
  const [isActive, setIsActive] = useState(account.is_active);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [credentials, setCredentials] = useState<AccountCredentials | null>(null);

  const submit = useMutation({
    mutationFn: () => clientAdminApi.updateAccount(account.id, { ...accountPayload(form), is_active: isActive }),
    onSuccess: async (response) => {
      setErrors({});
      await onClientSaved();
      Alert.alert('Berhasil', response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const reset = useMutation({
    mutationFn: () => clientAdminApi.resetPassword(account.id),
    onSuccess: (response) => setCredentials(response.credentials),
    onError: (error) => Alert.alert('Gagal', errorMessage(error)),
  });

  const save = () => {
    const next = validateAccountForm(form, false);
    setErrors(next);
    if (Object.keys(next).length === 0) {
      submit.mutate();
    }
  };

  const confirmReset = () =>
    Alert.alert('Reset Kata Sandi', `Buat kata sandi baru untuk ${account.name}? Kata sandi lama langsung tidak berlaku.`, [
      { text: 'Batal', style: 'cancel' },
      { text: 'Reset', style: 'destructive', onPress: () => reset.mutate() },
    ]);

  const generalError = submit.isError && !(submit.error instanceof ApiError && submit.error.errors) ? errorMessage(submit.error) : null;

  if (options.isPending) {
    return (
      <Screen>
        <LoadingView />
      </Screen>
    );
  }
  if (options.isError) {
    return (
      <Screen>
        <ErrorBanner message={errorMessage(options.error)} onRetry={() => options.refetch()} />
      </Screen>
    );
  }

  return (
    <Screen
      footer={
        <>
          {generalError ? <Notice tone="danger" message={generalError} /> : null}
          <Button title="Simpan" icon="checkmark" size="lg" onPress={save} loading={submit.isPending} />
        </>
      }>
      {account.worker ? (
        <Group title="Pekerja">
          <Row icon="id-card-outline" title={account.worker.name} subtitle={workerLine(account.worker) ?? undefined} />
        </Group>
      ) : null}

      <AccountFormFields form={form} onChange={setForm} options={options.data} errors={errors} emailEditable={false} />

      <Group title="Status" footer={isActive ? undefined : 'Akun nonaktif tidak bisa login.'}>
        <Row
          icon="power-outline"
          title="Akun aktif"
          right={<Switch value={isActive} onValueChange={setIsActive} trackColor={{ true: colors.primary, false: colors.fill }} accessibilityLabel="Akun aktif" />}
        />
      </Group>
      {errors.is_active ? <Notice tone="danger" message={errors.is_active} /> : null}

      <Button title="Reset Kata Sandi" icon="key-outline" variant="danger" onPress={confirmReset} loading={reset.isPending} />

      <AccountCredentialsSheet credentials={credentials} title="Kata sandi baru" onClose={() => setCredentials(null)} />
    </Screen>
  );
}

/** Ubah Akun: roles, data scope and status of one login; read-only (Detail Akun) when the server says it cannot be edited. */
export default function EditClientAccountScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const accountId = Number(id);
  const isValidId = Number.isInteger(accountId) && accountId > 0;
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: queryKeys.clientAccount(accountId),
    queryFn: () => clientAdminApi.account(accountId),
    enabled: isValidId,
    placeholderData: () => {
      const cached = findCachedAccount(queryClient, accountId);
      return cached ? { data: cached } : undefined;
    },
  });

  /** An older server has no single-account endpoint; fall back to the list data it did load. */
  const account = query.data?.data ?? (query.isError ? findCachedAccount(queryClient, accountId) : null);
  const title = <Stack.Screen options={{ title: account && !account.can_edit ? 'Detail Akun' : 'Ubah Akun' }} />;

  if (isValidId && query.isPending) {
    return (
      <Screen>
        {title}
        <LoadingView />
      </Screen>
    );
  }

  const notFound = !isValidId || (query.error instanceof ApiError && query.error.status === 404);
  if (!account && notFound) {
    return (
      <Screen>
        {title}
        <EmptyState
          icon="person-outline"
          title="Akun tidak ditemukan"
          message="Akun ini tidak ada atau bukan wewenang Anda."
          action={<Button title="Ke Akun & Akses" variant="secondary" onPress={() => router.replace('/client/accounts')} />}
        />
      </Screen>
    );
  }
  if (!account) {
    return (
      <Screen>
        {title}
        <ErrorBanner message={errorMessage(query.error)} onRetry={() => query.refetch()} />
      </Screen>
    );
  }

  if (!account.can_edit) {
    return (
      <Screen onRefresh={() => query.refetch()}>
        {title}
        <ReadOnlyAccount account={account} />
      </Screen>
    );
  }

  /** Keyed by the loaded data so the form starts over once the fresh account replaces the cached one. */
  return (
    <>
      {title}
      <EditAccount key={query.isSuccess && !query.isPlaceholderData ? 'fresh' : 'cached'} account={account} />
    </>
  );
}
