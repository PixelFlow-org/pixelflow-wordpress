/**
 * PHP error capture for the WooCommerce-deactivation smoke check.
 *
 * The site's FPM log is root-only, so the check routes PHP errors into
 * wp-content/debug.log by turning on WP_DEBUG_LOG for the duration of the
 * window and restoring wp-config afterwards.
 */
import { ssh, wp } from './ssh';

const DEBUG_LOG = 'wp-content/debug.log';

/** Enables WP_DEBUG_LOG (without display) and starts from an empty log. */
export function startErrorCapture(): void {
  wp('config set WP_DEBUG true --raw');
  wp('config set WP_DEBUG_LOG true --raw');
  wp('config set WP_DEBUG_DISPLAY false --raw');
  ssh(`: > ${DEBUG_LOG}`);
}

/** Restores wp-config to its normal state. Safe to call even if capture never started. */
export function stopErrorCapture(): void {
  wp('config set WP_DEBUG false --raw', { check: false });
  wp('config delete WP_DEBUG_LOG', { check: false });
  wp('config delete WP_DEBUG_DISPLAY', { check: false });
}

/** Lines logged since capture started that name the plugin. */
export function pluginErrors(): string[] {
  const raw = ssh(`cat ${DEBUG_LOG} 2>/dev/null || true`, { check: false });
  return raw
    .split('\n')
    .filter((line) => /pixelflow/i.test(line))
    .filter((line) => /(Fatal error|Warning|Notice|Deprecated|Uncaught)/i.test(line));
}
