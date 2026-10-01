<?php
/**
 * What a form event carries: hashed identifiers, the form title, the visitor identifier, the
 * browser and click identifiers from cookies — and nothing else from the form.
 *
 * Run: php tests/test-form-payload.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-forms.php';

$failures = [];
$passes   = 0;

pf_forms_case('Payload carries the title, hashed email and phone, and no message text', function () {
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $event = pf_only_event();
    if ($event === null) {
        return 'no single event was sent';
    }

    $raw = (string) json_encode($GLOBALS['__pf_http']);
    if (strpos($raw, 'SECRET-MESSAGE') !== false || strpos($raw, 'other@example.test') !== false) {
        return 'message text reached the payload';
    }
    if (strpos(strtolower($raw), 'ada@example.test') !== false) {
        return 'the raw email reached the payload';
    }
    if (($event['additionalData']['contentName'] ?? null) !== 'Contact us') {
        return 'contentName was ' . json_encode($event['additionalData']);
    }
    if (($event['customerData']['em'] ?? null) !== hash('sha256', 'ada@example.test')) {
        return 'em is not the hash of the normalised email';
    }
    if (($event['customerData']['ph'] ?? null) !== hash('sha256', '4402012345678')) {
        return 'ph is not the hash of the normalised phone';
    }
    foreach (['event_id', 'eventTime', 'actionSource', 'siteURL'] as $key) {
        if ( ! isset($event[$key])) {
            return "missing {$key}";
        }
    }

    return $event['actionSource'] === 'website' ? true : 'actionSource was ' . $event['actionSource'];
}, $failures, $passes);

pf_forms_case('Visitor identifier equals pixelflow_resolve_external_id() for that visitor', function () {
    pf_with_visitor_cookie();
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $event    = pf_only_event();
    $expected = pixelflow_resolve_external_id('wp_0123');

    return $expected !== null && ($event['customerData']['external_id'] ?? null) === $expected
        ? true
        : 'external_id did not match';
}, $failures, $passes);

pf_forms_case('A missing visitor cookie omits the visitor identifier and still sends the hashed email', function () {
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $event = pf_only_event();

    if ($event === null) {
        return 'no event was sent';
    }
    if (array_key_exists('external_id', $event['customerData'])) {
        return 'external_id was sent';
    }

    return isset($event['customerData']['em']) ? true : 'hashed email missing';
}, $failures, $passes);

pf_forms_case('A static value is sent as value with no currency', function () {
    update_option(PIXELFLOW_FORM_SETTINGS_OPTION, pixelflow_apply_form_settings_patch([], ['custom:7' => ['value' => '25.5']]));
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $event = pf_only_event();

    if (($event['additionalData']['value'] ?? null) !== 25.5) {
        return 'value was ' . json_encode($event['additionalData'] ?? null);
    }

    return array_key_exists('currency', $event['additionalData']) ? 'a currency was sent' : true;
}, $failures, $passes);

pf_forms_case('No value is set without a static value', function () {
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $event = pf_only_event();

    return array_key_exists('value', $event['additionalData']) || array_key_exists('currency', $event['additionalData'])
        ? 'value or currency present'
        : true;
}, $failures, $passes);

pf_forms_case('An empty site id sends nothing', function () {
    $GLOBALS['__pf_options']['pixelflow_script_params']['siteExternalId'] = '';
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $outcome === 'skipped' && $GLOBALS['__pf_http'] === [] ? true : "outcome {$outcome}";
}, $failures, $passes);

pf_forms_case('An empty API key sends nothing', function () {
    $GLOBALS['__pf_options']['pixelflow_script_params']['apiKey'] = '';
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $GLOBALS['__pf_http'] === [] ? true : 'a request was made';
}, $failures, $passes);

pf_forms_case('Meta and TikTok cookies become fbp, ttp and ttclid, and no gclid', function () {
    $_COOKIE['_fbp']          = 'fb.1.1700000000.123';
    $_COOKIE['_ttp']          = 'tiktok-browser-1';
    $_COOKIE['_pf_click_ids'] = 'ttclid=E_C_P_abc&gclid=other';
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $event = pf_only_event();

    if (($event['fbp'] ?? null) !== 'fb.1.1700000000.123' || ($event['ttp'] ?? null) !== 'tiktok-browser-1') {
        return 'fbp/ttp were ' . json_encode([$event['fbp'] ?? null, $event['ttp'] ?? null]);
    }
    if (($event['ttclid'] ?? null) !== 'E_C_P_abc') {
        return 'ttclid was ' . json_encode($event['ttclid'] ?? null);
    }

    return strpos((string) json_encode($event), 'gclid') === false ? true : 'gclid was forwarded';
}, $failures, $passes);

pf_forms_case('The client IP and user agent of the submitting request are sent', function () {
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $event = pf_only_event();

    return ($event['customerData']['client_ip_address'] ?? null) === PF_TEST_IP
        && isset($event['customerData']['client_user_agent'])
        ? true
        : 'client fields were ' . json_encode($event['customerData']);
}, $failures, $passes);

pf_forms_finish($failures, $passes);
