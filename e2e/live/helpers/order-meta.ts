/**
 * Reading and rewriting one order's PixelFlow meta over wp-cli.
 *
 * The blocked-purchase report is deferred by a hard-coded 1800 seconds and a
 * live scenario cannot wait that out. Rewriting the marker's `due` timestamp to
 * the past compresses the window without touching plugin code, and it proves
 * more than a filter on the constant would: the overdue path the design added
 * for sites with no working cron is exercised by the same rewrite.
 *
 * The rewrite touches only the `due` field of a marker the plugin itself wrote,
 * on an order the test created. What is asserted afterwards is what the plugin
 * then does with it.
 */
import { ssh, wp, wpEval } from './ssh';
import { SITE } from '../site';

/** The deferred-report marker, as the plugin stores it in `_pf_purchase_blocked`. */
export interface BlockedMarker {
  due?: number;
  reason?: string;
  [key: string]: unknown;
}

export interface OrderConsentMeta {
  /** Present while a blocked purchase is waiting for its reporting window to close. */
  blocked: BlockedMarker | null;
  /** `1` once the purchase has actually been delivered. */
  sent: boolean;
  /** `1` once the blocked row has been reported, which closes the order to further traffic. */
  blockedReported: boolean;
}

export function readOrderMeta(orderId: number): OrderConsentMeta {
  const raw = wpEval(`
    $order = wc_get_order(${orderId});
    if (!$order) { echo "{}"; return; }
    echo wp_json_encode([
      "blocked" => (string) $order->get_meta("_pf_purchase_blocked", true),
      "sent" => (string) $order->get_meta("_pf_purchase_sent", true),
      "reported" => (string) $order->get_meta("_pf_purchase_blocked_reported", true),
    ]);
  `);

  const parsed = JSON.parse(raw || '{}') as { blocked?: string; sent?: string; reported?: string };
  let blocked: BlockedMarker | null = null;
  if (parsed.blocked) {
    try {
      blocked = JSON.parse(parsed.blocked) as BlockedMarker;
    } catch {
      blocked = null;
    }
  }

  return {
    blocked,
    sent: Boolean(parsed.sent),
    blockedReported: Boolean(parsed.reported),
  };
}

/**
 * Moves the pending report's due time into the past, so the next purchase hook
 * or the scheduled event treats it as overdue.
 */
export function expireBlockedReport(orderId: number, secondsAgo = 60): void {
  const result = wpEval(`
    $order = wc_get_order(${orderId});
    if (!$order) { echo "no order"; return; }
    $stored = (string) $order->get_meta("_pf_purchase_blocked", true);
    $marker = $stored === "" ? null : json_decode($stored, true);
    if (!is_array($marker)) { echo "no marker"; return; }
    $marker["due"] = time() - ${secondsAgo};
    $order->update_meta_data("_pf_purchase_blocked", (string) wp_json_encode($marker));
    $order->save();
    echo "ok";
  `);

  if (result.trim() !== 'ok') {
    throw new Error(
      `Could not expire the blocked-report marker on order ${orderId}: ${result.trim() || 'empty response'}.`
    );
  }
}

/**
 * Removes every pending blocked-report except this order's.
 *
 * wp-cli's cron runner fires every event registered under a hook, not only the ones that are
 * due, so a report still pending from an earlier scenario would be reported in the same run
 * and counted against this order. This order's own event is left exactly as the plugin
 * scheduled it — the scenario is about the scheduler firing it, not about rewriting it.
 */
export function isolateScheduledReport(orderId: number): void {
  const remaining = wpEval(`
    $hook = "pixelflow_report_blocked_purchase";
    foreach ((array) _get_cron_array() as $timestamp => $hooks) {
      if (!isset($hooks[$hook])) { continue; }
      foreach ($hooks[$hook] as $event) {
        $args = isset($event["args"]) ? (array) $event["args"] : [];
        if ((int) ($args[0] ?? 0) === ${orderId}) { continue; }
        wp_unschedule_event($timestamp, $hook, $args);
      }
    }
    echo (int) wp_next_scheduled($hook, [${orderId}]);
  `).trim();

  if (remaining === '' || remaining === '0') {
    throw new Error(`Order ${orderId} has no scheduled blocked report to run.`);
  }
}

