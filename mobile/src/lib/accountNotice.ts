import { Alert, Linking } from 'react-native';

import type { NewAccount } from './api/types';

/**
 * Tell the coordinator a login was made. When WhatsApp did not deliver it, show the email and password
 * once, with a button that opens WhatsApp to pass them on.
 */
export function showNewAccount(
  name: string,
  account: NewAccount | null,
  message: string,
  onDone: () => void,
  next?: { text: string; onPress: () => void },
): void {
  const nextButton = next ? [{ text: next.text, onPress: next.onPress }] : [];

  if (!account || account.whatsapp_sent) {
    Alert.alert(name, message, [...nextButton, { text: 'Selesai', onPress: onDone }]);
    return;
  }

  const whatsappUrl = account.whatsapp_url;
  Alert.alert(name, `Kirim akun ini:\n\nEmail: ${account.email}\nPassword: ${account.password}`, [
    ...(whatsappUrl ? [{ text: 'Kirim lewat WhatsApp', onPress: () => void Linking.openURL(whatsappUrl).finally(next?.onPress ?? onDone) }] : []),
    ...nextButton,
    { text: 'Selesai', onPress: onDone },
  ]);
}
