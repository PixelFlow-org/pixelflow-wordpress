/** Server-side cart reset, so a scenario never inherits the previous one's cart. */
import { wpEval } from './ssh';
import { CUSTOMER } from '../site';

export function resetCarts(): void {
  wpEval(`
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_sessions");
    $u = get_user_by("login", "${CUSTOMER.username}");
    if ($u) {
      delete_user_meta($u->ID, "_woocommerce_persistent_cart_" . get_current_blog_id());
    }
  `);
}
