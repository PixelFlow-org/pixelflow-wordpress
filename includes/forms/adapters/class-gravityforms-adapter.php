<?php
/**
 * Gravity Forms adapter.
 *
 * Confirmed against Gravity Forms 2.7.17:
 * - Forms live in Gravity's own table with `is_active` and `is_trash` columns.
 *   `GFAPI::get_forms(null, false)` returns every form not in the trash whatever its active
 *   state (GFFormsModel::get_form_ids() filters a column only when its argument is not null),
 *   and `GFAPI::get_form()` returns a trashed form with `is_trash` set.
 * - Fields are keyed by field id; a name field's parts are inputs `<id>.3` (first) and
 *   `<id>.6` (last) unless its format is `simple`, and an address field's are `<id>.3` city,
 *   `<id>.4` state, `<id>.5` zip and `<id>.6` country. An email field with confirmation saves
 *   one value under its own id.
 * - `gform_after_submission` receives ($entry, $form) and fires for every stored submission,
 *   spam included: the spam check sets `$entry['status'] = 'spam'` on the same entry by
 *   reference before the hook runs, so the adapter skips on that status.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Gravity Forms adapter.
 */
class PixelFlow_Form_Adapter_GravityForms extends PixelFlow_Form_Adapter
{
    /** Gravity field types → canonical types, for types that are not split into parts. */
    private const TYPES = [
        'email'       => 'email',
        'phone'       => 'phone',
        'text'        => 'text',
        'textarea'    => 'textarea',
        'number'      => 'number',
        'checkbox'    => 'choice',
        'radio'       => 'choice',
        'select'      => 'choice',
        'multiselect' => 'choice',
        'date'        => 'date',
        'time'        => 'date',
        'fileupload'  => 'file',
        'consent'     => 'consent',
        'hidden'      => 'hidden',
        'captcha'     => 'captcha',
        'html'        => 'layout',
        'section'     => 'layout',
        'page'        => 'layout',
        'submit'      => 'submit',
    ];

    /** Name parts → canonical types. */
    private const NAME_PARTS = [
        '3' => 'first_name',
        '6' => 'last_name',
    ];

    /** Address parts → canonical types. */
    private const ADDRESS_PARTS = [
        '3' => 'city',
        '4' => 'state',
        '5' => 'zip',
        '6' => 'country',
    ];

    public function id(): string
    {
        return 'gravity';
    }

    public function label(): string
    {
        return 'Gravity Forms';
    }

    public function is_active(): bool
    {
        return class_exists('GFAPI');
    }

    public function register_hooks(): void
    {
        add_action('gform_after_submission', [$this, 'on_submit'], 10, 2);
    }

    public function list_forms(): array
    {
        $forms = [];
        foreach (GFAPI::get_forms(null, false) as $form) {
            if ( ! is_array($form) || empty($form['id'])) {
                continue;
            }
            $forms[] = [
                'form_id' => (string) $form['id'],
                'title'   => isset($form['title']) ? (string) $form['title'] : '',
                'fields'  => $this->fields_of($form),
            ];
        }

        return $forms;
    }

    public function form_exists(string $form_id): bool
    {
        $form = GFAPI::get_form((int) $form_id);

        return is_array($form) && empty($form['is_trash']);
    }

    /**
     * @param array $entry Entry, keyed by field and input id
     * @param array $form  Form object
     * @return void
     */
    public function on_submit($entry, $form): void
    {
        if ( ! is_array($entry) || ! is_array($form) || empty($form['id'])) {
            return;
        }
        if ((isset($entry['status']) ? (string) $entry['status'] : '') === 'spam') {
            return;
        }

        $values = [];
        foreach ($entry as $key => $value) {
            if (is_scalar($value)) {
                $values[(string) $key] = $value;
            }
        }

        $this->submit(
            (string) $form['id'],
            isset($form['title']) ? (string) $form['title'] : '',
            $this->with_values($this->fields_of($form), $values),
            'gform_after_submission'
        );
    }

    /**
     * Canonical fields of a form object, in form order.
     *
     * @param array $form Form object
     * @return array
     */
    private function fields_of(array $form): array
    {
        $out    = [];
        $fields = isset($form['fields']) && is_array($form['fields']) ? $form['fields'] : [];
        foreach ($fields as $field) {
            $id    = (string) $this->prop($field, 'id');
            $type  = (string) $this->prop($field, 'type');
            $label = (string) $this->prop($field, 'label');
            if ($id === '' || $type === '') {
                continue;
            }

            if ($type === 'name' && $this->prop($field, 'nameFormat') !== 'simple') {
                foreach (self::NAME_PARTS as $part => $canonical) {
                    $out[] = ['key' => $id . '.' . $part, 'type' => $canonical, 'label' => trim($label . ' (' . $canonical . ')')];
                }
                continue;
            }
            if ($type === 'name') {
                $out[] = ['key' => $id, 'type' => 'name', 'label' => $label];
                continue;
            }

            if ($type === 'address') {
                foreach (self::ADDRESS_PARTS as $part => $canonical) {
                    $out[] = ['key' => $id . '.' . $part, 'type' => $canonical, 'label' => trim($label . ' (' . $canonical . ')')];
                }
                continue;
            }

            $out[] = ['key' => $id, 'type' => self::TYPES[$type] ?? 'other', 'label' => $label];
        }

        return $out;
    }

    /**
     * Reads a property from a GF_Field object or a field array.
     *
     * @param mixed  $field GF_Field or array
     * @param string $name  Property
     * @return mixed
     */
    private function prop($field, string $name)
    {
        if (is_array($field)) {
            return $field[$name] ?? '';
        }

        return is_object($field) && isset($field->$name) ? $field->$name : '';
    }
}
