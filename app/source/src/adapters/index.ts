/**
 * @fileoverview Adapter singleton exports
 * @description Provides singleton instance of WordPress adapter for use throughout the application
 */

import { WordpressAdapter } from './wordpress-adapter';
import { platformConfig } from '@/config/platform.config';

/**
 * Singleton instance of WordPress adapter
 * @description Use this instance for all platform-specific operations
 * Can be used in both React components (via context) and non-React code (direct import)
 */
export const wordpressAdapter = new WordpressAdapter(platformConfig);


