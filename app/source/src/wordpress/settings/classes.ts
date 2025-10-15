import type { WooClassItem } from '@/wordpress/settings/settings.types.ts';

export const productClasses: WooClassItem[] = [
  {
    key: 'woo_class_product_container',
    className: 'info-pdct-ctnr-pf',
    description: 'Add this to the product container',
  },
  {
    key: 'woo_class_product_name',
    className: 'info-pdct-name-pf',
    description: 'Add this to the product name',
  },
  {
    key: 'woo_class_product_price',
    className: 'info-pdct-price-pf',
    description: 'Add this to the Item price:',
  },
  {
    key: 'woo_class_product_quantity',
    className: 'info-pdct-qnty-pf',
    description: 'Add this to the Item quantity:',
  },
  {
    key: 'woo_class_product_add_to_cart',
    className: 'action-btn-cart-005-pf',
    description: 'Add this to the add to cart button',
  },
];

export const cartClasses: WooClassItem[] = [
  {
    key: 'woo_class_cart_table',
    className: 'info-pdct-ctnr-pf',
    description: 'Add this to the overall main/parent container containing all the cart items',
  },
  {
    key: 'woo_class_cart_item',
    className: 'info-pdct-ctnr-pf',
    description: 'Add this the container of each individual item',
  },
  {
    key: 'woo_class_cart_price',
    className: 'info-pdct-price-pf',
    description: 'Add this to the Item price:',
  },
  {
    key: 'woo_class_cart_checkout_button',
    className: 'action-btn-buy-004-pf',
    description: 'Add this to the proceed to checkout button',
  },
  {
    key: 'woo_class_cart_product_name',
    className: 'info-pdct-name-pf',
    description: 'Add this to the cart product name',
  },
  {
    key: 'woo_class_cart_products_container',
    className: 'info-pdct-ctnr-list-pf',
    description: 'Add this to the element which wraps all the products in the cart',
  },
];

export const checkoutClasses: WooClassItem[] = [
  {
    key: 'woo_class_checkout_form',
    className: 'info-chk-itm-ctnr-pf',
    description:
      'Add this to the overall main/parent container containing all the checkout items (mandatory)',
  },
  {
    key: 'woo_class_checkout_item',
    className: 'info-chk-itm-pf',
    description: 'Add this the container of each individual item',
  },
  {
    key: 'woo_class_checkout_item_name',
    className: 'info-itm-name-pf',
    description: 'Add this to the Item name:',
  },
  {
    key: 'woo_class_checkout_item_price',
    className: 'info-itm-prc-pf',
    description: 'Add this to the Item price:',
  },
  {
    key: 'woo_class_checkout_item_quantity',
    className: 'info-itm-qnty-pf',
    description: 'Add this to the Item quantity:',
  },
  {
    key: 'woo_class_checkout_total',
    className: 'info-totl-amt-pf',
    description: 'Add this to total amount:',
  },
  {
    key: 'woo_class_checkout_place_order',
    className: 'action-btn-plc-ord-018-pf',
    description: 'Add this to the place order button',
  },
];
