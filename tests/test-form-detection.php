<?php
/**
 * Identifier detection, manual choices that stick, the combined-name split, the suggested
 * event and the languages the name patterns cover.
 *
 * Run: php tests/test-form-detection.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-forms.php';

$failures = [];
$passes   = 0;

/**
 * @param string $key   Field key
 * @param string $type  Canonical type
 * @param string $label Label
 * @return array
 */
function pf_field(string $key, string $type, string $label = ''): array
{
    return ['key' => $key, 'type' => $type, 'label' => $label];
}

pf_forms_case('Email with a consent checkbox and a captcha suggests CompleteRegistration', function () {
    $c = pixelflow_form_classify('Stay in touch', [
        pf_field('email', 'email'),
        pf_field('gdpr', 'consent'),
        pf_field('captcha', 'captcha'),
        pf_field('submit', 'submit'),
    ]);

    return $c['suggested_event'] === 'CompleteRegistration' ? true : 'suggested ' . $c['suggested_event'];
}, $failures, $passes);

pf_forms_case('Email with a message field suggests Lead', function () {
    $c = pixelflow_form_classify('Contact us', [pf_field('email', 'email'), pf_field('message', 'textarea')]);

    return $c['suggested_event'] === 'Lead' ? true : 'suggested ' . $c['suggested_event'];
}, $failures, $passes);

pf_forms_case('The same fields under a Newsletter signup title suggest CompleteRegistration', function () {
    $c = pixelflow_form_classify('Newsletter signup — footer', [pf_field('email', 'email'), pf_field('message', 'textarea')]);

    return $c['suggested_event'] === 'CompleteRegistration' ? true : 'suggested ' . $c['suggested_event'];
}, $failures, $passes);

pf_forms_case('Native types are detected without configuration', function () {
    $map = pixelflow_form_detect_map([pf_field('a', 'email'), pf_field('b', 'phone'), pf_field('c', 'city')]);

    return $map === ['em' => 'a', 'ph' => 'b', 'ct' => 'c'] ? true : json_encode($map);
}, $failures, $passes);

pf_forms_case('Two separate email-type fields map the first in form order', function () {
    $map = pixelflow_form_detect_map([pf_field('email', 'email'), pf_field('confirm-email', 'email')]);

    return ($map['em'] ?? null) === 'email' ? true : json_encode($map);
}, $failures, $passes);

pf_forms_case('A saved override survives a form edit and detection does not move it', function () {
    $record = pixelflow_apply_form_settings_patch([], [
        'custom:1' => ['fields' => ['ph' => ['key' => 'mobile', 'type' => 'text', 'label' => 'Mobile']]],
    ])['custom:1'];
    $edited = [pf_field('phone', 'phone'), pf_field('mobile', 'text', 'Mobile'), pf_field('new', 'text')];
    $config = pixelflow_form_effective_config($record, 'Contact', $edited);

    return ($config['map']['ph'] ?? null) === 'mobile' ? true : json_encode($config['map']);
}, $failures, $passes);

pf_forms_case('A field added later is detected without a re-save', function () {
    $config = pixelflow_form_effective_config([], 'Contact', [pf_field('email', 'email'), pf_field('tel', 'phone')]);

    return ($config['map']['ph'] ?? null) === 'tel' ? true : json_encode($config['map']);
}, $failures, $passes);

pf_forms_case('A cleared identifier stays absent', function () {
    $record = pixelflow_apply_form_settings_patch([], [
        'custom:1' => ['fields' => ['em' => ['key' => '', 'type' => '', 'label' => '']]],
    ])['custom:1'];
    $config = pixelflow_form_effective_config($record, 'Contact', [pf_field('email', 'email')]);

    return ! isset($config['map']['em']) ? true : json_encode($config['map']);
}, $failures, $passes);

