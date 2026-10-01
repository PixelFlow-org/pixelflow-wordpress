<?php
/**
 * The settings page's two admin-ajax routes: both verify the settings nonce and refuse a user
 * who cannot manage options, and the read route returns each form with what detection found
 * and what it only offers.
 *
 * Run: php tests/test-form-routes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-forms.php';

if ( ! defined('ELEMENTOR_PRO_VERSION')) {
    define('ELEMENTOR_PRO_VERSION', 'test');
}

// Stand-ins for the admin-ajax plumbing, so the routes can be driven directly.
function check_ajax_referer($action = -1, $query_arg = false, $die = true)
{
    $GLOBALS['__pf_nonce_checks'][] = [$action, $query_arg];

    return 1;
}
function current_user_can($capability, ...$args)
{
    return in_array($capability, $GLOBALS['__pf_capabilities'], true);
}
function wp_send_json_success($data = null, $status_code = null, $options = 0)
{
    $GLOBALS['__pf_json'] = ['success' => true, 'data' => $data, 'status' => $status_code];
}
function wp_send_json_error($data = null, $status_code = null, $options = 0)
{
    $GLOBALS['__pf_json'] = ['success' => false, 'data' => $data, 'status' => $status_code];
}

$failures = [];
$passes   = 0;

/**
 * Puts one Elementor form on a published page and resets the route state.
 *
 * @param string[] $capabilities Capabilities of the requesting user
 * @return void
 */
function pf_routes_setup(array $capabilities): void
{
    $GLOBALS['__pf_capabilities'] = $capabilities;
    $GLOBALS['__pf_nonce_checks'] = [];
    $GLOBALS['__pf_json']         = null;
    $_POST                        = [];

    $GLOBALS['__pf_posts'][40]                        = (object) ['ID' => 40, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Quote'];
    $GLOBALS['__pf_post_meta'][40]['_elementor_data'] = (string) json_encode([[
        'id'         => 'w1',
        'elType'     => 'widget',
        'widgetType' => 'form',
        'settings'   => [
            'form_name'   => 'Quote',
            'form_fields' => [
                ['_id' => 'a', 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Email'],
                ['_id' => 'b', 'custom_id' => 'company', 'field_label' => 'Company name'],
            ],
        ],
    ]]);
}

/** The row of the form pf_routes_setup() placed, from the last answer. */
function pf_routes_row(): ?array
{
    foreach ($GLOBALS['__pf_json']['data']['forms'] ?? [] as $row) {
        if ($row['key'] === 'elementor:40:w1') {
            return $row;
        }
    }

    return null;
}

pf_forms_case('The read route verifies the settings nonce and answers an administrator with the list', function () {
    pf_routes_setup(['manage_options']);
    pixelflow_forms_ajax_get_forms();

    if ($GLOBALS['__pf_nonce_checks'] !== [['pixelflow_settings_nonce', 'nonce']]) {
        return 'nonce checks ' . json_encode($GLOBALS['__pf_nonce_checks']);
    }

    return $GLOBALS['__pf_json']['success'] === true && pf_routes_row() !== null ? true : json_encode($GLOBALS['__pf_json']);
}, $failures, $passes);

pf_forms_case('The read route refuses a user who cannot manage options and returns no form', function () {
    pf_routes_setup(['edit_posts']);
    pixelflow_forms_ajax_get_forms();
    $answer = $GLOBALS['__pf_json'];

    return $answer['success'] === false && $answer['status'] === 403 && ! isset($answer['data']['forms'])
        ? true
        : json_encode($answer);
}, $failures, $passes);

pf_forms_case('A read row carries the detected field and the one that only contains a name word', function () {
    pf_routes_setup(['manage_options']);
    pixelflow_forms_ajax_get_forms();
    $row = (array) json_decode((string) json_encode(pf_routes_row()), true);

    if (($row['detected'] ?? null) !== ['em' => 'email']) {
        return 'detected ' . json_encode($row['detected'] ?? null);
    }

    return ($row['unconfirmed'] ?? null) === ['fn' => 'company', 'ln' => 'company']
        ? true
        : 'unconfirmed ' . json_encode($row['unconfirmed'] ?? null);
}, $failures, $passes);

pf_forms_case('The save route verifies the settings nonce, stores the change and answers with the fresh list', function () {
    pf_routes_setup(['manage_options']);
    $_POST = ['forms' => (string) json_encode(['elementor:40:w1' => ['event' => 'Contact']])];
    pixelflow_forms_ajax_save();

    if ($GLOBALS['__pf_nonce_checks'] !== [['pixelflow_settings_nonce', 'nonce']]) {
        return 'nonce checks ' . json_encode($GLOBALS['__pf_nonce_checks']);
    }
    $row = pf_routes_row();

    return $row !== null && ((array) $row['record'])['event'] === 'Contact' && pixelflow_get_form_record('elementor:40:w1')['event'] === 'Contact'
        ? true
        : json_encode($GLOBALS['__pf_json']);
}, $failures, $passes);

pf_forms_case('The save route refuses a user who cannot manage options and stores nothing', function () {
    pf_routes_setup(['edit_posts']);
    $_POST = ['forms' => (string) json_encode(['elementor:40:w1' => ['event' => 'Contact']])];
    pixelflow_forms_ajax_save();
    $answer = $GLOBALS['__pf_json'];

    if ($answer['success'] !== false || $answer['status'] !== 403) {
        return json_encode($answer);
    }

    return pixelflow_get_form_records() === [] ? true : 'stored ' . json_encode(pixelflow_get_form_records());
}, $failures, $passes);

pf_forms_case('The save route refuses a payload that is not a JSON object and stores nothing', function () {
    pf_routes_setup(['manage_options']);
    $_POST = ['forms' => 'not json'];
    pixelflow_forms_ajax_save();
    $answer = $GLOBALS['__pf_json'];

    return $answer['success'] === false && $answer['status'] === 400 && pixelflow_get_form_records() === []
        ? true
        : json_encode($answer);
}, $failures, $passes);

pf_forms_finish($failures, $passes);
