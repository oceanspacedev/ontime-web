import { useMemo } from 'react';
import { Pressable, View } from 'react-native';

import { Choice, Field, Group, Notice, Text } from '@/components/ui';
import type { AccountOptions, ClientAccount, DataScope, WorkerType } from '@/lib/api';
import { type FieldErrors, requiredMessage } from '@/lib/forms';
import { fontSize, makeStyles, radius, spacing } from '@/theme';

export interface AccountFormState {
  name: string;
  email: string;
  phone: string;
  roles: string[];
  dataScope: DataScope | null;
  companyIds: number[];
  divisionIds: number[];
}

export const EMPTY_ACCOUNT_FORM: AccountFormState = { name: '', email: '', phone: '', roles: [], dataScope: null, companyIds: [], divisionIds: [] };

export function accountFormFrom(account: ClientAccount): AccountFormState {
  return {
    name: account.name,
    email: account.email,
    phone: account.phone ?? '',
    roles: account.roles,
    dataScope: account.data_scope,
    companyIds: account.companies.map((company) => company.id),
    divisionIds: account.divisions.map((division) => division.id),
  };
}

/** Only the ids that belong to the chosen scope are sent, so switching scope never leaves stale access. */
export function accountPayload(form: AccountFormState): {
  name: string;
  phone: string | null;
  roles: string[];
  data_scope: DataScope;
  company_ids: number[];
  division_ids: number[];
} {
  return {
    name: form.name.trim(),
    phone: form.phone.trim() || null,
    roles: form.roles,
    data_scope: form.dataScope ?? 'own',
    company_ids: form.dataScope === 'companies' ? form.companyIds : [],
    division_ids: form.dataScope === 'divisions' ? form.divisionIds : [],
  };
}

export function validateAccountForm(form: AccountFormState, withEmail: boolean): FieldErrors {
  const next: FieldErrors = {};
  if (!form.name.trim()) {
    next.name = requiredMessage('Nama');
  }
  if (withEmail && !form.email.trim()) {
    next.email = requiredMessage('Email');
  }
  if (form.roles.length === 0) {
    next.roles = 'Pilih minimal satu peran.';
  }
  if (!form.dataScope) {
    next.data_scope = 'Pilih cakupan data.';
  }
  if (form.dataScope === 'companies' && form.companyIds.length === 0) {
    next.company_ids = 'Pilih minimal satu badan usaha.';
  }
  if (form.dataScope === 'divisions' && form.divisionIds.length === 0) {
    next.division_ids = 'Pilih minimal satu divisi.';
  }
  return next;
}

const WORKER_TYPE_LABELS: Record<WorkerType, string> = { employee: 'Karyawan', intern: 'Magang', freelancer: 'Freelancer' };

export function workerLine(worker: ClientAccount['worker']): string | null {
  if (!worker) {
    return null;
  }
  return [WORKER_TYPE_LABELS[worker.type] ?? worker.type, worker.name, worker.company].filter(Boolean).join(' · ');
}

/** The first message for a field, including array keys like `company_ids.0`. */
function errorFor(errors: FieldErrors, key: string): string | undefined {
  return errors[key] ?? Object.entries(errors).find(([field]) => field.startsWith(`${key}.`))?.[1];
}

