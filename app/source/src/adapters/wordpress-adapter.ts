/**
 * @fileoverview WordPress Platform Adapter
 * @description Platform-specific implementation for WordPress integration
 */

/** External libraries */
import { toast } from 'react-toastify';

/** Types */
import { PlatformAdapter, PlatformConfig } from '@pixelflow-org/plugin-core';

/**
 * WordPress Platform Adapter
 * @description Implements platform-specific functionality for WordPress,
 * including script injection, theme management, and notifications
 */
export class WordpressAdapter implements PlatformAdapter {
  constructor(private config: PlatformConfig) {}

  /**
   * Inject script into WordPress
   *
   * Saves the tracking script to the database via AJAX
   */
  async injectScript(script: string): Promise<void> {
    const settings = (window as any).pixelflowSettings;
    if (!settings) {
      throw new Error('WordPress settings not available');
    }

    const formData = new FormData();
    formData.append('action', 'pixelflow_save_script_code');
    formData.append('nonce', settings.nonce);
    formData.append('script_code', script);

    try {
      const response = await fetch(settings.ajax_url, {
        method: 'POST',
        body: formData,
      });

      const data = await response.json();

      if (!data.success) {
        throw new Error(data.data?.message || 'Failed to save script code');
      }

      console.log('[WordPress] Script injected successfully');
    } catch (error) {
      console.error('[WordPress] Failed to inject script:', error);
      throw error;
    }
  }

  /**
   * Remove script from WordPress
   *
   * Clears the tracking script from the database via AJAX
   */
  async removeScript(): Promise<void> {
    const settings = (window as any).pixelflowSettings;
    if (!settings) {
      throw new Error('WordPress settings not available');
    }

    const formData = new FormData();
    formData.append('action', 'pixelflow_remove_script_code');
    formData.append('nonce', settings.nonce);

    try {
      const response = await fetch(settings.ajax_url, {
        method: 'POST',
        body: formData,
      });

      const data = await response.json();

      if (!data.success) {
        throw new Error(data.data?.message || 'Failed to remove script code');
      }

      console.log('[WordPress] Script removed successfully');
    } catch (error) {
      console.error('[WordPress] Failed to remove script:', error);
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
