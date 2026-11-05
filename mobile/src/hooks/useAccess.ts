import { useMemo } from 'react';

import { deriveAccess } from '@/lib/access';
import { useSession } from '@/lib/session';

export function useAccess() {
  const user = useSession((state) => state.user);
  return useMemo(() => deriveAccess(user), [user]);
}
