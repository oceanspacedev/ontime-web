import { useMutation } from '@tanstack/react-query';

import { authApi } from '@/lib/api';
import type { User } from '@/lib/api/types';
import { useSession } from '@/lib/session';

/**
 * Asks /auth/me for the signed-in user, remembering which session asked. Kept outside the hook so
 * the race it guards against (signing out and in again while the request is out) can be tested.
 */
export async function fetchCurrentUser(): Promise<{ user: User; epoch: number }> {
  const epoch = useSession.getState().epoch;
  const user = await authApi.me();
  return { user, epoch };
}

/** Stores the answer only if the session that asked is still the current one. */
export function applyCurrentUser({ user, epoch }: { user: User; epoch: number }): Promise<void> {
  return useSession.getState().setUser(user, epoch);
}

/** Re-reads /auth/me so role, policy and profile photo changes made in the panel show up. */
export function useRefreshUser() {
  return useMutation({
    mutationFn: fetchCurrentUser,
    onSuccess: (result) => {
      void applyCurrentUser(result);
    },
  });
}
