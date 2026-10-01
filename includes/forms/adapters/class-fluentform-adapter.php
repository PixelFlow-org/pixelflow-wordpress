<?php
/**
 * Fluent Forms adapter.
 *
 * Forms live in Fluent's own `fluentform_forms` table and are deleted for good, not trashed;
 * `published` and `unpublished` are both listed. Fields are keyed by their `name` attribute,
 * which is also how the submitted data is keyed, and the parts of a name or address field by
 * `<name>.<part>`.
 *
 * `fluentform/submission_inserted` runs after the entry is stored. A submission Fluent's spam
 * checks reject outright never reaches it, but one they only flag is stored with status
 * `spam` and still runs the hook, so the adapter reads the entry's status and skips it.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Fluent Forms adapter.
 */
class PixelFlow_Form_Adapter_FluentForm extends PixelFlow_Form_Adapter
{
    /** Fluent elements → canonical types, for elements that are not split into parts. */
    private const TYPES = [
        'input_email'          => 'email',
        'phone'                => 'phone',
        'input_text'           => 'text',
        'textarea'             => 'textarea',
        'input_number'         => 'number',
        'rangeslider'          => 'number',
        'select'               => 'choice',
        'multi_select'         => 'choice',
        'input_radio'          => 'choice',
        'input_checkbox'       => 'choice',
        'ratings'              => 'choice',
        'input_date'           => 'date',
        'input_file'           => 'file',
        'input_image'          => 'file',
        'select_country'       => 'country',
        'terms_and_condition'  => 'consent',
        'gdpr_agreement'       => 'consent',
        'input_hidden'         => 'hidden',
        'recaptcha'            => 'captcha',
        'hcaptcha'             => 'captcha',
        'turnstile'            => 'captcha',
        'custom_html'          => 'layout',
        'section_break'        => 'layout',
        'shortcode'            => 'layout',
        'form_step'            => 'layout',
        'custom_submit_button' => 'submit',
    ];

    /** Parts of a name or address field → canonical types. */
    private const PART_TYPES = [
        'first_name'     => 'first_name',
        'last_name'      => 'last_name',
        'middle_name'    => 'text',
        'address_line_1' => 'other',
        'address_line_2' => 'other',
        'city'           => 'city',
        'state'          => 'state',
        'zip'            => 'zip',
        'country'        => 'country',
    ];

    public function id(): string
    {
        return 'fluent';
    }

    public function label(): string
    {
        return 'Fluent Forms';
    }

    public function is_active(): bool
    {
        return defined('FLUENTFORM');
    }

    public function register_hooks(): void
    {
        add_action('fluentform/submission_inserted', [$this, 'on_submit'], 20, 3);
    }

    public function list_forms(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results("SELECT id, title, form_fields FROM {$wpdb->prefix}fluentform_forms ORDER BY id ASC"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent's own table, admin-only listing
        if ( ! is_array($rows)) {
            return [];
        }

        $forms = [];
        foreach ($rows as $row) {
            $forms[] = [
                'form_id' => (string) $row->id,
                'title'   => (string) $row->title,
                'fields'  => $this->fields_of((string) $row->form_fields),
            ];
        }

        return $forms;
    }

    public function form_exists(string $form_id): bool
    {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", (int) $form_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent's own table

        return $found !== null;
    }

    /**
     * @param int    $insert_id Stored entry id
     * @param array  $form_data Submitted data keyed by field name
     * @param object $form      Form row
     * @return void
     */
    public function on_submit($insert_id, $form_data, $form): void
    {
        global $wpdb;

        if ( ! is_array($form_data) || ! is_object($form) || empty($form->id)) {
            return;
        }

        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", (int) $insert_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent's own table
        if ($status === 'spam') {
            return;
        }

        $values = [];
        foreach ($form_data as $name => $value) {
            if (is_array($value) && array_keys($value) !== range(0, count($value) - 1)) {
                foreach ($value as $part => $part_value) {
                    $values[$name . '.' . $part] = $part_value;
                }
                continue;
            }
            $values[(string) $name] = $value;
        }

        $definition = isset($form->form_fields) ? (string) $form->form_fields : '';
        if ($definition === '') {
            $definition = (string) $wpdb->get_var($wpdb->prepare("SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", (int) $form->id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent's own table
        }

        $this->submit(
            (string) $form->id,
            isset($form->title) ? (string) $form->title : '',
            $this->with_values($this->fields_of($definition), $values),
            'fluentform/submission_inserted'
        );
    }

    /**
     * @param string $json `form_fields` column
     * @return array
     */
    private function fields_of(string $json): array
    {
        $data = json_decode($json, true);
        $out  = [];
        if (is_array($data) && isset($data['fields']) && is_array($data['fields'])) {
            $this->collect($data['fields'], $out);
        }

        return $out;
    }

    /**
     * @param array $elements Fluent elements
     * @param array $out      Collected fields
     * @return void
     */
    private function collect(array $elements, array &$out): void
    {
        foreach ($elements as $element) {
            if ( ! is_array($element)) {
                continue;
            }
            $kind = isset($element['element']) ? (string) $element['element'] : '';

            if ($kind === 'container' && isset($element['columns']) && is_array($element['columns'])) {
                foreach ($element['columns'] as $column) {
                    if (is_array($column) && isset($column['fields']) && is_array($column['fields'])) {
                        $this->collect($column['fields'], $out);
                    }
                }
                continue;
            }

            $name  = isset($element['attributes']['name']) ? (string) $element['attributes']['name'] : '';
            $label = $this->label_of($element);

            if (($kind === 'input_name' || $kind === 'address') && isset($element['fields']) && is_array($element['fields'])) {
                foreach ($element['fields'] as $part => $sub) {
                    if ( ! is_array($sub) || (isset($sub['settings']['visible']) && $sub['settings']['visible'] === false)) {
                        continue;
                    }
                    $out[] = [
                        'key'   => $name . '.' . $part,
                        'type'  => self::PART_TYPES[$part] ?? 'other',
                        'label' => $this->label_of($sub) !== '' ? $this->label_of($sub) : $label,
                    ];
                }
                continue;
            }

            if ($kind === 'custom_submit_button') {
                $out[] = ['key' => 'submit', 'type' => 'submit', 'label' => $label];
                continue;
            }
            if ($name === '') {
                continue;
            }

            $out[] = ['key' => $name, 'type' => self::TYPES[$kind] ?? 'other', 'label' => $label];
        }
    }

    /**
     * @param array $element Fluent element
     * @return string
     */
    private function label_of(array $element): string
    {
        foreach (['label', 'admin_field_label'] as $key) {
            if (isset($element['settings'][$key]) && is_scalar($element['settings'][$key]) && (string) $element['settings'][$key] !== '') {
                return (string) $element['settings'][$key];
            }
        }

        return '';
    }
}
