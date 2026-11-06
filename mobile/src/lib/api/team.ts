import { request } from './client';
import type {
  NewFreelancer,
  NewFreelancerResponse,
  Paginated,
  ScheduleChange,
  TeamFreelancer,
  TeamOptions,
  TeamRoster,
  TeamWorker,
} from './types';

export function roster(date: string): Promise<TeamRoster> {
  return request<TeamRoster>('/team/roster', { query: { date } });
}

export function options(): Promise<TeamOptions> {
  return request<TeamOptions>('/team/options');
}

export function workers(search: string): Promise<{ data: TeamWorker[] }> {
  return request<{ data: TeamWorker[] }>('/team/workers', { query: { search: search || undefined } });
}

export function saveSchedule(change: ScheduleChange): Promise<{ message: string }> {
  return request<{ message: string }>('/team/schedules', { method: 'PUT', json: change });
}

export function freelancers(status: 'active' | 'ended', search: string): Promise<Paginated<TeamFreelancer>> {
  return request<Paginated<TeamFreelancer>>('/team/freelancers', { query: { status, search: search || undefined } });
}

export function addFreelancer(input: NewFreelancer): Promise<NewFreelancerResponse> {
  return request<NewFreelancerResponse>('/team/freelancers', { method: 'POST', json: input });
}

export function createAccount(id: number): Promise<NewFreelancerResponse> {
  return request<NewFreelancerResponse>(`/team/freelancers/${id}/account`, { method: 'POST' });
}

export function endFreelancer(id: number): Promise<{ message: string; data: TeamFreelancer }> {
  return request(`/team/freelancers/${id}/end`, { method: 'POST' });
}

export function reactivateFreelancer(id: number): Promise<{ message: string; data: TeamFreelancer }> {
  return request(`/team/freelancers/${id}/reactivate`, { method: 'POST' });
}
