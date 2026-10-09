/**
 * Puts the site's plugins back after a scenario that switches WooCommerce off.
 *
 * Some WooCommerce add-ons deactivate themselves on the first admin request while WooCommerce
 * is missing (e.g. Subscriptions for WooCommerce), so turning WooCommerce alone back on leaves
 * them off for every spec that runs later.
 */
import { wp } from './ssh';

/** Names of the plugins active right now. */
export function activePlugins(): string[] {
  return wp('plugin list --status=active --field=name')
    .split('\n')
    .map((name) => name.trim())
    .filter(Boolean);
}

/** Reactivates every plugin in `names`, WooCommerce first, and fails if one stays off. */
export function restorePlugins(names: string[]): void {
  wp('plugin activate woocommerce', { check: false });
  if (names.length > 0) {
    wp(`plugin activate ${names.join(' ')}`, { check: false });
  }

  const active = new Set(activePlugins());
  const missing = names.filter((name) => !active.has(name));
  if (missing.length > 0) {
    throw new Error(`Plugins did not come back on: ${missing.join(', ')}`);
  }
}
