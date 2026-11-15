import { request } from './client';
import type {
  AccountOptions,
  ClientAccount,
  ClientAccountList,
  ClientDivision,
  ClientLocation,
  ClientOverview,
  ClientPolicy,
  ClientPolicyInput,
  ClientPolicyResponse,
  CompanyInput,
  CompanySummary,
  DivisionInput,
  LocationInput,
  NewAccountInput,
  NewAccountResponse,
  ResetPasswordResponse,
  SavedResponse,
  UpdateAccountInput,
} from './types';

/** What the signed-in PIC or Admin may do, their Klien and the companies they manage. */
export function overview(): Promise<ClientOverview> {
  return request<ClientOverview>('/client/overview');
}

export function createCompany(input: CompanyInput): Promise<SavedResponse<CompanySummary>> {
  return request<SavedResponse<CompanySummary>>('/client/companies', { method: 'POST', json: input });
}

export function updateCompany(id: number, input: CompanyInput): Promise<SavedResponse<CompanySummary>> {
  return request<SavedResponse<CompanySummary>>(`/client/companies/${id}`, { method: 'PUT', json: input });
}

export function policy(companyId: number): Promise<ClientPolicyResponse> {
  return request<ClientPolicyResponse>(`/client/companies/${companyId}/policy`);
}

export function savePolicy(companyId: number, input: ClientPolicyInput): Promise<SavedResponse<ClientPolicy>> {
  return request<SavedResponse<ClientPolicy>>(`/client/companies/${companyId}/policy`, { method: 'PUT', json: input });
}

export function locations(companyId: number): Promise<{ data: ClientLocation[] }> {
  return request<{ data: ClientLocation[] }>(`/client/companies/${companyId}/locations`);
}

export function createLocation(companyId: number, input: LocationInput): Promise<SavedResponse<ClientLocation>> {
  return request<SavedResponse<ClientLocation>>(`/client/companies/${companyId}/locations`, { method: 'POST', json: input });
}

/** There is no delete in the app; send `is_active: false` to switch a location off. */
export function updateLocation(id: number, input: LocationInput): Promise<SavedResponse<ClientLocation>> {
  return request<SavedResponse<ClientLocation>>(`/client/locations/${id}`, { method: 'PUT', json: input });
}

export function divisions(companyId: number): Promise<{ data: ClientDivision[] }> {
  return request<{ data: ClientDivision[] }>(`/client/companies/${companyId}/divisions`);
}

export function createDivision(companyId: number, input: DivisionInput): Promise<SavedResponse<ClientDivision>> {
  return request<SavedResponse<ClientDivision>>(`/client/companies/${companyId}/divisions`, { method: 'POST', json: input });
}

export function updateDivision(id: number, input: DivisionInput): Promise<SavedResponse<ClientDivision>> {
  return request<SavedResponse<ClientDivision>>(`/client/divisions/${id}`, { method: 'PUT', json: input });
}

export function accounts(search: string, page = 1): Promise<ClientAccountList> {
  return request<ClientAccountList>('/client/accounts', { query: { search: search || undefined, page } });
}

export function account(id: number): Promise<{ data: ClientAccount }> {
  return request<{ data: ClientAccount }>(`/client/accounts/${id}`);
}

export function accountOptions(): Promise<AccountOptions> {
  return request<AccountOptions>('/client/accounts/options');
}

/** The password is random; the credentials come back once and are sent over WhatsApp when possible. */
export function createAccount(input: NewAccountInput): Promise<NewAccountResponse> {
  return request<NewAccountResponse>('/client/accounts', { method: 'POST', json: input });
}

export function updateAccount(id: number, input: UpdateAccountInput): Promise<SavedResponse<ClientAccount>> {
  return request<SavedResponse<ClientAccount>>(`/client/accounts/${id}`, { method: 'PUT', json: input });
}

export function resetPassword(id: number): Promise<ResetPasswordResponse> {
  return request<ResetPasswordResponse>(`/client/accounts/${id}/reset-password`, { method: 'POST' });
}
