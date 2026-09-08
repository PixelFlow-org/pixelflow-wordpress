/**
 * Preconditions for a live run, checked before any browser starts.
 *
 * These used to be a checklist in the skill for a human to eyeball. Checking
 * them here means a missing fixture fails in the first seconds with a precise
 * message, instead of surfacing half an hour later as a puzzling assertion.
 */
import { ADMIN, COUPON, CUSTOMER, PRODUCTS, VARIATIONS } from './site';
import { ssh, wp, wpEval } from './helpers/ssh';

function check(condition: boolean, message: string, problems: string[]): void {
  if (!condition) problems.push(message);
}

export default function globalSetup(): void {
  const problems: string[] = [];

  // 1. Can we reach the site at all?
  try {
    ssh('true', { timeoutMs: 20_000 });
  } catch (error) {
    throw new Error(
      `Cannot reach the test site over SSH — everything else depends on it.\n${(error as Error).message}`
    );
  }

  // 2. Credentials for the two accounts the matrix runs as.
  check(Boolean(ADMIN.password), 'PF_ADMIN_PASS is not set (copy .env.example to .env)', problems);
  check(
    Boolean(CUSTOMER.password),
    'PF_CUSTOMER_PASS is not set (copy .env.example to .env)',
    problems
  );

  // 3. The plugin is installed, connected, and has a debug log key to write to.
  const active = wp('plugin get pixelflow --field=status', { check: false }).trim();
  check(active === 'active', `the pixelflow plugin is not active on the site (status: ${active || 'unknown'})`, problems);

  const woo = wp('plugin get woocommerce --field=status', { check: false }).trim();
  check(woo === 'active', `WooCommerce is not active on the site (status: ${woo || 'unknown'})`, problems);

  const enabled = wpEval('$o = get_option("pixelflow_general_options", []); echo empty($o["enabled"]) ? "0" : "1";');
  check(enabled.trim() === '1', 'the Pixelflow connection is switched off in the plugin settings', problems);

  check(
    wp('option get pixelflow_debug_log_key', { check: false }).trim() !== '',
    'the plugin has not generated a debug log key yet — open the settings page once',
    problems
  );

  // 4. The consent platform, without which every consent scenario passes vacuously.
  const consentApi = wp('plugin get wp-consent-api --field=status', { check: false }).trim();
  check(
    consentApi === 'active',
    `the WP Consent API plugin is not active on the site (status: ${consentApi || 'unknown'})`,
    problems
  );

  const cmp = wp('plugin get complianz-gdpr --field=status', { check: false }).trim();
  check(
    cmp === 'active',
    `the Complianz consent plugin is not active on the site (status: ${cmp || 'unknown'})`,
    problems
  );

  const consentType = wpEval(
    'echo function_exists("wp_get_consent_type") ? (wp_get_consent_type() ?: "") : "";'
  ).trim();
  check(
    consentType === 'optin',
    `the site's consent type is "${consentType || 'empty'}", not "optin" — with anything else the plugin ` +
      'sends unconditionally and every consent scenario would pass without proving anything. ' +
      'Configure the CMP for an opt-in region; this suite deliberately does not repair the site.',
    problems
  );

  // 5. The storefront is actually reachable by visitors.
  const comingSoon = wp('option get woocommerce_coming_soon', { check: false }).trim();
  check(
    comingSoon !== 'yes',
    'WooCommerce is in "coming soon" mode, so the storefront is hidden from visitors',
    problems
  );

  // 6. The fixtures the matrix names.
  const skus = [
    ...Object.values(PRODUCTS).map((product) => product.sku),
    ...Object.values(VARIATIONS).map((variation) => variation.sku),
  ];
  const missing = wpEval(
    `$missing = []; foreach (["${skus.join('","')}"] as $sku) { if (!wc_get_product_id_by_sku($sku)) { $missing[] = $sku; } } echo implode(",", $missing);`
  ).trim();
  check(missing === '', `product fixtures missing from the site: ${missing}`, problems);

  const listing = wpEval(
    'echo (int) (new WP_Query(["post_type" => "product", "posts_per_page" => -1, "fields" => "ids", "tax_query" => [["taxonomy" => "product_cat", "field" => "slug", "terms" => "pf-fixtures"]]]))->post_count;'
  ).trim();
  check(
    Number(listing) >= Object.keys(PRODUCTS).length,
    `the pf-fixtures category holds ${listing} products; the matrix needs every fixture in it`,
    problems
  );

  const coupon = wpEval(`echo (int) wc_get_coupon_id_by_code("${COUPON.code}");`).trim();
  check(coupon !== '0', `the "${COUPON.code}" coupon does not exist on the site`, problems);

  const customer = wpEval(
    `$u = get_user_by("login", "${CUSTOMER.username}"); echo $u ? get_user_meta($u->ID, "billing_city", true) : "";`
  ).trim();
  check(
    customer !== '',
    `the ${CUSTOMER.username} account is missing or has no billing address — the location scenarios need one`,
    problems
  );

  // 7. Offline payment, so checkout can reach the thank-you page.
  const gateways = wpEval(
    'echo implode(",", array_keys(WC()->payment_gateways->get_available_payment_gateways()));'
  ).trim();
  check(
    /cod|cheque|bacs/.test(gateways),
    `no offline payment gateway is enabled (available: ${gateways || 'none'})`,
    problems
  );

  if (problems.length > 0) {
    throw new Error(
      `The test site is not ready for a live run:\n${problems.map((p) => `  - ${p}`).join('\n')}`
    );
  }
}