/**
 * Moves an order to a new status without a browser.
 *
 * **Not usable for the purchase hooks.** In a WP-CLI request the plugin's
 * `pf_purchase_hook` is not attached to `woocommerce_order_status_processing` or
 * `woocommerce_thankyou` — verified on the site: the singleton exists and
 * `order_has_reported_lines()` is true, yet `has_action(..., [$instance, 'pf_purchase_hook'])`
 * is false. A status change made here therefore runs no PixelFlow logic at all and leaves an
 * empty log, which reads as a plugin defect and is not one. A scenario that needs the purchase
 * hooks must move the status through wp-admin, as the ownership scenarios do.
 *
 * Not `wp wc order update` either: WooCommerce's CLI has no `order` subcommand on this site,
 * so the status change goes through the order object itself.
 */
export function setOrderStatus(orderId: number, status: string): void {
  const result = wpEval(`
    $order = wc_get_order(${orderId});
    if (!$order) { echo "no order"; return; }
    $order->update_status("${status}");
    echo $order->get_status();
  `);

  if (result.trim() !== status) {
    throw new Error(
      `Order ${orderId} did not move to "${status}" (now: ${result.trim() || 'unknown'}).`
    );
  }
}

/**
 * Creates a pending order for the customer account, without a browser.
 *
 * The withdrawal scenario needs an order whose purchase has *not* been
 * delivered yet — once a checkout reaches the thank-you page under a granted
 * consent the purchase is already gone, and a withdrawal afterwards would prove
 * nothing. An order the buyer has yet to pay for is exactly the case the
 * order-pay page exists for.
 */
export function createPendingOrder(customerLogin: string, productId: number): number {
  const id = wpEval(`
    $user = get_user_by("login", "${customerLogin}");
    if (!$user) { echo "0"; return; }
    $order = wc_create_order(["customer_id" => $user->ID]);
    $order->add_product(wc_get_product(${productId}), 1);
    $order->calculate_totals();
    $order->set_status("pending");
    $order->save();
    echo $order->get_id();
  `).trim();

  const orderId = Number(id);
  if (!orderId) {
    throw new Error(`Could not create a pending order for "${customerLogin}".`);
  }
  return orderId;
}

/** The order-pay URL WooCommerce would send the buyer to, key included. */
export function orderPayUrl(orderId: number): string {
  const url = wpEval(`
    $order = wc_get_order(${orderId});
    echo $order ? $order->get_checkout_payment_url() : "";
  `).trim();

  if (!url) {
    throw new Error(`Order ${orderId} has no order-pay URL — is the order still payable?`);
  }
  return url;
}

/** Runs one scheduled event by hook name, proving the scheduler path rather than the overdue path. */
export function runCronEvent(hook: string): string {
  return wp(`cron event run ${hook}`, { check: false });
}

/**
 * Runs due cron over HTTP, the way WordPress itself does it.
 *
 * `wp cron event run` executes in a CLI process with no HTTP request behind it. The
 * loopback request to `wp-cron.php` is what a real site performs, so the hooks run in the
 * request context they were written for.
 */
export function runCronOverHttp(): string {
  return ssh(
    `curl -sk -o /dev/null -w '%{http_code}' '${SITE.baseURL}/wp-cron.php?doing_wp_cron=1'`,
    { check: false }
  ).trim();
}

/** True when the scheduler still holds a pending report for this order. */
export function hasScheduledReport(orderId: number): boolean {
  const next = wpEval(
    `echo (int) wp_next_scheduled("pixelflow_report_blocked_purchase", [${orderId}]);`
  ).trim();
  return next !== '0' && next !== '';
}
