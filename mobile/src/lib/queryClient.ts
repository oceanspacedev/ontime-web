import { QueryClient } from '@tanstack/react-query';

import { ApiError } from './api/client';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30 * 1000,
      retry: (failureCount, error) => {
        if (error instanceof ApiError && error.status < 500) {
          return false;
        }
        return failureCount < 2;
      },
    },
  },
});

export const queryKeys = {
  today: ['attendance', 'today'] as const,
  attendances: (filter: Record<string, unknown>) => ['attendance', 'list', filter] as const,
  attendance: (id: number) => ['attendance', id] as const,
  report: (start: string, end: string) => ['report', start, end] as const,
  leaves: ['requests', 'leave'] as const,
  overtimes: ['requests', 'overtime'] as const,
  corrections: ['requests', 'correction'] as const,
  resignations: ['requests', 'resignation'] as const,
  requestDetail: (type: string, id: number) => ['requests', type, id] as const,
  approvals: ['approvals', 'pending'] as const,
  calendar: (month: number, year: number) => ['calendar', month, year] as const,
  team: ['team'] as const,
  teamRoster: (date: string) => ['team', 'roster', date] as const,
  teamWeek: (date: string) => ['team', 'week', date] as const,
  teamOptions: ['team', 'options'] as const,
  teamWorkers: (search: string) => ['team', 'workers', search] as const,
  teamFreelancers: (status: string, search: string) => ['team', 'freelancers', status, search] as const,
  teamRecap: (month: string) => ['team', 'recap', month] as const,
  client: ['client'] as const,
  clientOverview: ['client', 'overview'] as const,
  clientPolicy: (companyId: number) => ['client', 'company', companyId, 'policy'] as const,
  clientLocations: (companyId: number) => ['client', 'company', companyId, 'locations'] as const,
  clientDivisions: (companyId: number) => ['client', 'company', companyId, 'divisions'] as const,
  clientAccount: (id: number) => ['client', 'account', id] as const,
  clientAccounts: (search: string, page: number) => ['client', 'accounts', search, page] as const,
  clientAccountOptions: ['client', 'accounts', 'options'] as const,
};
