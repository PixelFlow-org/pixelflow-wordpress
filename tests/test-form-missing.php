<?php
/**
 * A configured form that no longer exists: it keeps its configuration, cannot be switched on and
 * sends nothing — and one that is merely unpublished or deactivated is not treated as missing.
 *
 * Run: php tests/test-form-missing.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-forms.php';

if ( ! defined('ELEMENTOR_PRO_VERSION')) {
    define('ELEMENTOR_PRO_VERSION', 'test');
}
if ( ! defined('FLUENTFORM')) {
    define('FLUENTFORM', true);
}

/** Makes the WPForms adapter active; its forms come from the post store. */
function wpforms()
{
    return null;
}

/**
 * The part of $wpdb the Fluent Forms adapter reads, backed by an in-memory forms table, on top of
 * the options rows the repeat window writes.
 */
class PF_Test_Wpdb extends PF_Test_Options_Wpdb
{
    public array $forms = [];

    public function get_results($query)
    {
        return array_map(static function ($row) {
            return (object) $row;
        }, array_values($this->forms));
    }

    public function get_var($prepared)
    {
        $id = (int) ($prepared['args'][0] ?? 0);
        if (strpos($prepared['query'], 'fluentform_forms') !== false && isset($this->forms[$id])) {
            return strpos($prepared['query'], 'SELECT id') === 0 ? $id : $this->forms[$id]['form_fields'];
        }

        return null;
    }
}

$GLOBALS['wpdb'] = new PF_Test_Wpdb();

/** GFAPI over $GLOBALS['__pf_gravity_forms'], as in test-form-adapters.php. */
class GFAPI
{
    public static function get_forms($active = true, $trash = false)
    {
        return array_values(array_filter($GLOBALS['__pf_gravity_forms'] ?? [], static function ($form) use ($active, $trash) {
            return ($active === null || (int) $form['is_active'] === (int) $active)
                && ($trash === null || (int) $form['is_trash'] === (int) $trash);
        }));
    }

    public static function get_form($form_id)
    {
        foreach ($GLOBALS['__pf_gravity_forms'] ?? [] as $form) {
            if ((int) $form['id'] === (int) $form_id) {
                return $form;
            }
        }

        return false;
    }
}

// Stand-ins for the admin-ajax plumbing, so the save route can be driven directly.
function check_ajax_referer($action = -1, $query_arg = false, $die = true)
{
    return 1;
}
function current_user_can($capability, ...$args)
{
    return true;
}
function wp_send_json_success($data = null, $status_code = null, $options = 0)
{
    $GLOBALS['__pf_json'] = ['success' => true, 'data' => $data];
}
function wp_send_json_error($data = null, $status_code = null, $options = 0)
{
    $GLOBALS['__pf_json'] = ['success' => false, 'data' => $data];
}

$failures = [];
$passes   = 0;

