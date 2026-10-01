<?php
/**
 * Elementor Pro adapter.
 *
 * Elementor has no form registry: a form is a widget inside a post's layout, stored as the
 * JSON tree in `_elementor_data`. Forms are listed by walking that tree across every post
 * type Elementor renders — `elementor_library` templates and popups included — in any status
 * WordPress's own `post_status => 'any'` admits, which leaves out `trash` and `auto-draft`
 * because both are registered as internal. Revisions are left out by post type, since
 * Elementor copies `_elementor_data` onto each one.
 *
 * A form's id is `<post id>:<widget id>`, the pair `elementor_pro/forms/new_record` reports as
 * `form_post_id` and `id`. For a global widget that pair is the template and the widget's
 * instance on the page, so the walk lists it the same way.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Elementor Pro adapter.
 */
class PixelFlow_Form_Adapter_Elementor extends PixelFlow_Form_Adapter
{
    /** Elementor form field types → canonical types. Anything unlisted is `other`. */
    private const TYPES = [
        'email'        => 'email',
        'tel'          => 'phone',
        'text'         => 'text',
        'textarea'     => 'textarea',
        'number'       => 'number',
        'date'         => 'date',
        'time'         => 'date',
        'select'       => 'choice',
        'radio'        => 'choice',
        'checkbox'     => 'choice',
        'upload'       => 'file',
        'acceptance'   => 'consent',
        'hidden'       => 'hidden',
        'recaptcha'    => 'captcha',
        'recaptcha_v3' => 'captcha',
        'honeypot'     => 'captcha',
        'html'         => 'layout',
        'step'         => 'layout',
    ];

    public function id(): string
    {
        return 'elementor';
    }

    public function label(): string
    {
        return 'Elementor Pro';
    }

    public function is_active(): bool
    {
        return defined('ELEMENTOR_PRO_VERSION');
    }

    public function register_hooks(): void
    {
        add_action('elementor_pro/forms/new_record', [$this, 'on_submit'], 10, 2);
    }

