/**
 * @fileoverview Platform configuration for PixelFlow WordPress plugin
 * @description Configuration specific to WordPress platform
 */

import { PlatformConfig } from '@pixelflow-org/plugin-core';

export const platformConfig: PlatformConfig = {
  apiBaseUrl: import.meta.env.VITE_API_BASE_URL || '',
  uiBaseUrl: import.meta.env.VITE_UI_BASE_URL || '',
  cdnUrl: import.meta.env.VITE_CDN_URL || '',
  platformName: 'wordpress',
};
