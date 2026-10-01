<?php
/**
 * The public hooks: the suppression filter, the event filter, and the action through which an
 * unsupported form plugin enters the same gated path.
 *
 * Run: php tests/test-form-hooks.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-forms.php';

pixelflow_forms_bootstrap();

$failures = [];
$passes   = 0;

pf_forms_case('pixelflow_should_send_form_event returning false stops the send', function () {
    $GLOBALS['__pf_test_filter_callbacks']['pixelflow_should_send_form_event'] = static function ($send, $submission) {
        return $submission['form_id'] === '7' ? false : $send;
    };
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $outcome === 'skipped' && $GLOBALS['__pf_http'] === [] ? true : "outcome {$outcome}";
}, $failures, $passes);

pf_forms_case('pixelflow_form_event changes the name, value and customer data that are sent', function () {
    $GLOBALS['__pf_test_filter_callbacks']['pixelflow_form_event'] = static function ($event) {
        $event['eventName']          = 'MyCustomLead';
        $event['value']              = 12;
        $event['customerData']['em'] = 'replaced-hash';
        unset($event['customerData']['ph']);

        return $event;
    };
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $event = pf_only_event();

    if ($event === null) {
        return 'no event';
    }
    if ($event['eventName'] !== 'MyCustomLead') {
        return 'name was ' . $event['eventName'];
    }
    if ((float) ($event['additionalData']['value'] ?? 0) !== 12.0) {
        return 'value was ' . json_encode($event['additionalData']);
    }

    return ($event['customerData']['em'] ?? null) === 'replaced-hash' && ! isset($event['customerData']['ph'])
        ? true
        : 'customerData was ' . json_encode($event['customerData']);
}, $failures, $passes);

pf_forms_case('A filtered submission that is held sends the filtered name after a grant without the filters running again', function () {
    $calls = 0;
    $GLOBALS['__pf_test_filter_callbacks']['pixelflow_form_event'] = static function ($event) use (&$calls) {
        $calls++;
        $event['eventName'] = 'Contact';

        return $event;
    };
    $GLOBALS['__pf_test_filter_callbacks']['pixelflow_should_send_form_event'] = static function ($send) use (&$calls) {
        $calls++;

        return $send;
    };

    pf_banner_unanswered();
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $before = $calls;
    pf_decide('granted');
    PixelFlow_Form_Dispatcher::resolve_held();
    $event = pf_only_event();

    if ($event === null || $event['eventName'] !== 'Contact') {
        return 'replayed event ' . json_encode($event['eventName'] ?? null);
    }

    return $before === 2 && $calls === 2 ? true : "filters ran {$calls} times, {$before} before the grant";
}, $failures, $passes);

pf_forms_case('A submission through pixelflow_track_form sends like a supported one', function () {
    do_action('pixelflow_track_form', pf_contact_submission(['source' => 'jetformbuilder', 'form_id' => '44']));
    $event = pf_only_event();

    if ($event === null) {
        return 'no event';
    }
    if (strpos((string) json_encode($GLOBALS['__pf_http']), 'SECRET-MESSAGE') !== false) {
        return 'message text reached the payload';
    }

    return isset($event['customerData']['em']) ? true : 'no hashed email';
}, $failures, $passes);

pf_forms_case('pixelflow_track_form is subject to the automated-traffic gate', function () {
    $_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.31';
    do_action('pixelflow_track_form', pf_contact_submission(['source' => 'jetformbuilder']));

    return pf_sent_events() === [] && (pf_blocked_rows()[0]['reason'] ?? '') === 'bot' ? true : 'not blocked as bot';
}, $failures, $passes);

pf_forms_case('pixelflow_track_form is subject to consent', function () {
    pf_banner_unanswered();
    do_action('pixelflow_track_form', pf_contact_submission(['source' => 'jetformbuilder']));

    return pf_sent_events() === [] && count(pf_held_recipes()) === 1 ? true : 'not held';
}, $failures, $passes);

pf_forms_case('pixelflow_track_form is subject to the repeat window', function () {
    do_action('pixelflow_track_form', pf_contact_submission(['source' => 'jetformbuilder']));
    PixelFlow_Form_Dispatcher::reset_request_state();
    do_action('pixelflow_track_form', pf_contact_submission(['source' => 'jetformbuilder']));

    return count(pf_sent_events()) === 1 ? true : 'events ' . count(pf_sent_events());
}, $failures, $passes);

pf_forms_case('A malformed submission through pixelflow_track_form is ignored', function () {
    do_action('pixelflow_track_form', ['fields' => []]);
    do_action('pixelflow_track_form', 'not a submission');

    return $GLOBALS['__pf_http'] === [] ? true : 'a request was made';
}, $failures, $passes);

pf_forms_finish($failures, $passes);
