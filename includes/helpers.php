<?php
/**
 * Helper Functions
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Normalize email address
 *
 * @param string $email Email address to normalize
 * @return string Normalized email address
 */
function pixelflow_normalize_email(string $email): string
{
    $email = trim($email);
    $email = mb_strtolower($email, 'UTF-8');

    return $email;
}

/**
 * Normalize phone number
 *
 * @param string $phone Phone number to normalize
 * @return string Normalized phone number
 */
function pixelflow_normalize_phone(string $phone): string
{
    $phone = trim($phone);

    // remove everything except digits
    $phone = preg_replace('/\D+/', '', $phone);
    if ( ! is_string($phone)) {
        return '';
    }

    // remove leading zeros (Meta requirement mentions leading zeros)
    $phone = ltrim($phone, '0');

    return $phone;
}

/**
 * Normalize name
 *
 * @param string $name Name to normalize
 * @return string Normalized name
 */
function pixelflow_normalize_name(string $name): string
{
    $name = trim($name);
    $name = mb_strtolower($name, 'UTF-8');

    // keep letters only (unicode), remove punctuation/spaces
    $name = preg_replace('/[^\p{L}]+/u', '', $name);
    if ( ! is_string($name)) {
        return '';
    }

    return $name;
}

/**
 * Normalize city name
 *
 * @param string $city City name to normalize
 * @return string Normalized city name
 */
function pixelflow_normalize_city(string $city): string
{
    $city = trim($city);
    $city = mb_strtolower($city, 'UTF-8');

    // Meta: lowercase, no punctuation, no spaces. Keep unicode letters/numbers.
    $city = preg_replace('/[^\p{L}\p{N}]+/u', '', $city);
    if ( ! is_string($city)) {
        return '';
    }

    return $city;
}

/**
 * Normalize state
 *
 * @param string $state State to normalize
 * @return string Normalized state
 */
function pixelflow_normalize_state(string $state): string
{
    $state = trim($state);
    $state = mb_strtolower($state, 'UTF-8');

    // Meta: for US use 2-char abbreviation; we keep only letters/numbers.
    $state = preg_replace('/[^\p{L}\p{N}]+/u', '', $state);
    if ( ! is_string($state)) {
        return '';
    }

    return $state;
}

/**
 * Normalize zip code
 *
 * @param string $zip Zip code to normalize
 * @return string Normalized zip code
 */
function pixelflow_normalize_zip(string $zip): string
{
    $zip = trim($zip);
    $zip = mb_strtolower($zip, 'UTF-8');

    // Meta: no spaces, no dash (we remove all non-alnum)
    $zip = preg_replace('/[^\p{L}\p{N}]+/u', '', $zip);
    if ( ! is_string($zip)) {
        return '';
    }

    return $zip;
}

/**
 * Normalize country code
 *
 * @param string $country Country code to normalize
 * @return string Normalized country code
 */
function pixelflow_normalize_country(string $country): string
{
    $country = trim($country);
    $country = mb_strtolower($country, 'UTF-8');

    // Woo stores ISO alpha-2 already; keep only letters
    $country = preg_replace('/[^\p{L}]+/u', '', $country);
    if ( ! is_string($country)) {
        return '';
    }

    return $country;
}

/**
 * Normalize external ID
 *
 * @param string $external_id External ID to normalize
 * @return string Normalized external ID
 */
function pixelflow_normalize_external_id(string $external_id): string
{
    $external_id = trim($external_id);
    $external_id = mb_strtolower($external_id, 'UTF-8');

    return $external_id;
}

/**
 * Hash value with SHA256 if not empty
 *
 * @param string $value Value to hash
 * @return string Hashed value or empty string
 */
function pixelflow_sha256_if_not_empty(string $value): string
{
    if ($value === '') {
        return '';
    }

    return hash('sha256', $value);
}

/**
 * Get client user agent
 *
 * @return string User agent string
 */
function pixelflow_get_client_user_agent(): string
{
    if ( ! isset($_SERVER['HTTP_USER_AGENT'])) {
        return '';
    }

    $ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));

    if ( ! is_string($ua)) {
        return '';
    }

    return trim($ua);
}

/**
 * Get client IP address
 *
 * @return string IP address
 */
