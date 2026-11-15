import { useQueryClient } from '@tanstack/react-query';
import { useCallback } from 'react';

import { useRefreshUser } from '@/hooks/useRefreshUser';
import { queryKeys } from '@/lib/queryClient';

/**
 * Call after any successful Kelola Klien change (policy, location, division, company, account).
 * Reloads the client screens, today's attendance and team data, and the signed-in user, so the
 * admin's own company policy and locations follow the change right away.
 */
export function useClientAdminSaved(): () => Promise<void> {
  const queryClient = useQueryClient();
  const { mutate: refreshUser } = useRefreshUser();

  return useCallback(async () => {
    refreshUser();
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: queryKeys.client }),
      queryClient.invalidateQueries({ queryKey: queryKeys.today }),
      queryClient.invalidateQueries({ queryKey: queryKeys.team }),
    ]);
  }, [queryClient, refreshUser]);
}
