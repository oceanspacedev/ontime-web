import { useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';

import { toDateString } from '@/lib/format';
import { msUntilNextDay } from '@/lib/today';

/**
 * Today's date (YYYY-MM-DD) that follows the clock: it turns over at midnight while the app is open and
 * is checked again whenever the app comes back to the foreground. `onChange` runs when the date moves on,
 * for refetching what belongs to the day.
 */
export function useCurrentDate(onChange?: (date: string) => void): string {
  const [date, setDate] = useState(() => toDateString(new Date()));
  const onChangeRef = useRef(onChange);

  useEffect(() => {
    onChangeRef.current = onChange;
  });

  useEffect(() => {
    const sync = () => setDate((current) => {
      const now = toDateString(new Date());
      return now === current ? current : now;
    });
    let timer: ReturnType<typeof setTimeout>;
    const schedule = () => {
      timer = setTimeout(() => {
        sync();
        schedule();
      }, msUntilNextDay(new Date()));
    };
    schedule();
    const subscription = AppState.addEventListener('change', (status) => {
      if (status === 'active') {
        sync();
        // Timers do not run reliably in the background; plan the next midnight from now.
        clearTimeout(timer);
        schedule();
      }
    });
    return () => {
      clearTimeout(timer);
      subscription.remove();
    };
  }, []);

  const first = useRef(true);
  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    onChangeRef.current?.(date);
  }, [date]);

  return date;
}
