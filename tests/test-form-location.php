<?php
/**
 * Location from the pf_loc cookie: the shared helper, and form events that fill the city,
 * state, postcode and country the form did not provide. Runs with no WC().
 *
 * The WooCommerce request path that calls the same helper is covered in
 * test-current-user-customer-data.php.
 *
 * Run: php tests/test-form-location.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-forms.php';

$failures = [];
$passes   = 0;

function wp_create_nonce($action = -1)
{
    return 'nonce';
}

function wp_send_json_success($data = null)
{
    $GLOBALS['__pf_json'] = ['success' => true, 'data' => $data];
}

/** pf_loc as the browser script writes it: each field already hashed. */
function pf_loc_values(): array
{
    return [
        'ct'      => hash('sha256', 'berlin'),
        'st'      => hash('sha256', 'be'),
        'zp'      => hash('sha256', '10115'),
        'country' => hash('sha256', 'de'),
    ];
}

/** Makes the request carry pf_loc. */
function pf_with_loc_cookie(?array $values = null): void
{
    $_COOKIE['pf_loc'] = (string) json_encode($values ?? pf_loc_values());
}

/** Reads the debug log written by this case. */
function pf_debug_log(): string
{
    $path = pixelflow_get_debug_log_path();

    return $path !== '' && file_exists($path) ? (string) file_get_contents($path) : '';
}

// ---------------------------------------------------------------------
// The helper
// ---------------------------------------------------------------------

pf_forms_case('The helper copies all four keys from the cookie exactly as stored', function () {
    pf_with_loc_cookie();
    $customer = ['em' => 'hashed-email'];
    $added    = pixelflow_append_location_from_cookie($customer);

    if ($added !== ['st', 'zp', 'ct', 'country']) {
        return 'added ' . json_encode($added);
    }

    // == because the key order differs; values are strings either way.
    return $customer == ['em' => 'hashed-email'] + pf_loc_values()
        ? true
        : 'customerData ' . json_encode($customer);
}, $failures, $passes);

pf_forms_case('The helper keeps a key that is already set', function () {
    pf_with_loc_cookie();
    $customer = ['ct' => 'from-the-form'];
    $added    = pixelflow_append_location_from_cookie($customer);

    if (in_array('ct', $added, true)) {
        return 'ct reported as added';
    }

    return $customer['ct'] === 'from-the-form' && ($customer['zp'] ?? null) === pf_loc_values()['zp']
        ? true
        : 'customerData ' . json_encode($customer);
}, $failures, $passes);

pf_forms_case('The helper adds nothing without the cookie', function () {
    $customer = ['em' => 'hashed-email'];
    $added    = pixelflow_append_location_from_cookie($customer);

    return $added === [] && $customer === ['em' => 'hashed-email'] ? true : 'customerData ' . json_encode($customer);
}, $failures, $passes);

pf_forms_case('The helper ignores a cookie that is not JSON or not an object', function () {
    foreach (['not json', '"berlin"', '42', '', '[]'] as $raw) {
        $_COOKIE['pf_loc'] = $raw;
        $customer          = [];
        $added             = pixelflow_append_location_from_cookie($customer);
        if ($added !== [] || $customer !== []) {
            return 'cookie ' . var_export($raw, true) . ' added ' . json_encode($customer);
        }
    }

    return true;
}, $failures, $passes);

pf_forms_case('The helper skips a non-scalar or empty field', function () {
    pf_with_loc_cookie(['ct' => ['berlin'], 'st' => '', 'zp' => null, 'country' => hash('sha256', 'de')]);
    $customer = [];
    $added    = pixelflow_append_location_from_cookie($customer);

    return $added === ['country'] && array_keys($customer) === ['country'] ? true : 'customerData ' . json_encode($customer);
}, $failures, $passes);

// ---------------------------------------------------------------------
// Form events
// ---------------------------------------------------------------------

pf_forms_case('An email-only form sends the four location keys from the cookie', function () {
    pf_with_visitor_cookie();
    pf_with_loc_cookie();
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission([
        'fields' => [['key' => 'your-email', 'type' => 'email', 'label' => 'Email', 'value' => 'ada@example.test']],
    ]));
    $event   = pf_only_event();

    if ($outcome !== 'sent' || $event === null) {
        return "outcome {$outcome}";
    }
    foreach (pf_loc_values() as $key => $value) {
        if (($event['customerData'][$key] ?? null) !== $value) {
            return "{$key} is " . json_encode($event['customerData'][$key] ?? null);
        }
    }

    return isset($event['customerData']['em']) ? true : 'the email was lost';
}, $failures, $passes);

