<?php
/**
 * Adapters: the Elementor `_elementor_data` walk that lists forms before any submission, and the
 * Ninja Forms submission mapping.
 *
 * Run: php tests/test-form-adapters.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-forms.php';

if ( ! defined('ELEMENTOR_PRO_VERSION')) {
    define('ELEMENTOR_PRO_VERSION', 'test');
}


/**
 * The part of GFAPI the adapter calls, over forms held in $GLOBALS['__pf_gravity_forms'].
 * Mirrors GFFormsModel::get_form_ids(): a null argument does not filter that column.
 */
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

/** A Gravity form with a two-part name, email, phone, address and message. */
function pf_gravity_form(int $id, int $active = 1, int $trash = 0): array
{
    return [
        'id'        => $id,
        'title'     => "Gravity {$id}",
        'is_active' => $active,
        'is_trash'  => $trash,
        'fields'    => [
            (object) ['id' => 1, 'type' => 'name', 'label' => 'Name', 'nameFormat' => 'advanced'],
            (object) ['id' => 2, 'type' => 'email', 'label' => 'Email'],
            (object) ['id' => 3, 'type' => 'phone', 'label' => 'Phone'],
            (object) ['id' => 4, 'type' => 'address', 'label' => 'Address'],
            (object) ['id' => 5, 'type' => 'textarea', 'label' => 'Message'],
            (object) ['id' => 6, 'type' => 'consent', 'label' => 'Consent'],
        ],
    ];
}

$failures = [];
$passes   = 0;

/**
 * A post with an Elementor layout.
 *
 * @param int         $id     Post id
 * @param string      $type   Post type
 * @param string      $status Post status
 * @param string|null $layout JSON layout, or null for none
 * @return void
 */
