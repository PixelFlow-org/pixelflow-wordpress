<?php
/**
 * Base class for a form-plugin adapter.
 *
 * An adapter is the only place that knows a form plugin: whether it is active, which forms it
 * has and their fields, and which hook reports a successful submission. It hands that
 * submission to the dispatcher in the normalised shape (see submission.php) and knows nothing
 * about consent, hashing or sending.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Form-plugin adapter.
 */
abstract class PixelFlow_Form_Adapter
{
    /**
     * Adapter id, the first half of every form key (`cf7`, `wpforms`, ...).
     *
     * @return string
     */
    abstract public function id(): string;

    /**
     * Plugin name for the settings page.
     *
     * @return string
     */
    abstract public function label(): string;

    /**
     * Whether the plugin is installed and active.
     *
     * @return bool
     */
    abstract public function is_active(): bool;

    /**
     * Every form that is neither deleted nor in the plugin's trash, whatever its active or
     * published state.
     *
     * @return array<int, array{form_id: string, title: string, fields: array<int, array{key: string, type: string, label: string}>}>
     */
    abstract public function list_forms(): array;

    /**
     * Registers the plugin's success hook.
     *
     * @return void
     */
    abstract public function register_hooks(): void;

    /**
     * Fields of one form, or an empty list when it does not exist.
     *
     * @param string $form_id Plugin form id
     * @return array<int, array{key: string, type: string, label: string}>
     */
    public function list_fields(string $form_id): array
    {
        foreach ($this->list_forms() as $form) {
            if ($form['form_id'] === $form_id) {
                return $form['fields'];
            }
        }

        return [];
    }

    /**
     * Whether a form still exists: listed by list_forms() under the same rule.
     *
     * Adapters with a cheaper lookup override this; the answer must stay the same, unless the
     * override documents where it does not.
     *
     * @param string $form_id Plugin form id
     * @return bool
     */
    public function form_exists(string $form_id): bool
    {
        foreach ($this->list_forms() as $form) {
            if ($form['form_id'] === $form_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hands a successful submission to the dispatcher.
     *
     * @param string $form_id Plugin form id
     * @param string $title   Form title
     * @param array  $fields  Fields with key, type, label and value
     * @param string $hook    Hook name, for the debug log
     * @return string Dispatcher outcome
     */
    protected function submit(string $form_id, string $title, array $fields, string $hook): string
    {
        return PixelFlow_Form_Dispatcher::dispatch(
            [
                'source'     => $this->id(),
                'form_id'    => $form_id,
                'form_title' => $title,
                'fields'     => $fields,
            ],
            $hook
        );
    }

    /**
     * Pairs a field list with submitted values by key.
     *
     * @param array                $fields Fields with key, type and label
     * @param array<string, mixed> $values Submitted values by key
     * @return array Fields with value
     */
    protected function with_values(array $fields, array $values): array
    {
        $out = [];
        foreach ($fields as $field) {
            $value = $values[$field['key']] ?? '';
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
            }
            $field['value'] = is_scalar($value) ? (string) $value : '';
            $out[]          = $field;
        }

        return $out;
    }
}
