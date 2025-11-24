/**
 * @fileoverview WordPress Platform Adapter
 * @description Platform-specific implementation for WordPress integration
 */

/** External libraries */
import { toast } from 'react-toastify';

/** Types */
import {
  PlatformAdapter,
  PlatformConfig,
  BlockingRule,
  TrackingUrlScriptData,
} from '@pixelflow-org/plugin-core';

/**
 * WordPress Platform Adapter
 * @description Implements platform-specific functionality for WordPress,
 * including script injection, theme management, and notifications
 */
// @ts-expect-error TS2420
export class WordpressAdapter implements PlatformAdapter {
  constructor(private config: PlatformConfig) {}

  /**
   * Inject script into WordPress
   *
   * Note: Script is generated from saved params on the PHP side
   */
  // @ts-expect-error TS6133
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  async injectScript(script: string): Promise<void> {
    // Script is not saved - only params are saved via saveParams
    // The PHP side generates the script from params when needed
    console.log('[WordPress] Script generation handled by PHP from saved params');
  }

  /**
   * Remove script from WordPress
   *
   * Clears the tracking script parameters from the database via AJAX
   */
  async removeScript(): Promise<void> {
    // eslint-disable-next-line
    const settings = (window as any).pixelflowSettings;
    if (!settings) {
      throw new Error('WordPress settings not available');
    }

    const formData = new FormData();
    formData.append('action', 'pixelflow_remove_script_params');
    formData.append('nonce', settings.nonce);

    try {
      const response = await fetch(settings.ajax_url, {
        method: 'POST',
        body: formData,
      });

      const data = await response.json();

      if (!data.success) {
        throw new Error(data.data?.message || 'Failed to remove script parameters');
      }

      console.log('[WordPress] Script parameters removed successfully');
    } catch (error) {
      console.error('[WordPress] Failed to remove script parameters:', error);
      throw error;
    }
  }

  /**
   * Save tracking script parameters to WordPress
   *
   * Saves the tracking script parameters to the database via AJAX
   * These parameters are the same as those passed to generateTrackingScript
   */
  async saveParams(
    pixelIds: string[],
    siteExternalId: string,
    apiKey: string,
    currency: string,
    trackingUrls: TrackingUrlScriptData[],
    apiEndpoint: string,
    cdnUrl: string,
    enableMetaPixel: boolean,
    blockingRules: BlockingRule[]
  ): Promise<void> {
    // eslint-disable-next-line
    const settings = (window as any).pixelflowSettings;
    if (!settings) {
      throw new Error('WordPress settings not available');
    }

    const formData = new FormData();
    formData.append('action', 'pixelflow_save_script_params');
    formData.append('nonce', settings.nonce);

    // Encode parameters as JSON and base64 encode
    const params = {
      pixelIds,
      siteExternalId,
      apiKey,
      currency,
      trackingUrls,
      apiEndpoint,
      cdnUrl,
      enableMetaPixel,
      blockingRules,
    };

    const jsonParams = JSON.stringify(params);
    const encoded = btoa(unescape(encodeURIComponent(jsonParams))); // base64 UTF-8 safe
    formData.append('params', encoded);

    try {
      const response = await fetch(settings.ajax_url, {
        method: 'POST',
        body: formData,
      });

      const data = await response.json();

      if (!data.success) {
        throw new Error(data.data?.message || 'Failed to save script parameters');
      }

      console.log('[WordPress] Script parameters saved successfully');
    } catch (error) {
      console.error('[WordPress] Failed to save script parameters:', error);
      throw error;
    }
  }

  /**
   *
   * This should return a unique identifier for the current site.
   */
  async getSiteId(): Promise<string> {
    const siteId = document.getElementById('pixelflow-site-id')?.getAttribute('value');
    if (siteId) return siteId;
    throw new Error('Site id not found');
  }

  /**
   * Get platform name
   *
   * Returns the platform name from config.
   */
  getPlatformName(): string {
    return this.config.platformName;
  }

  /**
   *
   * Show user-facing notifications/toasts.
   */
  showNotification(message: string, type: 'success' | 'error' | 'info' | 'warning'): void {
    toast(message, { type });
    if (type === 'error') {
      console.error(`[PixelFlow][${this.getPlatformName()}] ${message}`);
    }
  }

  /**
   *
   * Return the current theme (light or dark).
   */
  getTheme(): 'light' | 'dark' {
    if (typeof document === 'undefined') return 'light';

    const storedTheme = localStorage.getItem('pixelflow_theme');
    let theme: 'light' | 'dark' = 'light';

    if (storedTheme === 'dark' || storedTheme === 'light') {
      theme = storedTheme;
    } else {
      const bodyTheme = document.body.getAttribute('data-theme');
      theme = bodyTheme === 'dark' ? 'dark' : 'light';
    }

    document.body.setAttribute('data-theme', theme);
    localStorage.setItem('pixelflow_theme', theme);

    return theme;
  }

  /**
   *
   * Call the callback when the theme changes.
   * Return a cleanup function to remove the listener.
   */
  onThemeChange(callback: (theme: 'light' | 'dark') => void): () => void {
    // Set initial theme
    this.getTheme();

    callback(this.getTheme());

    // Return cleanup function
    return () => {};
  }
}
