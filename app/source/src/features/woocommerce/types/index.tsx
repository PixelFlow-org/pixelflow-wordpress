/**
 * @fileoverview WooCommerce types
 * @description Type definitions for WooCommerce Redux state
 */

/**
 * WooCommerce Redux state type
 * @description State shape for WooCommerce feature slice
 */
export type WooCommerceState = {
  /** Whether WooCommerce plugin is active in WordPress */
  isWooCommerceActive: boolean;
};