    public function list_forms(): array
    {
        $forms = [];
        foreach ($this->elementor_post_ids() as $post_id) {
            if ($this->is_global_widget_template($post_id)) {
                // Its form is listed where the global widget is placed, under that instance's id.
                continue;
            }
            foreach ($this->forms_in_post($post_id) as $form) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /**
     * One post's tree instead of every post's, with one exception to the base rule: a global
     * widget's id counts while its template can render, even once the instance is no longer
     * placed anywhere. Finding the placement would take a walk of every Elementor post on
     * each submission, and an unplaced instance can only be submitted from a cached copy of
     * the page it left.
     *
     * @param string $form_id `<post id>:<widget id>`
     * @return bool
     */
    public function form_exists(string $form_id): bool
    {
        $parts = explode(':', $form_id, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $post_id = (int) $parts[0];
        if ( ! $this->post_counts($post_id)) {
            return false;
        }

        if ($this->is_global_widget_template($post_id)) {
            return true;
        }

        foreach ($this->forms_in_post($post_id) as $form) {
            if ($form['form_id'] === $form_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param object $record  ElementorPro\Modules\Forms\Classes\Form_Record
     * @param object $handler Ajax handler
     * @return void
     */
    public function on_submit($record, $handler = null): void
    {
        if ( ! is_object($record) || ! method_exists($record, 'get')) {
            return;
        }

        $settings = $record->get('form_settings');
        $raw      = $record->get('fields');
        if ( ! is_array($settings) || ! is_array($raw) || empty($settings['id']) || empty($settings['form_post_id'])) {
            return;
        }

        $fields = [];
        foreach ($raw as $key => $field) {
            if ( ! is_array($field)) {
                continue;
            }
            $type     = isset($field['type']) ? (string) $field['type'] : 'text';
            $fields[] = [
                'key'   => (string) $key,
                'type'  => self::TYPES[$type] ?? 'other',
                'label' => isset($field['title']) ? (string) $field['title'] : '',
                'value' => $field['value'] ?? '',
            ];
        }

        $this->submit(
            (int) $settings['form_post_id'] . ':' . (string) $settings['id'],
            isset($settings['form_name']) ? (string) $settings['form_name'] : '',
            $fields,
            'elementor_pro/forms/new_record'
        );
    }

    /**
     * Forms in one post's layout. An unreadable or unexpected tree yields the forms that could
     * be read, or none, never an error.
     *
     * @param int $post_id Post id
     * @return array
     */
    public function forms_in_post(int $post_id): array
    {
        $tree = $this->decode_tree(get_post_meta($post_id, '_elementor_data', true));
        if ($tree === null) {
            return [];
        }

        $forms = [];
        $this->walk($tree, $post_id, $forms);

        return $forms;
    }

    /**
     * @param array $nodes   Element nodes
     * @param int   $post_id Post holding them
     * @param array $forms   Collected forms
     * @return void
     */
    private function walk(array $nodes, int $post_id, array &$forms): void
    {
        foreach ($nodes as $node) {
            if ( ! is_array($node)) {
                continue;
            }

            $widget = isset($node['widgetType']) && is_string($node['widgetType']) ? $node['widgetType'] : '';
            $id     = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';

            if ($widget === 'form' && $id !== '') {
                $forms[] = $this->form_from_node($node, $post_id . ':' . $id);
            } elseif ($widget === 'global' && $id !== '' && ! empty($node['templateID'])) {
                $template_id = (int) $node['templateID'];
                if ($this->post_counts($template_id)) {
                    $template = $this->decode_tree(get_post_meta($template_id, '_elementor_data', true));
                    $first    = is_array($template) && isset($template[0]) && is_array($template[0]) ? $template[0] : null;
                    if ($first !== null && ($first['widgetType'] ?? '') === 'form') {
                        $forms[] = $this->form_from_node($first, $template_id . ':' . $id);
                    }
                }
            }

            if (isset($node['elements']) && is_array($node['elements'])) {
                $this->walk($node['elements'], $post_id, $forms);
            }
        }
    }

    /**
     * @param array  $node    Form widget node
     * @param string $form_id `<post id>:<widget id>`
     * @return array
     */
    private function form_from_node(array $node, string $form_id): array
    {
        $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
        $defs     = isset($settings['form_fields']) && is_array($settings['form_fields']) ? $settings['form_fields'] : [];

        $fields = [];
        foreach ($defs as $def) {
            if ( ! is_array($def)) {
                continue;
            }
            $key = isset($def['custom_id']) && is_scalar($def['custom_id']) && (string) $def['custom_id'] !== ''
                ? (string) $def['custom_id']
                : (isset($def['_id']) && is_scalar($def['_id']) ? (string) $def['_id'] : '');
            if ($key === '') {
                continue;
            }
            // Elementor leaves a control at its default out of the saved tree; `text` is the default type.
            $type     = isset($def['field_type']) && is_string($def['field_type']) ? $def['field_type'] : 'text';
            $fields[] = [
                'key'   => $key,
                'type'  => self::TYPES[$type] ?? 'other',
                'label' => isset($def['field_label']) && is_scalar($def['field_label']) ? (string) $def['field_label'] : '',
            ];
        }

        return [
            'form_id' => $form_id,
            'title'   => isset($settings['form_name']) && is_scalar($settings['form_name']) && (string) $settings['form_name'] !== ''
                ? (string) $settings['form_name']
                : 'New Form',
            'fields'  => $fields,
        ];
    }

    /**
     * Every post carrying `_elementor_data` that can still render: any status WordPress's
     * `'any'` admits, every post type but revisions.
     *
     * @return int[]
     */
    private function elementor_post_ids(): array
    {
        $types = array_values(array_diff(get_post_types(), ['revision']));

        $ids = get_posts([
            'post_type'        => $types,
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'meta_key'         => '_elementor_data', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin-only listing
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ]);

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * The same rule as the listing, for one post.
     *
     * @param int $post_id Post id
     * @return bool
     */
    private function post_counts(int $post_id): bool
    {
        $post = $post_id > 0 ? get_post($post_id) : null;

        return $post !== null
            && $post->post_type !== 'revision'
            && ! in_array($post->post_status, ['trash', 'auto-draft'], true);
    }

    /**
     * @param int $post_id Post id
     * @return bool
     */
    private function is_global_widget_template(int $post_id): bool
    {
        return get_post_type($post_id) === 'elementor_library'
            && get_post_meta($post_id, '_elementor_template_type', true) === 'widget';
    }

    /**
     * @param mixed $raw `_elementor_data` meta value
     * @return array|null
     */
    private function decode_tree($raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ( ! is_string($raw) || $raw === '') {
            return null;
        }

        $tree = json_decode($raw, true);
        if ( ! is_array($tree)) {
            $tree = json_decode(wp_unslash($raw), true);
        }

        return is_array($tree) ? $tree : null;
    }
}