pf_forms_case('A chosen field that was removed yields no identifier and keeps the stored choice', function () {
    $records = pixelflow_apply_form_settings_patch([], [
        'custom:1' => ['fields' => ['ph' => ['key' => 'gone', 'type' => 'phone', 'label' => 'Phone']]],
    ]);
    $config  = pixelflow_form_effective_config($records['custom:1'], 'Contact', [pf_field('phone', 'phone')]);
    $data    = pixelflow_form_build_customer_data([['key' => 'phone', 'value' => '555 0100']], $config['map']);

    if (isset($data['customer']['ph'])) {
        return 'the detected phone replaced the removed choice';
    }

    return ($records['custom:1']['fields']['ph']['key'] ?? null) === 'gone' ? true : 'choice was dropped';
}, $failures, $passes);

pf_forms_case('A submission with no resolvable identifier still sends', function () {
    pf_with_visitor_cookie();
    update_option(PIXELFLOW_FORM_SETTINGS_OPTION, pixelflow_apply_form_settings_patch([], ['custom:3' => ['enabled' => 1]]));
    $outcome = PixelFlow_Form_Dispatcher::dispatch([
        'source'     => 'custom',
        'form_id'    => '3',
        'form_title' => 'Feedback',
        'fields'     => [['key' => 'rating', 'type' => 'choice', 'label' => 'Rating', 'value' => '5']],
    ]);
    $event = pf_only_event();

    if ($outcome !== 'sent' || $event === null) {
        return "outcome {$outcome}";
    }
    $keys = array_keys($event['customerData']);
    sort($keys);

    return $keys === ['client_ip_address', 'client_user_agent', 'external_id'] ? true : 'customerData keys ' . implode(',', $keys);
}, $failures, $passes);

pf_forms_case('A message field is never searched for an identifier', function () {
    $map = pixelflow_form_detect_map([pf_field('email-message', 'textarea', 'Email us your message')]);

    return $map === [] ? true : json_encode($map);
}, $failures, $passes);

pf_forms_case('A combined name field splits on the first space', function () {
    $data = pixelflow_form_build_customer_data([['key' => 'n', 'value' => 'Ada King Lovelace']], ['fn' => 'n', 'ln' => 'n']);

    return ($data['customer']['fn'] ?? null) === hash('sha256', 'ada')
        && ($data['customer']['ln'] ?? null) === hash('sha256', 'kinglovelace')
        ? true
        : json_encode($data);
}, $failures, $passes);

pf_forms_case('A single-word name yields fn only', function () {
    $data = pixelflow_form_build_customer_data([['key' => 'n', 'value' => 'Ada']], ['fn' => 'n', 'ln' => 'n']);

    return $data['present'] === ['fn'] ? true : json_encode($data['present']);
}, $failures, $passes);

pf_forms_case('A leading space still yields a non-empty fn', function () {
    $data = pixelflow_form_build_customer_data([['key' => 'n', 'value' => '  Ada Lovelace']], ['fn' => 'n', 'ln' => 'n']);

    return ($data['customer']['fn'] ?? null) === hash('sha256', 'ada') ? true : json_encode($data);
}, $failures, $passes);

pf_forms_case('A plain text name field is detected as a combined name', function () {
    $map = pixelflow_form_detect_map([pf_field('your-name', 'text', 'Your name'), pf_field('email', 'email')]);

    return ($map['fn'] ?? null) === 'your-name' && ($map['ln'] ?? null) === 'your-name' ? true : json_encode($map);
}, $failures, $passes);

pf_forms_case('Prénom and Nom map to fn and ln without splitting', function () {
    $map = pixelflow_form_detect_map([pf_field('f1', 'text', 'Prénom'), pf_field('f2', 'text', 'Nom')]);

    return $map === ['fn' => 'f1', 'ln' => 'f2'] ? true : json_encode($map);
}, $failures, $passes);

pf_forms_case('A label that only contains a name word is unconfirmed and not mapped', function () {
    $fields = [pf_field('company', 'text', 'Company name'), pf_field('email', 'email', 'Email')];
    $found  = pixelflow_form_detect($fields);

    if (isset($found['map']['fn']) || isset($found['map']['ln'])) {
        return 'mapped ' . json_encode($found['map']);
    }

    return $found['unconfirmed'] === ['fn' => 'company', 'ln' => 'company'] ? true : json_encode($found);
}, $failures, $passes);

