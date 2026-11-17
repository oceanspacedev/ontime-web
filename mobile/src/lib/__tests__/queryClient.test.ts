import { AppState } from 'react-native';

import { isAppFocused, listenForAppFocus } from '../queryClient';

describe('app focus for React Query', () => {
  it('counts only the active state as focused', () => {
    expect(isAppFocused('active')).toBe(true);
    expect(isAppFocused('background')).toBe(false);
    expect(isAppFocused('inactive')).toBe(false);
  });

  it('reports foreground and background changes and stops listening on cleanup', () => {
    const remove = jest.fn();
    let listener: ((status: string) => void) | undefined;
    const spy = jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, handler) => {
      listener = handler as (status: string) => void;
      return { remove } as unknown as ReturnType<typeof AppState.addEventListener>;
    });
    const setFocused = jest.fn();

    const unsubscribe = listenForAppFocus(setFocused);
    listener?.('background');
    listener?.('active');
    unsubscribe();

    expect(setFocused.mock.calls).toEqual([[false], [true]]);
    expect(remove).toHaveBeenCalledTimes(1);
    spy.mockRestore();
  });
});
