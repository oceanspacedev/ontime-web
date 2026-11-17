/// <reference types="jest" />
import { convertFormDataAsync } from 'expo/build/winter/fetch/convertFormData';
import { File } from 'expo-file-system';

import { appendFile, filePart } from '../api/client';

jest.mock('expo-file-system', () => ({
  File: jest.fn().mockImplementation((uri: string) => ({
    bytes: jest.fn(async () => new TextEncoder().encode(`bytes of ${uri}`)),
  })),
}));

/** A FormData stand-in that keeps the parts the way Expo's patched FormData does. */
function collectingForm(): { form: FormData; entries: () => [string, unknown][] } {
  const parts: [string, unknown][] = [];
  const form = { append: (name: string, value: unknown) => parts.push([name, value]) } as unknown as FormData;
  return { form, entries: () => parts };
}

describe('file parts for expo/fetch', () => {
  it('is accepted by the expo/fetch multipart encoder, which rejects the {uri} descriptor', async () => {
    const photo = { uri: 'file:///cache/check-in.jpg', name: 'check-in.jpg', type: 'image/jpeg' };

    // The old descriptor: expo/fetch throws before any request is sent (seen on a real Android phone).
    await expect(convertFormDataAsync({ entries: () => [['photo', photo]] } as unknown as FormData, 'b')).rejects.toThrow(
      'Unsupported FormDataPart implementation',
    );

    const { form, entries } = collectingForm();
    appendFile(form, 'photo', photo);
    form.append('latitude', '-6.2');
    const { body } = await convertFormDataAsync({ entries } as unknown as FormData, 'b');
    const text = new TextDecoder().decode(body);

    expect(text).toContain('content-disposition: form-data; name="photo"; filename="check-in.jpg"');
    expect(text).toContain('content-type: image/jpeg');
    expect(text).toContain('bytes of file:///cache/check-in.jpg');
    expect(text).toContain('form-data; name="latitude"');
  });

  it('reads the file only when the body is built', async () => {
    jest.mocked(File).mockClear();
    const part = filePart({ uri: 'file:///cache/a.jpg', name: 'a.jpg', type: 'image/jpeg' });

    expect(File).not.toHaveBeenCalled();
    await part.bytes();
    expect(File).toHaveBeenCalledWith('file:///cache/a.jpg');
  });
});