/** Wrapping chips where any number can be on. */
export function MultiChoice<T extends string | number>({ options, values, onChange }: { options: { value: T; label: string }[]; values: T[]; onChange: (values: T[]) => void }) {
  const styles = useStyles();
  return (
    <View style={styles.row}>
      {options.map((option) => {
        const active = values.includes(option.value);
        return (
          <Pressable
            key={String(option.value)}
            onPress={() => onChange(active ? values.filter((value) => value !== option.value) : [...values, option.value])}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: active }}
            style={({ pressed }) => [styles.chip, active && styles.chipActive, pressed && !active && styles.chipPressed]}>
            <Text style={[styles.text, active && styles.textActive]}>{option.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/** Name, contact, roles and data scope of an account; the same fields as the web panel. */
export function AccountFormFields({
  form,
  onChange,
  options,
  errors,
  emailEditable,
}: {
  form: AccountFormState;
  onChange: (next: AccountFormState) => void;
  options: AccountOptions;
  errors: FieldErrors;
  emailEditable: boolean;
}) {
  const styles = useStyles();
  const set = <K extends keyof AccountFormState>(key: K, value: AccountFormState[K]) => onChange({ ...form, [key]: value });
  const scope = options.scopes.find((item) => item.value === form.dataScope);

  /** Roles the account already has stay visible even when this admin may not hand them out. */
  const roleOptions = useMemo(() => Array.from(new Set([...options.roles, ...form.roles])).map((role) => ({ value: role, label: role })), [options.roles, form.roles]);

  const divisionGroups = useMemo(() => {
    const companyName = new Map(options.companies.map((company) => [company.id, company.name]));
    const groups = new Map<number, { title: string; items: { value: number; label: string }[] }>();
    for (const division of options.divisions) {
      const group = groups.get(division.company_id) ?? { title: companyName.get(division.company_id) ?? 'Badan usaha lain', items: [] };
      group.items.push({ value: division.id, label: division.label });
      groups.set(division.company_id, group);
    }
    return Array.from(groups.values());
  }, [options.companies, options.divisions]);

  return (
    <>
      <Group title="Data akun" padded>
        <Field label="Nama" value={form.name} onChangeText={(value) => set('name', value)} autoCapitalize="words" error={errors.name} maxLength={255} />
        <Field
          label="Email"
          value={form.email}
          onChangeText={(value) => set('email', value)}
          editable={emailEditable}
          keyboardType="email-address"
          autoCapitalize="none"
          autoCorrect={false}
          hint={emailEditable ? 'Dipakai untuk login. Tidak bisa diubah setelah dibuat.' : 'Email tidak bisa diubah.'}
          error={errors.email}
          maxLength={255}
        />
        <Field label="Nomor HP" value={form.phone} onChangeText={(value) => set('phone', value)} keyboardType="phone-pad" placeholder="08…" hint="Akun login dikirim lewat WhatsApp ke nomor ini." error={errors.phone} maxLength={50} />
      </Group>

      <Group title="Peran" padded>
        {roleOptions.length === 0 ? <Notice tone="warning" message="Tidak ada peran yang bisa Anda berikan." /> : <MultiChoice options={roleOptions} values={form.roles} onChange={(value) => set('roles', value)} />}
        {errorFor(errors, 'roles') ? <Notice tone="danger" message={errorFor(errors, 'roles') ?? ''} /> : null}
      </Group>

      <Group title="Cakupan data" padded>
        <Choice options={options.scopes} value={form.dataScope} onChange={(value) => set('dataScope', value)} />
        {scope?.description ? <Text style={styles.description}>{scope.description}</Text> : null}
        {errors.data_scope ? <Notice tone="danger" message={errors.data_scope} /> : null}
      </Group>

      {form.dataScope === 'companies' ? (
        <Group title="Badan usaha" padded>
          {options.companies.length === 0 ? (
            <Notice tone="warning" message="Belum ada badan usaha yang bisa Anda berikan." />
          ) : (
            <MultiChoice options={options.companies.map((company) => ({ value: company.id, label: company.name }))} values={form.companyIds} onChange={(value) => set('companyIds', value)} />
          )}
          {errorFor(errors, 'company_ids') ? <Notice tone="danger" message={errorFor(errors, 'company_ids') ?? ''} /> : null}
        </Group>
      ) : null}

      {form.dataScope === 'divisions' ? (
        <Group title="Divisi" padded>
          {divisionGroups.length === 0 ? (
            <Notice tone="warning" message="Belum ada divisi yang bisa Anda berikan." />
          ) : (
            divisionGroups.map((group) => (
              <View key={group.title} style={styles.divisionGroup}>
                {divisionGroups.length > 1 ? <Text style={styles.groupTitle}>{group.title}</Text> : null}
                <MultiChoice options={group.items} values={form.divisionIds} onChange={(value) => set('divisionIds', value)} />
              </View>
            ))
          )}
          {errorFor(errors, 'division_ids') ? <Notice tone="danger" message={errorFor(errors, 'division_ids') ?? ''} /> : null}
        </Group>
      ) : null}
    </>
  );
}

const useStyles = makeStyles((c) => ({
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  chip: { paddingHorizontal: 16, paddingVertical: 10, borderRadius: radius.pill, backgroundColor: c.fill },
  chipPressed: { backgroundColor: c.fillPressed },
  chipActive: {
    backgroundColor: c.primary,
    boxShadow: 'inset 0px 1px 0px rgba(255,255,255,0.3), 0px 4px 10px rgba(242,41,41,0.28)',
  },
  text: { fontSize: fontSize.sm, color: c.text, fontWeight: '500' },
  textActive: { color: c.onPrimary, fontWeight: '600' },
  description: { fontSize: fontSize.sm, color: c.textMuted, lineHeight: 19, paddingHorizontal: 4 },
  divisionGroup: { gap: spacing.sm },
  groupTitle: { fontSize: fontSize.sm, color: c.textMuted, fontWeight: '600', paddingHorizontal: 4 },
}));
