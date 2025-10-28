/**
 * @fileoverview WooCommerce class definitions
 * @description Defines WooCommerce CSS class configurations for tracking
 */

/** Types */
import type { WooClassItem } from '@/features/settings/types/settings.types.ts';

/**
 * Product page class configurations
 * @description CSS classes to track Add to Cart events on product and shop pages
 */
export const productClasses: WooClassItem[] = [
  {
    key: 'woo_class_product_container',
    className: 'info-chk-itm-pf',
    description: 'Product container',
  },
  {
    key: 'woo_class_product_name',
    className: 'info-itm-name-pf',
    description: 'Product name',
  },
  {
    key: 'woo_class_product_price',
    className: 'info-itm-prc-pf',
    description: 'Item price',
  },
  {
    key: 'woo_class_product_quantity',
    className: 'info-itm-qnty-pf',
    description: 'Item quantity',
  },
  {
    key: 'woo_class_product_add_to_cart',
    className: 'action-btn-cart-005-pf',
    description: 'Cart button',
  },
];

/**
 * Cart page class configurations
 * @description CSS classes to track Initiate Checkout events on the cart page
 */
export const cartClasses: WooClassItem[] = [
  {
    key: 'woo_class_cart_item',
    className: 'info-chk-itm-pf',
    description: 'Container of each individual item',
  },
  {
    key: 'woo_class_cart_price',
    className: 'info-itm-prc-pf',
    description: 'Item price',
  },
  {
    key: 'woo_class_cart_quantity',
    className: 'info-itm-qnty-pf',
    description: 'Item quantity',
  },
  {
    key: 'woo_class_cart_checkout_button',
    className: 'action-btn-buy-004-pf',
    description: 'Proceed to checkout button',
  },
  {
    key: 'woo_class_cart_product_name',
    className: 'info-itm-name-pf',
    description: 'Cart product name',
  },
  {
    key: 'woo_class_cart_products_container',
    className: 'info-chk-itm-ctnr-pf',
    description: 'Element which wraps all the products in the cart',
  },
];

/**
 * Debug color mapping
 * @description Maps WooCommerce class names to debug border colors for visual identification
 */
export const debugColors: Record<string, string> = {
  'info-chk-itm-pf': 'green',
  'info-itm-name-pf': 'red',
  'info-itm-prc-pf': 'blue',
  'info-itm-qnty-pf': 'orange',
  'action-btn-cart-005-pf': '#fc0390',
  'action-btn-buy-004-pf': '#67a174',
  'info-chk-itm-ctnr-pf': 'green',
  'info-totl-amt-pf': '#b103fc',
  'action-btn-plc-ord-018-pf': '#b01a81',
};
