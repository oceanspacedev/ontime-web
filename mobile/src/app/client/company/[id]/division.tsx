import { useMutation, useQuery, type UseQueryResult } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { Alert, ScrollView, Switch } from 'react-native';

import { divisionAndDescendantIds, flattenDivisions } from '@/components/client/DivisionTree';
import { Button, EmptyState, ErrorBanner, Field, Group, LoadingView, Notice, PickerField, Row, Screen, Sheet } from '@/components/ui';
import { useClientAdminSaved } from '@/hooks/useClientAdminSaved';
import { ApiError, clientAdminApi, errorMessage, type ClientDivision, type DivisionInput } from '@/lib/api';
import { type FieldErrors, fieldErrorsFrom, requiredMessage } from '@/lib/forms';
import { queryKeys } from '@/lib/queryClient';
import { useTheme } from '@/theme';

/** Adds a division, or edits one when `divisionId` is passed. */
export default function ClientDivisionScreen() {
  const { id, divisionId } = useLocalSearchParams<{ id: string; divisionId?: string }>();
  const companyId = Number(id);
  const editingId = divisionId ? Number(divisionId) : null;
  const divisions = useQuery({ queryKey: queryKeys.clientDivisions(companyId), queryFn: () => clientAdminApi.divisions(companyId) });

  return (
    <>
      <Stack.Screen options={{ title: editingId ? 'Ubah Divisi' : 'Tambah Divisi' }} />
      <DivisionContent companyId={companyId} editingId={editingId} divisions={divisions} />
    </>
  );
}

function DivisionContent({ companyId, editingId, divisions }: { companyId: number; editingId: number | null; divisions: UseQueryResult<{ data: ClientDivision[] }> }) {

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

  const division = editingId ? divisions.data.data.find((item) => item.id === editingId) : undefined;
  if (editingId && !division) {
    return (
      <Screen>
        <EmptyState icon="git-network-outline" title="Divisi tidak ditemukan" message="Divisi ini tidak ada atau bukan wewenang Anda." />
      </Screen>
    );
  }

  return <DivisionForm key={division?.id ?? 'new'} companyId={companyId} division={division} divisions={divisions.data.data} />;
}

function DivisionForm({ companyId, division, divisions }: { companyId: number; division?: ClientDivision; divisions: ClientDivision[] }) {
  const onClientSaved = useClientAdminSaved();
  const { colors } = useTheme();

  const [name, setName] = useState(division?.name ?? '');
  const [code, setCode] = useState(division?.code ?? '');
  const [parentId, setParentId] = useState<number | null>(division?.parent_id ?? null);
  const [isActive, setIsActive] = useState(division?.is_active ?? true);
  const [pickingParent, setPickingParent] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});

  /** Same company only; a division can never sit under itself or under one of its own children. */
  const parentOptions = useMemo(() => {
    const blocked = division ? divisionAndDescendantIds(divisions, division.id) : new Set<number>();
    return flattenDivisions(divisions.filter((item) => item.company_id === companyId && !blocked.has(item.id)));
  }, [companyId, division, divisions]);
  const parent = divisions.find((item) => item.id === parentId);

  const submit = useMutation({
    mutationFn: (input: DivisionInput) => (division ? clientAdminApi.updateDivision(division.id, input) : clientAdminApi.createDivision(companyId, input)),
    onSuccess: async (response) => {
      await onClientSaved();
      Alert.alert(division ? 'Tersimpan' : 'Divisi ditambahkan', response.message, [{ text: 'OK', onPress: () => router.back() }]);
    },
    onError: (error) => setErrors(fieldErrorsFrom(error)),
  });

  const handleSubmit = () => {
    const next: FieldErrors = {};
    if (!name.trim()) {
      next.name = requiredMessage('Nama');
    }
    setErrors(next);
    if (Object.keys(next).length > 0) {
      return;
    }
    const trimmedCode = code.trim();
    submit.mutate({ name: name.trim(), code: trimmedCode === '' ? null : trimmedCode, parent_id: parentId, is_active: isActive });
  };

  const generalError = submit.isError && !(submit.error instanceof ApiError && submit.error.errors) ? errorMessage(submit.error) : null;

  return (
    <Screen
      footer={
        <>
          {generalError ? <Notice tone="danger" message={generalError} /> : null}
          <Button title={division ? 'Simpan' : 'Tambah Divisi'} icon="checkmark" size="lg" onPress={handleSubmit} loading={submit.isPending} />
        </>
      }>
      <Group title="Data divisi" padded>
        <Field label="Nama" value={name} onChangeText={setName} autoCapitalize="words" placeholder="Operasional" error={errors.name} maxLength={255} />
        <Field label="Kode" value={code} onChangeText={setCode} autoCapitalize="characters" autoCorrect={false} placeholder="opsional" error={errors.code} maxLength={50} />
        <PickerField
          label="Induk divisi"
          value={parent ? parent.path || parent.name : null}
          placeholder="Tanpa induk (divisi utama)"
          onPress={() => setPickingParent(true)}
          error={errors.parent_id}
          hint="Hanya divisi di badan usaha yang sama."
        />
      </Group>

      <Group title="Status">
        <Row
          icon="power-outline"
          title="Aktif"
          subtitle={isActive ? 'Divisi bisa dipilih untuk pekerja dan akses.' : 'Divisi dinonaktifkan.'}
          right={<Switch value={isActive} onValueChange={setIsActive} trackColor={{ false: colors.fill, true: colors.primary }} thumbColor={colors.onPrimary} />}
        />
      </Group>
      {errors.is_active ? <Notice tone="danger" message={errors.is_active} /> : null}

      <Sheet visible={pickingParent} onClose={() => setPickingParent(false)} title="Pilih induk divisi">
        <ScrollView style={{ maxHeight: 420 }} keyboardShouldPersistTaps="handled">
          <Group>
            <Row
              icon={parentId === null ? 'radio-button-on' : 'radio-button-off'}
              iconColor={parentId === null ? colors.primary : undefined}
              title="Tanpa induk"
              subtitle="Jadikan divisi utama"
              onPress={() => {
                setParentId(null);
                setPickingParent(false);
              }}
            />
            {parentOptions.map(({ division: option, depth }) => (
              <Row
                key={option.id}
                icon={parentId === option.id ? 'radio-button-on' : 'radio-button-off'}
                iconColor={parentId === option.id ? colors.primary : undefined}
                title={`${'— '.repeat(Math.min(depth, 4))}${option.name}`}
                subtitle={option.is_active ? option.code ?? undefined : 'Nonaktif'}
                onPress={() => {
                  setParentId(option.id);
                  setPickingParent(false);
                }}
              />
            ))}
          </Group>
        </ScrollView>
      </Sheet>
    </Screen>
  );
}
