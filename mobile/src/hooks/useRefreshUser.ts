import { useMutation } from '@tanstack/react-query';

import { authApi } from '@/lib/api';
import { useSession } from '@/lib/session';

/** Re-reads /auth/me so role, policy and master-face changes made in the panel show up. */
export function useRefreshUser() {
  const setUser = useSession((state) => state.setUser);
  return useMutation({
    mutationFn: authApi.me,
    onSuccess: (user) => {
      void setUser(user);
    },
  });
}