function pixelflow_get_client_ip_address(): string
{
    // Prefer CF if available, then XFF, then REMOTE_ADDR
    $candidates = [];

    if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && is_string($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
    }

    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && is_string($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $xff = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
        $parts = explode(',', $xff);
        if (isset($parts[0]) && is_string($parts[0])) {
            $candidates[] = trim($parts[0]);
        }
    }

    if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }

    foreach ($candidates as $ip) {
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

/**
 * Get UTM parameters from cookie
 *
 * @return array UTM parameters array
 */
function pixelflow_get_utm_params_from_cookie(): array
{
    if ( ! isset($_COOKIE['_pf_utm']) || ! is_string($_COOKIE['_pf_utm'])) {
        return [];
    }

    $raw = sanitize_text_field(wp_unslash($_COOKIE['_pf_utm']));

    if ($raw === '') {
        return [];
    }

    parse_str($raw, $parsed);

    if ( ! is_array($parsed) || empty($parsed)) {
        return [];
    }

    $allowed = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
    ];

    $out = [];

    foreach ($allowed as $key) {
        if (isset($parsed[$key]) && is_scalar($parsed[$key])) {
            $out[$key] = sanitize_text_field((string)$parsed[$key]);
        }
    }

    return $out;
}

/**
 * Get the absolute filesystem path to the WooCommerce debug log file.
 *
 * Strips all non-alphanumeric characters from the stored key and verifies
 * the resulting path stays inside WP_CONTENT_DIR (defense-in-depth against
 * a malicious stored value such as '../wp-config.php').
 *
 * Returns '' when the key is absent or the path escapes the content directory.
 *
 * @return string Absolute path or empty string on failure
 */
define('PIXELFLOW_DEBUG_LOG_MAX_SIZE', 1_048_576); // 1 MB

function pixelflow_get_debug_log_path(): string
{
    $key = preg_replace('/[^a-zA-Z0-9]/', '', (string) get_option('pixelflow_debug_log_key', ''));

    if (empty($key)) {
        return '';
    }

    $path         = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'pixelflow_debug_' . $key . '.log';
    $real_content = realpath(WP_CONTENT_DIR);

    // For a file that doesn't exist yet, realpath() returns false — use the constructed path directly.
    $real_path = file_exists($path) ? realpath($path) : $path;

    if ($real_content !== false && strpos($real_path, $real_content . DIRECTORY_SEPARATOR) !== 0) {
        return '';
    }

    return $path;
}

/**
 * Append a debug log entry to the log file, trimming the oldest entries when
 * the file exceeds PIXELFLOW_DEBUG_LOG_MAX_SIZE (filterable via
 * 'pixelflow_debug_log_max_size'). The entire append + trim is performed under
 * a single LOCK_EX so concurrent requests are safe.
 *
 * @param string $log_file Absolute path to the log file.
 * @param string $data     Raw string to append (must end with "\n---\n").
 */
function pixelflow_write_debug_log_entry(string $log_file, string $data): void
{
    $max_size = (int) apply_filters('pixelflow_debug_log_max_size', PIXELFLOW_DEBUG_LOG_MAX_SIZE);
    if ($max_size <= 0) {
        $max_size = PIXELFLOW_DEBUG_LOG_MAX_SIZE;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $fp = fopen($log_file, 'c+');
    if ( ! $fp) {
        return;
    }

    flock($fp, LOCK_EX);

    fseek($fp, 0, SEEK_END);
    fwrite($fp, $data);
    fflush($fp);

    $size = ftell($fp);

    if ($size > $max_size) {
        fseek($fp, 0, SEEK_SET);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $content = fread($fp, $size);

        // Find the first clean entry boundary past the midpoint, then drop everything before it.
        $cut_pos = strpos($content, "\n---\n", (int) ($size / 2));

        if ($cut_pos !== false) {
            $new_content = substr($content, $cut_pos + strlen("\n---\n"));
            ftruncate($fp, 0);
            fseek($fp, 0, SEEK_SET);
            fwrite($fp, $new_content);
        }
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Append cookie parameters to payload
 *
 * @param array &$payload Payload array (passed by reference)
 * @param array $map Cookie name mapping
 * @return void
 */
function pixelflow_append_cookie_params(
    array &$payload,
    array $map = [
        'clkId'    => 'pf_clkid',
        'fbc'      => 'pf_fbc',
        'fbp'      => '_fbp',
    ]
): void {
    if ( ! isset($payload['eventData']) || ! is_array($payload['eventData'])) {
        return;
    }

    foreach ($map as $param => $cookieName) {
        if ( ! isset($_COOKIE[$cookieName])) {
            continue;
        }

        $val = sanitize_text_field(wp_unslash($_COOKIE[$cookieName]));

        if ( ! is_string($val) || $val === '') {
            continue;
        }

        $payload['eventData'][$param] = $val;
    }

    // fallback for _fbc cookie
    if( ! isset($payload['eventData']['fbc']) && isset($_COOKIE['_fbc']) && is_string($_COOKIE['_fbc'])) {
        $val = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));

        if (is_string($val) && $val !== '') {
            $payload['eventData']['fbc'] = $val;
        }
    }
}
