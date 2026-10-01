<?php
/**
 * Ninja Forms adapter.
 *
 * Forms and fields come from Ninja's own model factory; forms are deleted for good, not
 * trashed. Fields are keyed by their field key. `ninja_forms_after_submission` runs only once
 * every field has validated and every action has run: a spam, honeypot or captcha field that
 * fails stops the submission before it, and an action that raises an error halts it too. The
 * adapter still skips a submission that reaches it carrying errors.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Ninja Forms adapter.
 */
class PixelFlow_Form_Adapter_NinjaForms extends PixelFlow_Form_Adapter
{
    /** Ninja field types → canonical types. Anything unlisted is `other`. */
    private const TYPES = [
        'email'           => 'email',
        'phone'           => 'phone',
        'firstname'       => 'first_name',
        'lastname'        => 'last_name',
        'city'            => 'city',
        'zip'             => 'zip',
        'liststate'       => 'state',
        'listcountry'     => 'country',
        'textbox'         => 'text',
        'textarea'        => 'textarea',
        'number'          => 'number',
        'quantity'        => 'number',
        'date'            => 'date',
        'listcheckbox'    => 'choice',
        'listradio'       => 'choice',
        'listselect'      => 'choice',
        'listmultiselect' => 'choice',
        'listimage'       => 'choice',
        'starrating'      => 'choice',
        'file_upload'     => 'file',
        'checkbox'        => 'consent',
        'terms'           => 'consent',
        'hidden'          => 'hidden',
        // A confirm field repeats another field's value, so it is not a second input.
        'confirm'         => 'hidden',
        'spam'            => 'captcha',
        'recaptcha'       => 'captcha',
        'recaptcha_v3'    => 'captcha',
        'hcaptcha'        => 'captcha',
        'turnstile'       => 'captcha',
        'html'            => 'layout',
        'hr'              => 'layout',
        'note'            => 'layout',
        'submit'          => 'submit',
    ];

    public function id(): string
    {
        return 'ninja';
    }

    public function label(): string
    {
        return 'Ninja Forms';
    }

    public function is_active(): bool
    {
        return function_exists('Ninja_Forms');
    }

    public function register_hooks(): void
    {
        add_action('ninja_forms_after_submission', [$this, 'on_submit'], 10, 1);
    }

    public function list_forms(): array
    {
        $forms = [];
        foreach (Ninja_Forms()->form()->get_forms() as $form) {
            $id      = (string) $form->get_id();
            $forms[] = [
                'form_id' => $id,
                'title'   => (string) $form->get_setting('title'),
                'fields'  => $this->fields_of($id),
            ];
        }

        return $forms;
    }

    public function form_exists(string $form_id): bool
    {
        foreach (Ninja_Forms()->form()->get_forms() as $form) {
            if ((string) $form->get_id() === $form_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $data Submission data: form_id, settings, fields keyed by field id
     * @return void
     */
    public function on_submit($data): void
    {
        if ( ! is_array($data) || empty($data['form_id']) || ! empty($data['errors'])) {
            return;
        }

        $fields = [];
        $raw    = isset($data['fields']) && is_array($data['fields']) ? array_values($data['fields']) : [];
        // Keyed by field id; detection takes the first qualifying field in the order the form shows.
        usort($raw, static function ($a, $b) {
            return (int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0);
        });
        foreach ($raw as $field) {
            if ( ! is_array($field) || empty($field['key'])) {
                continue;
            }
            $type     = isset($field['type']) ? (string) $field['type'] : '';
            $value    = $field['value'] ?? '';
            $fields[] = [
                'key'   => (string) $field['key'],
                'type'  => self::TYPES[$type] ?? 'other',
                'label' => isset($field['label']) ? (string) $field['label'] : '',
                'value' => is_array($value) ? implode(', ', array_filter($value, 'is_scalar')) : $value,
            ];
        }

        $this->submit(
            (string) $data['form_id'],
            isset($data['settings']['title']) ? (string) $data['settings']['title'] : '',
            $fields,
            'ninja_forms_after_submission'
        );
    }

    /**
     * @param string $form_id Form id
     * @return array
     */
    private function fields_of(string $form_id): array
    {
        $models = Ninja_Forms()->form((int) $form_id)->get_fields();
        if ( ! is_array($models)) {
            return [];
        }

        usort($models, static function ($a, $b) {
            return (int) $a->get_setting('order') <=> (int) $b->get_setting('order');
        });

        $fields = [];
        foreach ($models as $model) {
            $key = (string) $model->get_setting('key');
            if ($key === '') {
                continue;
            }
            $type     = (string) $model->get_setting('type');
            $fields[] = [
                'key'   => $key,
                'type'  => self::TYPES[$type] ?? 'other',
                'label' => (string) $model->get_setting('label'),
            ];
        }

        return $fields;
    }
}
