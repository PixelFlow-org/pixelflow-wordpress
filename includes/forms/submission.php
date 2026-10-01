<?php
/**
 * The normalised submission every form adapter produces and the dispatcher consumes.
 *
 * Shape:
 *   [
 *     'source'     => 'cf7',            // adapter id
 *     'form_id'    => '12',             // the plugin's own form id ('<post>:<widget>' for Elementor)
 *     'form_title' => 'Contact us',
 *     'fields'     => [
 *       ['key' => 'your-email', 'value' => 'a@example.test', 'type' => 'email', 'label' => 'Email'],
 *       ...
 *     ],
 *   ]
 *
 * `type` is one of the canonical types documented beside PIXELFLOW_FORM_NON_INPUT_TYPES.
 * The same shape without `value` describes a form's fields for the settings list.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/** Canonical field types an adapter may report; anything else becomes `other`. */
const PIXELFLOW_FORM_FIELD_TYPES = [
    'email',
    'phone',
    'first_name',
    'last_name',
    'name',
    'city',
    'state',
    'zip',
    'country',
    'text',
    'textarea',
    'choice',
    'number',
    'date',
    'file',
    'other',
    'hidden',
    'submit',
    'captcha',
    'consent',
    'layout',
];

/**
 * The record key a form's configuration is stored under: `<source>:<form id>`.
 *
 * @param string $source  Adapter id
 * @param string $form_id Plugin form id
 * @return string
 */
function pixelflow_form_key(string $source, string $form_id): string
{
    return $source . ':' . $form_id;
}

/**
 * Keeps only the keys the feature speaks and discards everything else an adapter passed.
 *
 * Values stay raw here: they are needed to build identifiers and are hashed before anything
 * leaves the request. Nothing in this array is ever stored or logged as-is.
 *
 * @param mixed $submission Adapter output, or what a site passed to `pixelflow_track_form`
 * @return array|null Normalised submission, or null when it cannot identify a form
 */
function pixelflow_sanitize_form_submission($submission): ?array
{
    if ( ! is_array($submission)) {
        return null;
    }

    $source  = isset($submission['source']) && is_scalar($submission['source'])
        ? sanitize_key((string) $submission['source'])
        : '';
    $form_id = isset($submission['form_id']) && is_scalar($submission['form_id'])
        ? sanitize_text_field((string) $submission['form_id'])
        : '';
    if ($source === '' || $form_id === '') {
        return null;
    }

    $title = isset($submission['form_title']) && is_scalar($submission['form_title'])
        ? sanitize_text_field((string) $submission['form_title'])
        : '';

    $fields = [];
    $raw    = isset($submission['fields']) && is_array($submission['fields']) ? $submission['fields'] : [];
    foreach ($raw as $field) {
        $clean = pixelflow_sanitize_form_field($field, true);
        if ($clean !== null) {
            $fields[] = $clean;
        }
    }

    return [
        'source'     => $source,
        'form_id'    => $form_id,
        'form_title' => $title,
        'fields'     => $fields,
    ];
}

/**
 * One field of a submission or of a form's field list.
 *
 * @param mixed $field      Raw field
 * @param bool  $with_value Whether to keep `value` (a submission) or drop it (a field list)
 * @return array|null
 */
function pixelflow_sanitize_form_field($field, bool $with_value): ?array
{
    if ( ! is_array($field) || ! isset($field['key']) || ! is_scalar($field['key'])) {
        return null;
    }

    $key = sanitize_text_field((string) $field['key']);
    if ($key === '') {
        return null;
    }

    $type = isset($field['type']) && is_scalar($field['type']) ? (string) $field['type'] : 'other';
    if ( ! in_array($type, PIXELFLOW_FORM_FIELD_TYPES, true)) {
        $type = 'other';
    }

    $clean = [
        'key'   => $key,
        'type'  => $type,
        'label' => isset($field['label']) && is_scalar($field['label'])
            ? sanitize_text_field((string) $field['label'])
            : '',
    ];

    if ($with_value) {
        $value          = $field['value'] ?? '';
        $clean['value'] = is_scalar($value) ? (string) $value : '';
    }

    return $clean;
}
