/**
 * @fileoverview Platform configuration for PixelFlow WordPress plugin
 * @description Configuration specific to WordPress platform
 */

import { PlatformConfig, getUiBaseUrl } from '@pixelflow-org/plugin-core';
import { getServiceConfig } from '@/features/settings/utils/wordpress-config';

export const platformConfig: PlatformConfig = {
  ...getServiceConfig(),
  platformName: 'wordpress',
};

/**
 * The dashboard's overview page on the configured dashboard origin.
 * Read at call time: the origin is set in main.tsx after imported modules have loaded.
 */
export const getDashboardUrl = (): string => `${getUiBaseUrl()}/dashboard/overview`;
