<?php
/**
 * Per-form configuration records.
 *
 * One option, `pixelflow_form_settings`, maps a form key (`<source>:<form id>`) to what a
 * person set for that form and nothing else: `enabled` and `event` only once a person changed
 * them, `value` when set, and `fields` holding only the identifiers a person chose. Absent
 * entries are computed at submit time (see pixelflow_form_effective_config()).
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/** Option holding the per-form records. */
const PIXELFLOW_FORM_SETTINGS_OPTION = 'pixelflow_form_settings';

/**
 * All stored records, keyed by form key.
 *
 * @return array<string, array<string, mixed>>
 */
function pixelflow_get_form_records(): array
{
    $records = get_option(PIXELFLOW_FORM_SETTINGS_OPTION, []);

    return is_array($records) ? $records : [];
}

/**
 * The stored record for one form, or an empty array when nobody configured it.
 *
 * @param string $form_key Form key
 * @return array<string, mixed>
 */
function pixelflow_get_form_record(string $form_key): array
{
    $records = pixelflow_get_form_records();

    return isset($records[$form_key]) && is_array($records[$form_key]) ? $records[$form_key] : [];
}

/**
 * Whether an event name is a Meta standard event, compared case-sensitively.
 *
 * @param mixed $event Candidate name
 * @return bool
 */
function pixelflow_is_standard_meta_event($event): bool
{
    return is_string($event) && in_array($event, PIXELFLOW_META_STANDARD_EVENTS, true);
}

/**
 * A form key as the option stores it: the adapter id, a colon, then the plugin's own id.
 *
 * @param mixed $key Candidate key
 * @return string Sanitized key, or '' when it is not a form key
 */
function pixelflow_sanitize_form_key($key): string
{
    if ( ! is_string($key) || strlen($key) > 191) {
        return '';
    }

    return preg_match('/^[a-z0-9_\-]+:[A-Za-z0-9_\-:.]+$/', $key) === 1 ? $key : '';
}

/**
 * Applies a settings-page patch to the stored records.
 *
 * Each form in the patch lists only what the person changed. A `null` removes the stored
 * entry, returning it to its computed value; a field entry with an empty key is a deliberate
 * "don't send". An event name outside the catalogue is dropped, so the stored event stays as
 * it was.
 *
 * @param array $records Stored records
 * @param mixed $patch   form key => changes
 * @return array<string, array<string, mixed>> Updated records
 */
function pixelflow_apply_form_settings_patch(array $records, $patch): array
{
    if ( ! is_array($patch)) {
        return $records;
    }

    foreach ($patch as $raw_key => $changes) {
        $key = pixelflow_sanitize_form_key($raw_key);
        if ($key === '' || ! is_array($changes)) {
            continue;
        }

        $record = isset($records[$key]) && is_array($records[$key]) ? $records[$key] : [];

        if (array_key_exists('enabled', $changes)) {
            if ($changes['enabled'] === null) {
                unset($record['enabled']);
            } else {
                $record['enabled'] = filter_var($changes['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }

        if (array_key_exists('event', $changes)) {
            if ($changes['event'] === null) {
                unset($record['event']);
            } elseif (pixelflow_is_standard_meta_event($changes['event'])) {
                $record['event'] = $changes['event'];
            }
        }

        if (array_key_exists('value', $changes)) {
            $value = $changes['value'];
            if ($value === null || $value === '') {
                unset($record['value']);
            } elseif (is_numeric($value) && is_finite((float) $value) && (float) $value >= 0) {
                $record['value'] = (float) $value;
            }
        }

        if (array_key_exists('title', $changes) && is_scalar($changes['title'])) {
            $record['title'] = sanitize_text_field((string) $changes['title']);
        }

        if (isset($changes['fields']) && is_array($changes['fields'])) {
            $fields = isset($record['fields']) && is_array($record['fields']) ? $record['fields'] : [];
            foreach ($changes['fields'] as $identifier => $choice) {
                if ( ! in_array($identifier, PIXELFLOW_FORM_IDENTIFIERS, true)) {
                    continue;
                }
                if ($choice === null) {
                    unset($fields[$identifier]);
                    continue;
                }
                if ( ! is_array($choice)) {
                    continue;
                }
                $type = isset($choice['type']) && is_string($choice['type'])
                    && in_array($choice['type'], PIXELFLOW_FORM_FIELD_TYPES, true) ? $choice['type'] : '';
                $fields[$identifier] = [
                    'key'   => isset($choice['key']) && is_scalar($choice['key']) ? sanitize_text_field((string) $choice['key']) : '',
                    'type'  => $type,
                    'label' => isset($choice['label']) && is_scalar($choice['label']) ? sanitize_text_field((string) $choice['label']) : '',
                ];
            }
            if ($fields === []) {
                unset($record['fields']);
            } else {
                $record['fields'] = $fields;
            }
        }

        $settled = array_diff(array_keys($record), ['title']);
        if ($settled === []) {
            unset($records[$key]);
        } else {
            $records[$key] = $record;
        }
    }

    return $records;
}
