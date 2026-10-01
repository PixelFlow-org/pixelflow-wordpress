<?php
/**
 * WPForms adapter.
 *
 * Forms are `wpforms` posts whose content is the JSON form definition; WPForms has a trash,
 * which `post_status => 'any'` already leaves out. Fields are keyed by field id, and a name
 * or address field's parts by `<id>.<part>`. `wpforms_process_complete` does not fire for an
 * entry WPForms marked as spam; the adapter also skips one that still carries a spam reason.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * WPForms adapter.
 */
class PixelFlow_Form_Adapter_WPForms extends PixelFlow_Form_Adapter
{
    /** WPForms field types → canonical types, for types that are not split into parts. */
    private const TYPES = [
        'email'                => 'email',
        'phone'                => 'phone',
        'text'                 => 'text',
        'textarea'             => 'textarea',
        'number'               => 'number',
        'number-slider'        => 'number',
        'checkbox'             => 'choice',
        'radio'                => 'choice',
        'select'               => 'choice',
        'payment-checkbox'     => 'choice',
        'payment-multiple'     => 'choice',
        'payment-select'       => 'choice',
        'date-time'            => 'date',
        'file-upload'          => 'file',
        'gdpr-checkbox'        => 'consent',
        'hidden'               => 'hidden',
        'captcha'              => 'captcha',
        'html'                 => 'layout',
        'divider'              => 'layout',
        'pagebreak'            => 'layout',
        'content'              => 'layout',
        'entry-preview'        => 'layout',
        'internal-information' => 'layout',
        'layout'               => 'layout',
    ];

    /** Address parts WPForms submits → canonical types. */
    private const ADDRESS_PARTS = [
        'city'    => 'city',
        'state'   => 'state',
        'postal'  => 'zip',
        'country' => 'country',
    ];

    public function id(): string
    {
        return 'wpforms';
    }

    public function label(): string
    {
        return 'WPForms';
    }

    public function is_active(): bool
    {
        return function_exists('wpforms');
    }

    public function register_hooks(): void
    {
        add_action('wpforms_process_complete', [$this, 'on_submit'], 10, 4);
    }

    public function list_forms(): array
    {
        $posts = get_posts([
            'post_type'      => 'wpforms',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $forms = [];
        foreach ($posts as $post) {
            $data    = $this->decode($post->post_content);
            $forms[] = [
                'form_id' => (string) $post->ID,
                'title'   => (string) $post->post_title,
                'fields'  => $this->fields_of($data),
            ];
        }

        return $forms;
    }

    public function form_exists(string $form_id): bool
    {
        $post = get_post((int) $form_id);

        return $post !== null
            && $post->post_type === 'wpforms'
            && ! in_array($post->post_status, ['trash', 'auto-draft'], true);
    }

    /**
     * @param array $fields    Processed fields, keyed by field id
     * @param array $entry     Raw $_POST entry
     * @param array $form_data Form definition
     * @param int   $entry_id  Entry id
     * @return void
     */
    public function on_submit($fields, $entry, $form_data, $entry_id = 0): void
    {
        if ( ! is_array($fields) || ! is_array($form_data) || ! empty($form_data['spam_reason'])) {
            return;
        }

        $values = [];
        foreach ($fields as $id => $field) {
            if ( ! is_array($field)) {
                continue;
            }
            $id            = (string) ($field['id'] ?? $id);
            $values[$id]   = $field['value'] ?? '';
            foreach (['first', 'last'] as $part) {
                if (isset($field[$part])) {
                    $values[$id . '.' . $part] = $field[$part];
                }
            }
            foreach (array_keys(self::ADDRESS_PARTS) as $part) {
                if (isset($field[$part])) {
                    $values[$id . '.' . $part] = $field[$part];
                }
            }
        }

        $title = isset($form_data['settings']['form_title']) ? (string) $form_data['settings']['form_title'] : '';
        $this->submit(
            (string) ($form_data['id'] ?? ''),
            $title,
            $this->with_values($this->fields_of($form_data), $values),
            'wpforms_process_complete'
        );
    }

    /**
     * Canonical fields of a form definition, in form order.
     *
     * @param array $data Decoded form definition
     * @return array
     */
    private function fields_of(array $data): array
    {
        $out = [];
        $defs = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [];
        foreach ($defs as $def) {
            if ( ! is_array($def) || ! isset($def['id'], $def['type'])) {
                continue;
            }
            $id    = (string) $def['id'];
            $type  = (string) $def['type'];
            $label = isset($def['label']) ? (string) $def['label'] : '';

            if ($type === 'name') {
                $format = isset($def['format']) ? (string) $def['format'] : 'first-last';
                if ($format === 'simple') {
                    $out[] = ['key' => $id, 'type' => 'name', 'label' => $label];
                } else {
                    $out[] = ['key' => $id . '.first', 'type' => 'first_name', 'label' => trim($label . ' (first)')];
                    $out[] = ['key' => $id . '.last', 'type' => 'last_name', 'label' => trim($label . ' (last)')];
                }
                continue;
            }

            if ($type === 'address') {
                foreach (self::ADDRESS_PARTS as $part => $canonical) {
                    $out[] = ['key' => $id . '.' . $part, 'type' => $canonical, 'label' => trim($label . ' (' . $part . ')')];
                }
                continue;
            }

            // An email field's confirmation input is part of the same field; only the primary
            // value is submitted under the field id, so it is listed once.
            $out[] = ['key' => $id, 'type' => self::TYPES[$type] ?? 'other', 'label' => $label];
        }

        return $out;
    }

    /**
     * @param string $content post_content of a `wpforms` post
     * @return array
     */
    private function decode(string $content): array
    {
        $data = function_exists('wpforms_decode') ? wpforms_decode($content) : json_decode($content, true);

        return is_array($data) ? $data : [];
    }
}
