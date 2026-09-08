/**
 * Everything that is specific to the rift test site.
 *
 * Credentials come from the environment (see .env.example); nothing secret is
 * committed. Paths, product fixtures and URLs are pinned deliberately — this
 * suite verifies one known site, not an arbitrary WordPress install.
 */
import { homedir } from 'node:os';

export const SITE = {
  baseURL: 'https://rift.kskonovalov.me',
  sshHost: process.env.PF_SSH_HOST ?? 'claude@rift.kskonovalov.me',
  sshKey: process.env.PF_SSH_KEY ?? `${homedir()}/.claude/keys/rift`,
  wpRoot: process.env.PF_WP_ROOT ?? '/var/www/rift.kskonovalov.me/www',
  wpCli: process.env.PF_WP_CLI ?? '~/bin/wp',
} as const;

export const ADMIN = {
  username: process.env.PF_ADMIN_USER ?? 'pfbot',
  password: process.env.PF_ADMIN_PASS ?? '',
} as const;

export const CUSTOMER = {
  username: process.env.PF_CUSTOMER_USER ?? 'pfcustomer',
  password: process.env.PF_CUSTOMER_PASS ?? '',
  /** Mirrors the billing address stored on the account — the source for the no-cookie location case. */
  billing: {
    city: 'Springfield',
    state: 'OR',
    postcode: '97477',
    country: 'US',
    address1: '742 Evergreen Terrace',
    email: 'pfcustomer@rift.kskonovalov.me',
    phone: '5415550123',
    firstName: 'Pixel',
    lastName: 'Flow',
  },
} as const;

export const URLS = {
  login: `${SITE.baseURL}/wp-login.php`,
  settings: `${SITE.baseURL}/wp-admin/options-general.php?page=pixelflow-settings`,
  plugins: `${SITE.baseURL}/wp-admin/plugins.php`,
  pluginUpload: `${SITE.baseURL}/wp-admin/plugin-install.php?tab=upload`,
  /** Deterministic storefront listing: every product the matrix touches, one page, no pagination. */
  fixtureListing: `${SITE.baseURL}/?product_cat=pf-fixtures`,
  cart: `${SITE.baseURL}/?page_id=10`,
  checkout: `${SITE.baseURL}/?page_id=11`,
  product: (slug: string) => `${SITE.baseURL}/?product=${slug}`,
  adminOrder: (orderId: number) =>
    `${SITE.baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`,
} as const;

export interface Fixture {
  id: number;
  sku: string;
  slug: string;
  name: string;
  /** Displayed price, as the plugin reports it in item_price. */
  price: number;
  type: 'simple' | 'variable' | 'grouped' | 'external';
  /** True when the storefront listing offers a direct add-to-cart button. */
  addableFromListing: boolean;
}

export const PRODUCTS = {
  free: {
    id: 61, sku: 'PF-FREE', slug: 'pf-free-product', name: 'PF Free Product',
    price: 0, type: 'simple', addableFromListing: true,
  },
  virtual: {
    id: 62, sku: 'PF-VIRTUAL', slug: 'pf-virtual-product', name: 'PF Virtual Product',
    price: 12, type: 'simple', addableFromListing: true,
  },
  sale: {
    id: 63, sku: 'PF-SALE', slug: 'pf-on-sale-product', name: 'PF On Sale Product',
    price: 60, type: 'simple', addableFromListing: true,
  },
  backorder: {
    id: 65, sku: 'PF-BACKORDER', slug: 'pf-backorder-product', name: 'PF Backorder Product',
    price: 30, type: 'simple', addableFromListing: true,
  },
  download: {
    id: 70, sku: 'PF-DOWNLOAD', slug: 'pf-downloadable-product', name: 'PF Downloadable Product',
    price: 8, type: 'simple', addableFromListing: true,
  },
  excluded: {
    id: 72, sku: 'PF-EXCLUDED', slug: 'pf-excluded-product', name: 'PF Excluded Product',
    price: 49, type: 'simple', addableFromListing: true,
  },
  variable: {
    id: 66, sku: 'PF-VARIABLE', slug: 'pf-variable-multi-price', name: 'PF Variable Multi Price',
    price: 20, type: 'variable', addableFromListing: false,
  },
  grouped: {
    id: 36, sku: 'logo-collection', slug: 'logo-collection', name: 'Logo Collection',
    price: 18, type: 'grouped', addableFromListing: false,
  },
  external: {
    id: 37, sku: 'wp-pennant', slug: 'wordpress-pennant', name: 'WordPress Pennant',
    price: 11.05, type: 'external', addableFromListing: false,
  },
  tshirt: {
    id: 17, sku: 'woo-tshirt', slug: 't-shirt', name: 'T-Shirt',
    price: 18, type: 'simple', addableFromListing: true,
  },
} satisfies Record<string, Fixture>;

/** Variations of PRODUCTS.variable. `Small` is free — it exercises the freebie flags at variation level. */
export const VARIATIONS = {
  small: { id: 67, sku: 'PF-VARIABLE-S', option: 'Small', price: 0 },
  medium: { id: 68, sku: 'PF-VARIABLE-M', option: 'Medium', price: 20 },
  large: { id: 69, sku: 'PF-VARIABLE-L', option: 'Large', price: 35 },
} as const;

/** Children the grouped product actually adds to the cart. */
export const GROUPED_CHILDREN = [PRODUCTS.tshirt.id, 18] as const;

export const COUPON = { code: 'discount', percent: 37 } as const;
