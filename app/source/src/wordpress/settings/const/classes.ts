import type { WooClassItem } from '../types/settings.types.ts';

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
