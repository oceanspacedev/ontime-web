import { useMutation, useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';

import { AccountCredentialsSheet } from '@/components/client/AccountCredentialsSheet';
import { AccountFormFields, accountPayload, EMPTY_ACCOUNT_FORM, validateAccountForm } from '@/components/client/AccountForm';
import { Button, ErrorBanner, LoadingView, Notice, Screen } from '@/components/ui';
import { useClientAdminSaved } from '@/hooks/useClientAdminSaved';
import { type AccountCredentials, ApiError, clientAdminApi, errorMessage } from '@/lib/api';
import { type FieldErrors, fieldErrorsFrom } from '@/lib/forms';
import { queryKeys } from '@/lib/queryClient';

/** A new login for the Klien. The password is random and shown once after saving. */
export default function NewClientAccountScreen() {
  const onClientSaved = useClientAdminSaved();
  const options = useQuery({ queryKey: queryKeys.clientAccountOptions, queryFn: clientAdminApi.accountOptions });

  const [form, setForm] = useState(EMPTY_ACCOUNT_FORM);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [credentials, setCredentials] = useState<AccountCredentials | null>(null);

  const submit = useMutation({
    mutationFn: () => clientAdminApi.createAccount({ ...accountPayload(form), email: form.email.trim() }),
    onSuccess: async (response) => {
      setErrors({});
      await onClientSaved();
      setCredentials(response.credentials);
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const save = () => {
    const next = validateAccountForm(form, true);
    setErrors(next);
    if (Object.keys(next).length === 0) {
      submit.mutate();
    }
  };

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
          <Button title="Simpan" icon="person-add" size="lg" onPress={save} loading={submit.isPending} disabled={credentials !== null} />
        </>
      }>
      <AccountFormFields form={form} onChange={setForm} options={options.data} errors={errors} emailEditable />
      <AccountCredentialsSheet
        credentials={credentials}
        title="Akun dibuat"
        onClose={() => {
          setCredentials(null);
          router.back();
        }}
      />
    </Screen>
  );
}