/** @param int $id @param string $status @param string $layout */
function pf_page(int $id, string $status, string $layout, string $type = 'page', string $template_type = ''): void
{
    $GLOBALS['__pf_posts'][$id]                        = (object) ['ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => "Page {$id}"];
    $GLOBALS['__pf_post_meta'][$id]['_elementor_data'] = $layout;
    if ($template_type !== '') {
        $GLOBALS['__pf_post_meta'][$id]['_elementor_template_type'] = $template_type;
    }
}

/** A layout holding one form widget, or none. */
function pf_layout(?string $widget_id): string
{
    $elements = $widget_id === null ? [] : [[
        'id'         => $widget_id,
        'elType'     => 'widget',
        'widgetType' => 'form',
        'settings'   => [
            'form_name'   => 'Quote',
            'form_fields' => [['_id' => 'a', 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Email']],
        ],
    ]];

    return (string) json_encode([['id' => 's', 'elType' => 'section', 'elements' => $elements]]);
}

/** Listing rows by key. */
function pf_rows(): array
{
    $rows = [];
    foreach (pixelflow_forms_listing()['forms'] as $row) {
        $rows[$row['key']] = $row;
    }

    return $rows;
}

/** Stores a configured, enabled record for a key. */
function pf_configure(string $key): void
{
    update_option(PIXELFLOW_FORM_SETTINGS_OPTION, pixelflow_apply_form_settings_patch(pixelflow_get_form_records(), [
        $key => [
            'title'   => 'Quote',
            'enabled' => 1,
            'event'   => 'Schedule',
            'fields'  => ['em' => ['key' => 'email', 'type' => 'email', 'label' => 'Email']],
        ],
    ]));
}

/** An Elementor submission for a form id. */
function pf_elementor_submit(string $form_id): string
{
    return PixelFlow_Form_Dispatcher::dispatch([
        'source'     => 'elementor',
        'form_id'    => $form_id,
        'form_title' => 'Quote',
        'fields'     => [['key' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => 'q@example.test']],
    ]);
}

/** Drives the save route with a patch; returns the listing it answered with. */
function pf_save(array $patch): array
{
    $_POST = ['forms' => (string) json_encode($patch)];
    pixelflow_forms_ajax_save();

    return $GLOBALS['__pf_json']['data'] ?? [];
}

pf_forms_case('A registry form deleted in its plugin is flagged missing and keeps its record', function () {
    pf_configure('wpforms:70');
    $row = pf_rows()['wpforms:70'] ?? null;

    if ($row === null || $row['missing'] !== true) {
        return 'row ' . json_encode($row);
    }

    return (array) $row['record'] !== [] && ((array) $row['record'])['event'] === 'Schedule' ? true : 'record lost';
}, $failures, $passes);

pf_forms_case('A registry form moved to its plugin trash is flagged missing', function () {
    $GLOBALS['__pf_posts'][70] = (object) ['ID' => 70, 'post_type' => 'wpforms', 'post_status' => 'trash', 'post_title' => 'Quote', 'post_content' => '{"fields":[]}'];
    pf_configure('wpforms:70');

    return (pf_rows()['wpforms:70']['missing'] ?? null) === true ? true : 'not missing';
}, $failures, $passes);

pf_forms_case('A registry form that is only deactivated in its plugin is not missing', function () {
    $GLOBALS['wpdb']->forms = [5 => ['id' => 5, 'title' => 'Quote', 'status' => 'unpublished', 'form_fields' => '{"fields":[]}']];
    pf_configure('fluent:5');
    $row = pf_rows()['fluent:5'] ?? null;
    $GLOBALS['wpdb']->forms = [];

    return $row !== null && $row['missing'] === false ? true : 'row ' . json_encode($row);
}, $failures, $passes);

pf_forms_case('A form whose page was deleted sends nothing, refuses to be enabled and keeps its map', function () {
    pf_configure('elementor:80:w');
    $outcome = pf_elementor_submit('80:w');
    pf_save(['elementor:80:w' => ['enabled' => 0]]);
    $listing = pf_save(['elementor:80:w' => ['enabled' => 1]]);
    $record  = pixelflow_get_form_record('elementor:80:w');

    if ($outcome !== 'skipped' || $GLOBALS['__pf_http'] !== []) {
        return "outcome {$outcome}";
    }
    if (($record['enabled'] ?? null) !== 0) {
        return 'the switch was turned on: ' . json_encode($record);
    }

    return ($record['fields']['em']['key'] ?? null) === 'email' && $listing !== [] ? true : 'map lost';
}, $failures, $passes);

pf_forms_case('A form whose widget was removed from an existing page is missing and sends nothing', function () {
    pf_page(81, 'publish', pf_layout(null));
    pf_configure('elementor:81:w');

    return (pf_rows()['elementor:81:w']['missing'] ?? null) === true && pf_elementor_submit('81:w') === 'skipped'
        ? true
        : 'not treated as missing';
}, $failures, $passes);

pf_forms_case('A form whose page was merely unpublished is not missing and keeps its switch', function () {
    pf_page(82, 'draft', pf_layout('w'));
    pf_configure('elementor:82:w');
    $row = pf_rows()['elementor:82:w'] ?? null;

    if ($row === null || $row['missing'] !== false || ((array) $row['record'])['enabled'] !== 1) {
        return 'row ' . json_encode($row);
    }

    return pf_elementor_submit('82:w') === 'sent' ? true : 'the draft page form did not send';
}, $failures, $passes);

pf_forms_case('A form whose page was moved to the trash is missing', function () {
    pf_page(83, 'trash', pf_layout('w'));
    pf_configure('elementor:83:w');

    return (pf_rows()['elementor:83:w']['missing'] ?? null) === true ? true : 'not missing';
}, $failures, $passes);

pf_forms_case('A template form is listed while a draft and missing once the template is trashed', function () {
    pf_page(84, 'draft', pf_layout('w'), 'elementor_library', 'section');
    pf_configure('elementor:84:w');
    $draft = pf_rows()['elementor:84:w']['missing'] ?? null;
    $GLOBALS['__pf_posts'][84]->post_status = 'trash';
    $trashed = pf_rows()['elementor:84:w']['missing'] ?? null;

    return $draft === false && $trashed === true ? true : json_encode([$draft, $trashed]);
}, $failures, $passes);

pf_forms_case('Forms on an unpublished and on a private page are offered for configuration', function () {
    pf_page(85, 'pending', pf_layout('a'));
    pf_page(86, 'private', pf_layout('b'));
    $rows = pf_rows();

    return isset($rows['elementor:85:a'], $rows['elementor:86:b']) ? true : json_encode(array_keys($rows));
}, $failures, $passes);

pf_forms_case('When the same form identity appears again, its stored configuration applies', function () {
    pf_configure('elementor:87:w');
    $gone = pf_elementor_submit('87:w');
    pf_page(87, 'publish', pf_layout('w'));
    PixelFlow_Form_Dispatcher::reset_request_state();
    $back  = pf_elementor_submit('87:w');
    $event = pf_only_event();

    return $gone === 'skipped' && $back === 'sent' && ($event['eventName'] ?? '') === 'Schedule'
        ? true
        : "gone {$gone}, back {$back}";
}, $failures, $passes);

pf_forms_case('A Gravity form moved to the trash is missing; an inactive one is not', function () {
    $form = static function (int $id, int $active, int $trash) {
        return ['id' => $id, 'title' => "G{$id}", 'is_active' => $active, 'is_trash' => $trash, 'fields' => []];
    };
    $GLOBALS['__pf_gravity_forms'] = [$form(5, 1, 1), $form(6, 0, 0)];
    pf_configure('gravity:5');
    pf_configure('gravity:6');
    $rows = pf_rows();
    $GLOBALS['__pf_gravity_forms'] = [];

    return ($rows['gravity:5']['missing'] ?? null) === true && ($rows['gravity:6']['missing'] ?? null) === false
        ? true
        : json_encode([$rows['gravity:5']['missing'] ?? null, $rows['gravity:6']['missing'] ?? null]);
}, $failures, $passes);

pf_forms_finish($failures, $passes);