pf_forms_case('A city from the form wins and the other three come from the cookie', function () {
    pf_with_visitor_cookie();
    pf_with_loc_cookie();
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission([
        'fields' => [
            ['key' => 'your-email', 'type' => 'email', 'label' => 'Email', 'value' => 'ada@example.test'],
            ['key' => 'your-city', 'type' => 'city', 'label' => 'City', 'value' => 'Paris'],
        ],
    ]));
    $customer = pf_only_event()['customerData'] ?? [];
    $loc      = pf_loc_values();
    $form_ct  = hash('sha256', pixelflow_normalize_city('Paris'));

    if (($customer['ct'] ?? null) !== $form_ct) {
        return 'ct is not the form\'s: ' . json_encode($customer['ct'] ?? null);
    }

    return ($customer['st'] ?? null) === $loc['st'] && ($customer['zp'] ?? null) === $loc['zp'] && ($customer['country'] ?? null) === $loc['country']
        ? true
        : 'customerData ' . json_encode($customer);
}, $failures, $passes);

pf_forms_case('A form with no cookie sends no location', function () {
    pf_with_visitor_cookie();
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $customer = pf_only_event()['customerData'] ?? null;

    if ($customer === null) {
        return 'no event was sent';
    }

    return array_intersect(['ct', 'st', 'zp', 'country'], array_keys($customer)) === [] ? true : 'customerData ' . json_encode($customer);
}, $failures, $passes);

pf_forms_case('A held submission takes location from the request that sends it', function () {
    pf_banner_unanswered();
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    if ($outcome !== 'held') {
        return "outcome {$outcome}";
    }
    $stored = (string) json_encode(pf_held_recipes());

    pf_decide('granted');
    pf_with_loc_cookie();
    PixelFlow_Form_Dispatcher::resolve_held();
    $customer = pf_only_event()['customerData'] ?? null;

    if ($customer === null) {
        return 'no event was sent after the grant';
    }
    if (strpos($stored, pf_loc_values()['ct']) !== false) {
        return 'location was stored with the hold';
    }

    return ($customer['ct'] ?? null) === pf_loc_values()['ct'] && ($customer['country'] ?? null) === pf_loc_values()['country']
        ? true
        : 'customerData ' . json_encode($customer);
}, $failures, $passes);

pf_forms_case('The debug log names the keys taken from the cookie', function () {
    $GLOBALS['__pf_options']['pixelflow_debug_log_key']                          = 'formslocation' . getmypid();
    $GLOBALS['__pf_options']['pixelflow_general_options']['forms_debug_enabled'] = 1;
    @unlink(pixelflow_get_debug_log_path());
    pf_with_visitor_cookie();
    pf_with_loc_cookie();
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $log = pf_debug_log();
    @unlink(pixelflow_get_debug_log_path());

    $entry = json_decode(trim(explode("\n---", $log)[0]), true);
    if ( ! is_array($entry) || ! isset($entry['identifiers'])) {
        return 'no identifiers in the log: ' . $log;
    }
    $missing = array_diff(['em', 'ct', 'st', 'zp', 'country'], (array) $entry['identifiers']);

    return $missing === [] ? true : 'identifiers ' . json_encode($entry['identifiers']);
}, $failures, $passes);

pf_forms_case('A denied submission reports the same blocked row with or without the cookie', function () {
    pf_decide('denied');
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $without = pf_blocked_rows();

    pf_forms_reset();
    pf_decide('denied');
    pf_with_loc_cookie();
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $with = pf_blocked_rows();

    if (count($without) !== 1 || pf_sent_events() !== []) {
        return 'expected one blocked row and no event, rows ' . json_encode($without);
    }
    unset($without[0]['time'], $with[0]['time'], $without[0]['blocked_at'], $with[0]['blocked_at']);

    return $with == $without ? true : 'the row changed: ' . json_encode($with) . ' vs ' . json_encode($without);
}, $failures, $passes);

pf_forms_finish($failures, $passes);