function pf_elementor_post(int $id, string $type, string $status, ?string $layout, string $template_type = ''): void
{
    $GLOBALS['__pf_posts'][$id] = (object) ['ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => "Post {$id}"];
    if ($layout !== null) {
        $GLOBALS['__pf_post_meta'][$id]['_elementor_data'] = $layout;
    }
    if ($template_type !== '') {
        $GLOBALS['__pf_post_meta'][$id]['_elementor_template_type'] = $template_type;
    }
}

/**
 * A layout with one form widget nested in a section and a column.
 *
 * @param string $widget_id Widget id
 * @param array  $extra     Extra sibling nodes
 * @return string
 */
function pf_form_layout(string $widget_id, array $extra = []): string
{
    $form = [
        'id'         => $widget_id,
        'elType'     => 'widget',
        'widgetType' => 'form',
        'settings'   => [
            'form_name'   => 'Quote request',
            'form_fields' => [
                ['_id' => 'a1', 'custom_id' => 'name', 'field_label' => 'Name'],
                ['_id' => 'a2', 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Email'],
                ['_id' => 'a3', 'custom_id' => 'message', 'field_type' => 'textarea', 'field_label' => 'Message'],
            ],
        ],
    ];

    return (string) json_encode([
        [
            'id'       => 'sec1',
            'elType'   => 'section',
            'elements' => array_merge([['id' => 'col1', 'elType' => 'column', 'elements' => [$form]]], $extra),
        ],
    ]);
}

/** Form ids the Elementor adapter lists. */
function pf_elementor_ids(): array
{
    return array_map(static function ($form) {
        return $form['form_id'];
    }, (new PixelFlow_Form_Adapter_Elementor())->list_forms());
}

pf_forms_case('The walk finds a form in a page, a template and a popup, with custom_id, label and type', function () {
    pf_elementor_post(10, 'page', 'publish', pf_form_layout('w10'));
    pf_elementor_post(11, 'elementor_library', 'publish', pf_form_layout('w11'), 'section');
    pf_elementor_post(12, 'elementor_library', 'publish', pf_form_layout('w12'), 'popup');
    $forms = (new PixelFlow_Form_Adapter_Elementor())->list_forms();

    $ids = array_column($forms, 'form_id');
    if ($ids !== ['10:w10', '11:w11', '12:w12']) {
        return 'ids ' . json_encode($ids);
    }
    $expected = [
        ['key' => 'name', 'type' => 'text', 'label' => 'Name'],
        ['key' => 'email', 'type' => 'email', 'label' => 'Email'],
        ['key' => 'message', 'type' => 'textarea', 'label' => 'Message'],
    ];

    return $forms[0]['fields'] === $expected && $forms[0]['title'] === 'Quote request' ? true : json_encode($forms[0]);
}, $failures, $passes);

pf_forms_case('An unknown node type does not hide the recognised forms', function () {
    pf_elementor_post(10, 'page', 'publish', pf_form_layout('w10', [['id' => 'x', 'elType' => 'e-flexbox-v9', 'weird' => true]]));

    return pf_elementor_ids() === ['10:w10'] ? true : json_encode(pf_elementor_ids());
}, $failures, $passes);

pf_forms_case('Malformed JSON yields an empty list for that post, not an error', function () {
    pf_elementor_post(9, 'page', 'publish', '{"broken": [');
    pf_elementor_post(10, 'page', 'publish', pf_form_layout('w10'));

    return pf_elementor_ids() === ['10:w10'] ? true : json_encode(pf_elementor_ids());
}, $failures, $passes);

pf_forms_case('Draft, pending, scheduled and private posts are listed', function () {
    pf_elementor_post(20, 'page', 'draft', pf_form_layout('d'));
    pf_elementor_post(21, 'page', 'pending', pf_form_layout('p'));
    pf_elementor_post(22, 'page', 'future', pf_form_layout('f'));
    pf_elementor_post(23, 'post', 'private', pf_form_layout('v'));

    return pf_elementor_ids() === ['20:d', '21:p', '22:f', '23:v'] ? true : json_encode(pf_elementor_ids());
}, $failures, $passes);

pf_forms_case('Trash, auto-draft and revisions are not listed', function () {
    pf_elementor_post(30, 'page', 'trash', pf_form_layout('t'));
    pf_elementor_post(31, 'page', 'auto-draft', pf_form_layout('a'));
    pf_elementor_post(32, 'revision', 'inherit', pf_form_layout('r'));

    return pf_elementor_ids() === [] ? true : json_encode(pf_elementor_ids());
}, $failures, $passes);

pf_forms_case('An unused elementor_library template form is listed', function () {
    pf_elementor_post(40, 'elementor_library', 'draft', pf_form_layout('u'), 'section');

    return pf_elementor_ids() === ['40:u'] ? true : json_encode(pf_elementor_ids());
}, $failures, $passes);

pf_forms_case('A global widget form is listed under its template and its placement', function () {
    pf_elementor_post(50, 'elementor_library', 'publish', (string) json_encode([json_decode(pf_form_layout('tpl'), true)[0]['elements'][0]['elements'][0]]), 'widget');
    pf_elementor_post(51, 'page', 'publish', (string) json_encode([['id' => 'inst', 'elType' => 'widget', 'widgetType' => 'global', 'templateID' => 50]]));

    return pf_elementor_ids() === ['50:inst'] ? true : json_encode(pf_elementor_ids());
}, $failures, $passes);

/**
 * A stand-in for Elementor Pro's Form_Record.
 */
class PF_Test_Elementor_Record
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get($property)
    {
        return $this->data[$property] ?? null;
    }
}

pf_forms_case('A submission from a widget the walk did not return sends with its computed configuration and adds no row', function () {
    pf_elementor_post(60, 'page', 'publish', pf_form_layout('known'));
    (new PixelFlow_Form_Adapter_Elementor())->on_submit(new PF_Test_Elementor_Record([
        'form_settings' => ['id' => 'unseen', 'form_post_id' => 60, 'form_name' => 'Hidden form'],
        'fields'        => [
            'email' => ['id' => 'email', 'type' => 'email', 'title' => 'Email', 'value' => 'hidden@example.test'],
        ],
    ]));
    $event = pf_only_event();

    if ($event === null || $event['eventName'] !== 'CompleteRegistration') {
        return 'event ' . json_encode($event['eventName'] ?? null);
    }
    $keys = array_column(pixelflow_forms_listing()['forms'], 'key');

    return $keys === ['elementor:60:known'] && get_option(PIXELFLOW_FORM_SETTINGS_OPTION, []) === []
        ? true
        : 'listing ' . json_encode($keys);
}, $failures, $passes);

pf_forms_case('Ninja Forms maps its submitted data to the normalised shape', function () {
    $captured = null;
    $GLOBALS['__pf_test_filter_callbacks']['pixelflow_should_send_form_event'] = static function ($send, $submission) use (&$captured) {
        $captured = $submission;

        return $send;
    };
    (new PixelFlow_Form_Adapter_NinjaForms())->on_submit([
        'form_id'  => 3,
        'settings' => ['title' => 'Contact Me'],
        'fields'   => [
            7 => ['key' => 'message', 'type' => 'textarea', 'label' => 'Message', 'value' => 'hi', 'order' => 3],
            5 => ['key' => 'name', 'type' => 'textbox', 'label' => 'Name', 'value' => 'Ada Lovelace', 'order' => 1],
            6 => ['key' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => 'ninja@example.test', 'order' => 2],
            8 => ['key' => 'submit', 'type' => 'submit', 'label' => 'Submit', 'value' => '', 'order' => 4],
        ],
    ]);

    if ($captured === null) {
        return 'the submission never reached the dispatcher';
    }
    if ($captured['source'] !== 'ninja' || $captured['form_id'] !== '3' || $captured['form_title'] !== 'Contact Me') {
        return json_encode($captured);
    }
    $shape = array_map(static function ($f) {
        return $f['key'] . '/' . $f['type'];
    }, $captured['fields']);

    return $shape === ['name/text', 'email/email', 'message/textarea', 'submit/submit'] && count(pf_sent_events()) === 1
        ? true
        : json_encode($shape);
}, $failures, $passes);

pf_forms_case('Ninja Forms skips a submission that carries errors (spam)', function () {
    (new PixelFlow_Form_Adapter_NinjaForms())->on_submit([
        'form_id'  => 3,
        'settings' => ['title' => 'Contact Me'],
        'errors'   => ['fields' => [9 => 'Spam answer is wrong']],
        'fields'   => [6 => ['key' => 'email', 'type' => 'email', 'value' => 'ninja@example.test']],
    ]);

    return $GLOBALS['__pf_http'] === [] ? true : 'a request was made';
}, $failures, $passes);

pf_forms_case('Gravity lists active and inactive forms, not trashed ones, with name and address parts', function () {
    $GLOBALS['__pf_gravity_forms'] = [pf_gravity_form(1), pf_gravity_form(2, 0), pf_gravity_form(3, 1, 1)];
    $forms = (new PixelFlow_Form_Adapter_GravityForms())->list_forms();
    $GLOBALS['__pf_gravity_forms'] = [];

    if (array_column($forms, 'form_id') !== ['1', '2']) {
        return 'ids ' . json_encode(array_column($forms, 'form_id'));
    }
    $shape = array_map(static function ($f) {
        return $f['key'] . '/' . $f['type'];
    }, $forms[0]['fields']);
    $expected = ['1.3/first_name', '1.6/last_name', '2/email', '3/phone', '4.3/city', '4.4/state', '4.5/zip', '4.6/country', '5/textarea', '6/consent'];

    return $shape === $expected ? true : json_encode($shape);
}, $failures, $passes);

pf_forms_case('Gravity maps a submitted entry to the normalised shape and sends hashed parts', function () {
    $GLOBALS['__pf_gravity_forms'] = [pf_gravity_form(1)];
    (new PixelFlow_Form_Adapter_GravityForms())->on_submit(
        [
            'id' => 99, 'form_id' => 1, 'status' => 'active',
            '1.3' => 'Ada', '1.6' => 'Lovelace', '2' => 'g@example.test', '3' => '555 0100',
            '4.1' => '1 Main St', '4.3' => 'Springfield', '4.4' => 'OR', '4.5' => '97477', '4.6' => 'US',
            '5' => 'SECRET-MESSAGE', '6.1' => '1',
        ],
        pf_gravity_form(1)
    );
    $GLOBALS['__pf_gravity_forms'] = [];
    $event = pf_only_event();

    if ($event === null) {
        return 'no event';
    }
    if (strpos((string) json_encode($GLOBALS['__pf_http']), 'SECRET-MESSAGE') !== false) {
        return 'message text reached the payload';
    }
    $expected = [
        'em' => hash('sha256', 'g@example.test'),
        'fn' => hash('sha256', 'ada'),
        'ln' => hash('sha256', 'lovelace'),
        'ct' => hash('sha256', 'springfield'),
        'zp' => hash('sha256', '97477'),
    ];
    foreach ($expected as $key => $hash) {
        if (($event['customerData'][$key] ?? null) !== $hash) {
            return "{$key} was " . json_encode($event['customerData'][$key] ?? null);
        }
    }

    return ($event['additionalData']['contentName'] ?? '') === 'Gravity 1' ? true : 'wrong title';
}, $failures, $passes);

pf_forms_case('Gravity skips an entry marked as spam', function () {
    (new PixelFlow_Form_Adapter_GravityForms())->on_submit(
        ['id' => 100, 'form_id' => 1, 'status' => 'spam', '2' => 'g@example.test'],
        pf_gravity_form(1)
    );

    return $GLOBALS['__pf_http'] === [] ? true : 'a request was made';
}, $failures, $passes);

pf_forms_finish($failures, $passes);
