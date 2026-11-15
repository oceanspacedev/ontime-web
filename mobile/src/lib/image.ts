import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';

import type { UploadFile } from './api/types';

const MAX_SIDE = 1280;
const JPEG_QUALITY = 0.75;

/**
 * Shrinks a photo so it stays well under the API's 5 MB limit and uploads fast on mobile data.
 * The output is always JPEG, which every backend validation rule accepts.
 *
 * The photo is downsampled while it is decoded, so a full-resolution camera picture is never held
 * in memory: decoding it whole could run low-end Android phones out of memory. This also works for
 * picked files whose dimensions are unknown, and never enlarges a small image.
 */
export async function prepareUpload(uri: string, name: string): Promise<UploadFile> {
  const rendered = await ImageManipulator.manipulate(uri, { maxWidth: MAX_SIDE, maxHeight: MAX_SIDE }).renderAsync();
  const saved = await rendered.saveAsync({ format: SaveFormat.JPEG, compress: JPEG_QUALITY });
  return { uri: saved.uri, name: `${name}.jpg`, type: 'image/jpeg' };
}

export function guessMimeType(name: string, fallback = 'application/octet-stream'): string {
  const ext = name.split('.').pop()?.toLowerCase();
  switch (ext) {
    case 'jpg':
    case 'jpeg':
      return 'image/jpeg';
    case 'png':
      return 'image/png';
    case 'webp':
      return 'image/webp';
    case 'pdf':
      return 'application/pdf';
    case 'doc':
      return 'application/msword';
    case 'docx':
      return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    default:
      return fallback;
  }
}
