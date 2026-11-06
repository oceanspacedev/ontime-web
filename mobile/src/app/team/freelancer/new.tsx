import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';

import { DateTimeField } from '@/components/DateTimeField';
import { Button, Choice, ErrorBanner, Field, Group, LoadingView, Notice, Screen } from '@/components/ui';
import { showNewAccount } from '@/lib/accountNotice';
import { ApiError, errorMessage, teamApi } from '@/lib/api';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import { toDateString } from '@/lib/format';
import { queryKeys } from '@/lib/queryClient';

/** Name, phone and outlet are enough: the number and the login account are made for them. */
export default function NewFreelancerScreen() {
  const queryClient = useQueryClient();
  const options = useQuery({ queryKey: queryKeys.teamOptions, queryFn: teamApi.options });
  const outlets = useMemo(() => options.data?.companies.flatMap((company) => company.outlets) ?? [], [options.data]);

  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [outletId, setOutletId] = useState<string | null>(null);
  const [startDate, setStartDate] = useState(toDateString(new Date()));
  const [errors, setErrors] = useState<FieldErrors>({});

  const submit = useMutation({
    mutationFn: () => teamApi.addFreelancer({ full_name: name.trim(), phone: phone.trim(), company_location_id: outletId ? Number(outletId) : null, start_date: startDate }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.team });
      showNewAccount(response.data.name, response.account, response.message, () => router.back(), {
        text: 'Atur jadwal',
        onPress: () => router.replace({ pathname: '/team/assign', params: { search: response.data.name } }),
      });
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const validate = (): boolean => {
    const next: FieldErrors = {};
    if (!name.trim()) {
      next.full_name = requiredMessage('Nama');
    }
    if (!phone.trim()) {
      next.phone = requiredMessage('Nomor HP');
    }
    if (!outletId) {
      next.company_location_id = 'Pilih outlet.';
    }
    setErrors(next);
    return Object.keys(next).length === 0;
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
          <Button title="Tambah" icon="person-add" size="lg" onPress={() => validate() && submit.mutate()} loading={submit.isPending} />
        </>
      }>
      <Group title="Data" padded>
        <Field label="Nama" value={name} onChangeText={setName} autoCapitalize="words" error={errors.full_name} maxLength={255} />
        <Field label="Nomor HP" value={phone} onChangeText={setPhone} keyboardType="phone-pad" placeholder="08…" hint="Akun login dikirim lewat WhatsApp." error={errors.phone} maxLength={50} />
        <DateTimeField label="Mulai kerja" mode="date" value={startDate} onChange={setStartDate} error={errors.start_date} />
      </Group>
      <Group title="Outlet" padded>
        {outlets.length === 0 ? (
          <Notice tone="warning" message="Belum ada outlet untuk brand Anda. Minta admin mengisinya di Lokasi & Cabang." />
        ) : (
          <Choice options={outlets.map((outlet) => ({ value: String(outlet.id), label: outlet.name }))} value={outletId} onChange={setOutletId} />
        )}
        {errors.company_location_id ? <Notice tone="danger" message={errors.company_location_id} /> : null}
      </Group>
    </Screen>
  );
}
