/**
 * @fileoverview WordPress configuration utility
 * @description Single source of truth for WordPress settings from window object
 */

/**
 * Get WordPress configuration from window object
 * @description Extracts WordPress settings once during module initialization.
 * This is the ONLY place where window.pixelflowSettings should be accessed.
 * @returns WordPress settings object
 */
const getWordPressSettings = () => {
  return window.pixelflowSettings || null;
};

// Extract settings once at module load
export const wordpressSettings = getWordPressSettings();

/**
 * Get WordPress AJAX configuration
 * @description Provides nonce and ajax_url for API calls
 * @returns Object with nonce and ajaxUrl
 */
export const getWordPressAjaxConfig = () => ({
  nonce: wordpressSettings?.nonce || '',
  ajaxUrl: wordpressSettings?.ajax_url || '',
});

