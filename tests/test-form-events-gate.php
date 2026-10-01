<?php
/**
 * Which form submissions send at all: the master toggle, confidence, the title deny-list, the
 * per-form switch and the key a form's configuration is stored under. Runs with no WC().
 *
 * Run: php tests/test-form-events-gate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-forms.php';

$failures = [];
$passes   = 0;

pf_forms_case('Master toggle off sends nothing', function () {
    pf_forms_reset(['forms_enabled' => 0]);
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $outcome === 'skipped' && $GLOBALS['__pf_http'] === [] ? true : "outcome {$outcome}, calls " . count($GLOBALS['__pf_http']);
}, $failures, $passes);

pf_forms_case('Plugin disabled sends nothing even with form tracking on', function () {
    pf_forms_reset(['enabled' => 0]);
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $GLOBALS['__pf_http'] === [] ? true : 'a request was made';
}, $failures, $passes);

pf_forms_case('A high-confidence submission sends one Lead', function () {
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $event   = pf_only_event();

    if ($outcome !== 'sent' || $event === null) {
        return "outcome {$outcome}, events " . count(pf_sent_events());
    }

    return $event['eventName'] === 'Lead' ? true : 'event was ' . $event['eventName'];
}, $failures, $passes);

pf_forms_case('A search-titled form sends nothing', function () {
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission(['form_title' => 'Search our site']));

    return $outcome === 'skipped' && pf_sent_events() === [] ? true : "outcome {$outcome}";
}, $failures, $passes);

pf_forms_case('A French login form sends nothing', function () {
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission(['form_title' => 'Connexion client']));

    return $outcome === 'skipped' ? true : "outcome {$outcome}";
}, $failures, $passes);

$medium = [
    'source'     => 'custom',
    'form_id'    => '9',
    'form_title' => 'Callback request',
    'fields'     => [
        ['key' => 'phone-number', 'type' => 'text', 'label' => 'Phone number', 'value' => '555 0100'],
    ],
];

pf_forms_case('A medium-confidence form sends nothing until enabled', function () use ($medium) {
    $before = PixelFlow_Form_Dispatcher::dispatch($medium);
    update_option(PIXELFLOW_FORM_SETTINGS_OPTION, pixelflow_apply_form_settings_patch([], ['custom:9' => ['enabled' => 1]]));
    PixelFlow_Form_Dispatcher::reset_request_state();
    $after = PixelFlow_Form_Dispatcher::dispatch($medium);

    if ($before !== 'skipped') {
        return "before enabling: {$before}";
    }

    return $after === 'sent' && count(pf_sent_events()) === 1 ? true : "after enabling: {$after}";
}, $failures, $passes);

pf_forms_case('An untouched medium form that gains a native email field sends without a re-save', function () use ($medium) {
    $edited             = $medium;
    $edited['fields'][] = ['key' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => 'a@example.test'];
    $outcome            = PixelFlow_Form_Dispatcher::dispatch($edited);

    return $outcome === 'sent' && get_option(PIXELFLOW_FORM_SETTINGS_OPTION, []) === [] ? true : "outcome {$outcome}";
}, $failures, $passes);

pf_forms_case('A switch a person turned off stays off after an unrelated save', function () {
    $records = pixelflow_apply_form_settings_patch([], ['custom:7' => ['enabled' => 0]]);
    $records = pixelflow_apply_form_settings_patch($records, ['custom:7' => ['event' => 'Contact']]);
    update_option(PIXELFLOW_FORM_SETTINGS_OPTION, $records);

    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $other   = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission(['form_id' => '8']));

    if ($outcome !== 'skipped') {
        return "switched-off form: {$outcome}";
    }

    return $other === 'sent' ? true : "untouched form in the same list: {$other}";
}, $failures, $passes);

pf_forms_case('Two forms from different plugins with the same id keep separate configurations', function () {
    update_option(PIXELFLOW_FORM_SETTINGS_OPTION, pixelflow_apply_form_settings_patch([], [
        'custom:5' => ['event' => 'Schedule'],
    ]));

    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission(['form_id' => '5']));
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission(['source' => 'another', 'form_id' => '5']));
    $events = pf_sent_events();

    if (count($events) !== 2) {
        return 'expected two events, got ' . count($events);
    }
    $names = [$events[0]['body']['eventData']['eventName'], $events[1]['body']['eventData']['eventName']];

    return $names === ['Schedule', 'Lead'] ? true : 'events were ' . implode(', ', $names);
}, $failures, $passes);

pf_forms_case('Choosing an event does not enable a medium form', function () use ($medium) {
    update_option(PIXELFLOW_FORM_SETTINGS_OPTION, pixelflow_apply_form_settings_patch([], ['custom:9' => ['event' => 'Contact']]));
    $outcome = PixelFlow_Form_Dispatcher::dispatch($medium);

    return $outcome === 'skipped' ? true : "outcome {$outcome}";
}, $failures, $passes);

pf_forms_finish($failures, $passes);
