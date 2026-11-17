import { focusManager, QueryClient } from '@tanstack/react-query';
import { AppState, type AppStateStatus, Platform } from 'react-native';

import { ApiError, NetworkError } from './api/client';

/**
 * Whether a change of the app state means the app is in front of the user again. React Native has no
 * window focus event, so without this an app brought back from the background keeps showing yesterday's
 * card until pulled to refresh.
 */
export function isAppFocused(status: AppStateStatus): boolean {
  return status === 'active';
}

/**
 * Tells React Query when the app comes to the foreground, so stale queries (today's card, the team's day)
 * refetch on their own. The web build keeps React Query's own window focus handling.
 */
export function listenForAppFocus(setFocused: (focused?: boolean) => void): () => void {
  const subscription = AppState.addEventListener('change', (status) => setFocused(isAppFocused(status)));
  return () => subscription.remove();
}

if (Platform.OS !== 'web') {
  focusManager.setEventListener(listenForAppFocus);
}

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30 * 1000,
      retry: (failureCount, error) => {
        if (error instanceof ApiError && error.status < 500) {
          return false;
        }
        // A timeout already kept the user waiting; show the message and its retry right away.
        if (error instanceof NetworkError && error.kind === 'timeout') {
          return false;
        }
        return failureCount < 2;
      },
    },
    // A form sent while offline fails at once with the offline message (request() turns the failed fetch
    // into a NetworkError) and is never paused and replayed later, after the user left the screen.
    mutations: {
      networkMode: 'always',
      retry: false,
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
