/**
 * "Only the customer's first purchase" against the site's real order queries.
 *
 * The PHP tests stub wc_get_orders() and cannot check the SQL, which differs between order
 * storages (meta queries in legacy posts storage, a total column in HPOS). Each case creates
 * its orders over WP-CLI with an email of its own, moves the order under test to processing in
 * the same request, so the plugin's own status hook takes the decision, and reads the decision
 * back from `_pf_purchase_first_only`. Run once per storage; the report names the storage.
 *
 * A filter case hooks `pixelflow_order_amount_paid` inside that same request, so nothing is
 * deployed to the site for it.
 */
import { test, expect, withAdminPage } from '../fixtures';
import { PRODUCTS } from '../site';
import { applySettings, FIRST_PURCHASE_ONLY, TRACK_EVERYTHING } from '../presets';
import { wpEval } from '../helpers/ssh';

interface CaseResult {
  storage: 'hpos' | 'legacy';
  decision: string;
  previous: number;
}

/**
 * Creates the previous order(s) described by `setupPhp`, then the order under test, and
 * returns its recorded decision. `setupPhp` sees `$email` and `$mk` and must set `$previous`.
 */
function runCase(setupPhp: string, filterPhp = ''): CaseResult {
  const email = `q-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;
  const raw = wpEval(`
    $email = "${email}";
    $mk = function ($billing, $total, $status, $meta = [], $withItem = false) {
      $o = wc_create_order();
      $o->set_billing_email($billing);
      if ($withItem) { $o->add_product(wc_get_product(${PRODUCTS.virtual.id}), 1); $o->calculate_totals(); }
      $o->set_total($total);
      foreach ($meta as $k => $v) { $o->update_meta_data($k, $v); }
      $o->save();
      $o->set_status($status);
      $o->save();
      return $o;
    };
    ${setupPhp}
    ${filterPhp}
    $current = $mk($email, 12, "pending", [], true);
    $current->set_status("processing");
    $current->save();
    $current = wc_get_order($current->get_id());
    echo wp_json_encode([
      "storage" => \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? "hpos" : "legacy",
      "decision" => (string) $current->get_meta("_pf_purchase_first_only", true),
      "previous" => isset($previous) ? $previous->get_id() : 0,
    ]);
  `);

  const json = raw.slice(raw.indexOf('{'));
  return JSON.parse(json) as CaseResult;
}

test.describe('First purchase only — real order queries', () => {
  test.beforeAll(async ({ browser }) => {
    await withAdminPage(browser, (page) => applySettings(page, FIRST_PURCHASE_ONLY));
  });

  test.afterAll(async ({ browser }) => {
    await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
  });

  test('a previous order with total 0 and _real_total 29 counts', () => {
    const r = runCase(`$previous = $mk($email, 0, "completed", ["_real_total" => "29"]);`);
    test.info().annotations.push({ type: 'storage', description: r.storage });
    expect(r.decision).toBe(`skipped:${r.previous}`);
  });

  test("another customer's paid order does not count", () => {
    const r = runCase(
      `$previous = $mk("other-" . $email, 40, "completed");`
    );
    test.info().annotations.push({ type: 'storage', description: r.storage });
    expect(r.decision).toBe('first');
  });

  test('a free previous order does not count', () => {
    const r = runCase(`$previous = $mk($email, 0, "completed");`);
    expect(r.decision).toBe('first');
  });

  test('the filter demotes a total-40 order to free', () => {
    const r = runCase(
      `$previous = $mk($email, 40, "completed");`,
      `add_filter("pixelflow_order_amount_paid", function ($amount, $order) use ($previous) { return $order->get_id() === $previous->get_id() ? 0 : $amount; }, 10, 2);`
    );
    expect(r.decision).toBe('first');
  });

  test('the filter promotes a total-0 order without _real_total', () => {
    const r = runCase(
      `$previous = $mk($email, 0, "completed");`,
      `add_filter("pixelflow_order_amount_paid", function ($amount, $order) use ($previous) { return $order->get_id() === $previous->get_id() ? 15 : $amount; }, 10, 2);`
    );
    expect(r.decision).toBe(`skipped:${r.previous}`);
  });

  test('the filter promotes a total-0 order whose _real_total is "0"', () => {
    const r = runCase(
      `$previous = $mk($email, 0, "completed", ["_real_total" => "0"]);`,
      `add_filter("pixelflow_order_amount_paid", function ($amount, $order) use ($previous) { return $order->get_id() === $previous->get_id() ? 15 : $amount; }, 10, 2);`
    );
    expect(r.decision).toBe(`skipped:${r.previous}`);
  });

  test('anchor: an order others were withheld for is recorded first', () => {
    const email = `q-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;
    const raw = wpEval(`
      $x = wc_create_order();
      $x->set_billing_email("${email}");
      $x->set_total(40);
      $x->update_meta_data("_pf_purchase_sent", "1");
      $x->save();
      $x->set_status("completed");
      $x->save();
      $y = wc_create_order();
      $y->set_billing_email("${email}");
      $y->add_product(wc_get_product(${PRODUCTS.virtual.id}), 1);
      $y->calculate_totals();
      $y->save();
      $y->set_status("processing");
      $y->save();
      $y = wc_get_order($y->get_id());
      $x = wc_get_order($x->get_id());
      $hooks = PixelFlow_WooCommerce_Cart_Hooks::instance();
      $m = new ReflectionMethod($hooks, "first_purchase_withholds");
      $m->setAccessible(true);
      $withheld = $m->invoke($hooks, $x);
      echo wp_json_encode([
        "x" => $x->get_id(),
        "y" => (string) $y->get_meta("_pf_purchase_first_only", true),
        "xWithheld" => $withheld,
        "xDecision" => (string) $x->get_meta("_pf_purchase_first_only", true),
      ]);
    `);
    const r = JSON.parse(raw.slice(raw.indexOf('{'))) as {
      x: number;
      y: string;
      xWithheld: boolean;
      xDecision: string;
    };
    expect(r.y).toBe(`skipped:${r.x}`);
    expect(r.xWithheld).toBe(false);
    expect(r.xDecision).toBe('first');
  });
});