pf_forms_case('A field whose whole label is a name word wins over an earlier one that contains it', function () {
    $found = pixelflow_form_detect([pf_field('company', 'text', 'Company name'), pf_field('who', 'text', 'Name')]);

    return ($found['map']['fn'] ?? null) === 'who' && ($found['map']['ln'] ?? null) === 'who' && $found['unconfirmed'] === []
        ? true
        : json_encode($found);
}, $failures, $passes);

pf_forms_case('Statement is an unconfirmed state, and a State field after it is mapped', function () {
    $alone = pixelflow_form_detect([pf_field('statement', 'text', 'Statement of interest')]);
    $both  = pixelflow_form_detect([pf_field('statement', 'text', 'Statement of interest'), pf_field('region', 'text', 'State')]);

    if (isset($alone['map']['st']) || ($alone['unconfirmed']['st'] ?? null) !== 'statement') {
        return 'alone ' . json_encode($alone);
    }

    return ($both['map']['st'] ?? null) === 'region' && $both['unconfirmed'] === [] ? true : 'both ' . json_encode($both);
}, $failures, $passes);

pf_forms_case('An unconfirmed name is not sent, and is sent once a person chooses it', function () {
    $submission = pf_contact_submission(['fields' => [
        ['key' => 'company', 'type' => 'text', 'label' => 'Company name', 'value' => 'Acme Widgets Ltd'],
        ['key' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => 'buyer@example.test'],
    ]]);
    PixelFlow_Form_Dispatcher::dispatch($submission);
    $before = pf_only_event();

    pf_forms_reset();
    $choice = ['key' => 'company', 'type' => 'text', 'label' => 'Company name'];
    update_option(PIXELFLOW_FORM_SETTINGS_OPTION, pixelflow_apply_form_settings_patch([], [
        'custom:7' => ['fields' => ['fn' => $choice, 'ln' => $choice]],
    ]));
    PixelFlow_Form_Dispatcher::dispatch($submission);
    $after = pf_only_event();

    if ($before === null || isset($before['customerData']['fn']) || isset($before['customerData']['ln'])) {
        return 'before ' . json_encode($before['customerData'] ?? null);
    }

    return ($after['customerData']['fn'] ?? null) === hash('sha256', 'acme') ? true : 'after ' . json_encode($after['customerData'] ?? null);
}, $failures, $passes);

$languages = [
    'French'  => [pf_field('f1', 'text', 'Téléphone'), 'ph'],
    'German'  => [pf_field('f1', 'text', 'Telefonnummer'), 'ph'],
    'Spanish' => [pf_field('f1', 'text', 'Correo electrónico'), 'em'],
    'Italian' => [pf_field('f1', 'text', 'Cognome'), 'ln'],
    'Dutch'   => [pf_field('f1', 'text', 'Voornaam'), 'fn'],
];
foreach ($languages as $language => [$field, $identifier]) {
    pf_forms_case("A {$language} text field label is detected", function () use ($field, $identifier) {
        $map = pixelflow_form_detect_map([$field]);

        return ($map[$identifier] ?? null) === 'f1' ? true : json_encode($map);
    }, $failures, $passes);
}

pf_forms_case('A word inside another word does not match (hotel is not a phone)', function () {
    $map = pixelflow_form_detect_map([pf_field('hotel', 'text', 'Hotel')]);

    return $map === [] ? true : json_encode($map);
}, $failures, $passes);

pf_forms_case('A form with only a plain text identifier is medium confidence', function () {
    $c = pixelflow_form_classify('Callback', [pf_field('phone-number', 'text', 'Phone number')]);

    return $c['confidence'] === 'medium' ? true : $c['confidence'];
}, $failures, $passes);

pf_forms_finish($failures, $passes);
