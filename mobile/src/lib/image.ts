import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';

import type { UploadFile } from './api/types';

const MAX_SIDE = 1280;
const JPEG_QUALITY = 0.75;

/**
 * Shrinks a photo so it stays well under the API's 5 MB limit and uploads fast on mobile data.
 * The output is always JPEG, which every backend validation rule accepts.
 */
export async function prepareUpload(uri: string, name: string, width?: number, height?: number): Promise<UploadFile> {
  const context = ImageManipulator.manipulate(uri);
  const longest = Math.max(width ?? 0, height ?? 0);
  if (longest === 0 || longest > MAX_SIDE) {
    if (width && height && width >= height) {
      context.resize({ width: MAX_SIDE, height: null });
    } else {
      context.resize({ width: null, height: MAX_SIDE });
    }
  }
  const rendered = await context.renderAsync();
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
