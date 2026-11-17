/// <reference types="jest" />
import * as Network from 'expo-network';

import { listenForNetwork } from '../deviceNetwork';
import { confirmOnline, RECENT_OFFLINE_EVENT_MS, resetNetworkEvents, setNetworkReader } from '../networkState';

type Listener<T> = (value: T) => void;

const mockNetworkListeners: Listener<Network.NetworkState>[] = [];
const mockAppStateListeners: Listener<string>[] = [];
const mockRemoveNetwork = jest.fn();
const mockRemoveAppState = jest.fn();

jest.mock('expo-network', () => ({
  getNetworkStateAsync: jest.fn(),
  addNetworkStateListener: jest.fn((listener: Listener<Network.NetworkState>) => {
    mockNetworkListeners.push(listener);
    return { remove: mockRemoveNetwork };
  }),
}));

jest.mock('react-native', () => ({
  AppState: {
    addEventListener: jest.fn((_event: string, listener: Listener<string>) => {
      mockAppStateListeners.push(listener);
      return { remove: mockRemoveAppState };
    }),
  },
}));

const getState = jest.mocked(Network.getNetworkStateAsync);

function deviceReports(state: Partial<Network.NetworkState>): void {
  getState.mockResolvedValue(state as Network.NetworkState);
}

async function settle(): Promise<void> {
  await new Promise((resolve) => setImmediate(resolve));
}

beforeEach(() => {
  mockNetworkListeners.length = 0;
  mockAppStateListeners.length = 0;
  jest.clearAllMocks();
  resetNetworkEvents();
});

afterEach(() => {
  jest.restoreAllMocks();
});

describe('listenForNetwork', () => {
  it('reads the device once at start', async () => {
    deviceReports({ isConnected: true, isInternetReachable: false });
    const setOnline = jest.fn();

    listenForNetwork(setOnline);
    await settle();

    expect(setOnline).toHaveBeenLastCalledWith(false);
  });

  it('follows network events', () => {
    deviceReports({ isConnected: true, isInternetReachable: true });
    const setOnline = jest.fn();
    listenForNetwork(setOnline);

    mockNetworkListeners[0]({ isConnected: false, isInternetReachable: false } as Network.NetworkState);

    expect(setOnline).toHaveBeenLastCalledWith(false);
  });

  it('reads the device again when the app comes back to the foreground, so a missed event cannot keep it offline', async () => {
    deviceReports({ isConnected: true, isInternetReachable: false });
    const setOnline = jest.fn();
    listenForNetwork(setOnline);
    await settle();
    expect(setOnline).toHaveBeenLastCalledWith(false);

    // The connection came back while the screen was off and no event reached the app.
    deviceReports({ isConnected: true, isInternetReachable: true });
    mockAppStateListeners[0]('background');
    await settle();
    expect(setOnline).toHaveBeenLastCalledWith(false);

    mockAppStateListeners[0]('active');
    await settle();
    expect(setOnline).toHaveBeenLastCalledWith(true);
  });

  it('does not let a foreground read undo an offline event that just arrived', async () => {
    const now = jest.spyOn(Date, 'now').mockReturnValue(50_000);
    deviceReports({ isConnected: true, isInternetReachable: true });
    const setOnline = jest.fn();
    listenForNetwork(setOnline);
    await settle();

    // onLost: the device says offline, but a read right after still sees the lost network as connected.
    mockNetworkListeners[0]({ isConnected: false, isInternetReachable: false } as Network.NetworkState);
    mockAppStateListeners[0]('active');
    await settle();
    expect(setOnline).toHaveBeenLastCalledWith(false);

    // Later, a read that says online is believed again.
    now.mockReturnValue(50_000 + RECENT_OFFLINE_EVENT_MS);
    mockAppStateListeners[0]('active');
    await settle();
    expect(setOnline).toHaveBeenLastCalledWith(true);
  });

  it('keeps the last value when the device cannot be read', async () => {
    getState.mockRejectedValue(new Error('native gone'));
    const setOnline = jest.fn();

    listenForNetwork(setOnline);
    mockAppStateListeners[0]('active');
    await settle();

    expect(setOnline).not.toHaveBeenCalled();
  });

  it('stops both listeners on cleanup', () => {
    deviceReports({ isConnected: true, isInternetReachable: true });
    const stop = listenForNetwork(jest.fn());

    stop();

    expect(mockRemoveNetwork).toHaveBeenCalledTimes(1);
    expect(mockRemoveAppState).toHaveBeenCalledTimes(1);
  });
});

describe('loading deviceNetwork', () => {
  it('lets the submit guards ask expo-network', async () => {
    const { onlineManager } = jest.requireActual<typeof import('@tanstack/react-query')>('@tanstack/react-query');
    deviceReports({ isConnected: true, isInternetReachable: true });
    onlineManager.setOnline(false);
    try {
      await expect(confirmOnline()).resolves.toBe(true);
      expect(getState).toHaveBeenCalled();
    } finally {
      onlineManager.setOnline(true);
      setNetworkReader(null);
    }
  });
});
