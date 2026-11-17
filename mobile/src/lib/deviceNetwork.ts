import * as Network from 'expo-network';
import { AppState, type AppStateStatus } from 'react-native';

import { type ConnectionState, isOfflineState, isOnlineRead, recordNetworkEvent, setNetworkReader } from './networkState';

setNetworkReader(Network.getNetworkStateAsync);

/**
 * Feeds React Query's onlineManager from the device: once at start, on every network event, and each
 * time the app comes back to the foreground, since a change that happens while the app is in the
 * background can be missed and nothing else would correct the stored value.
 */
export function listenForNetwork(setOnline: (online: boolean) => void): () => void {
  // An event is the device telling us about a change, so it is taken as is.
  const onEvent = (state: ConnectionState) => {
    recordNetworkEvent(state);
    setOnline(!isOfflineState(state));
  };
  // A read can report a just-lost network as still connected, so it cannot undo a fresh offline event.
  const readNow = () => {
    void Network.getNetworkStateAsync()
      .then((state) => setOnline(isOnlineRead(state)))
      .catch(() => {});
  };

  readNow();
  const network = Network.addNetworkStateListener(onEvent);
  const appState = AppState.addEventListener('change', (status: AppStateStatus) => {
    if (status === 'active') {
      readNow();
    }
  });

  return () => {
    network.remove();
    appState.remove();
  };
}
