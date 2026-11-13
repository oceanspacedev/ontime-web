import { request } from './client';
import type {
  NewFreelancer,
  NewFreelancerResponse,
  Paginated,
  ScheduleChange,
  TeamFreelancer,
  TeamOptions,
  TeamRecap,
  TeamRoster,
  TeamWeek,
  TeamWorker,
} from './types';

export function roster(date: string): Promise<TeamRoster> {
  return request<TeamRoster>('/team/roster', { query: { date } });
}

/** The week (Monday to Sunday) holding `date`. */
export function week(date: string): Promise<TeamWeek> {
  return request<TeamWeek>('/team/week', { query: { date } });
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

/** `month` is YYYY-MM. */
export function recap(month: string): Promise<TeamRecap> {
  return request<TeamRecap>('/team/recap', { query: { month } });
}

/** A signed link, valid for 15 minutes, that downloads the recap as Excel in the browser. */
export function recapLink(month: string): Promise<{ url: string; expires_in: number }> {
  return request<{ url: string; expires_in: number }>('/team/recap/link', { query: { month } });
}

/** Copies the week holding `from` onto the week holding `to` (both YYYY-MM-DD); days already filled are kept. */
export function copySchedules(from: string, to: string): Promise<{ message: string; data: { copied: number } }> {
  return request<{ message: string; data: { copied: number } }>('/team/schedules/copy', { method: 'POST', json: { from, to } });
}
