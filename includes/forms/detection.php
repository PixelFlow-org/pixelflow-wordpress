<?php
/**
 * Identifier detection, form classification and the effective per-form configuration.
 *
 * Everything here is a pure function of a form's fields, its title and its stored record, so
 * the settings list and the submission path compute the same answer from the same input.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Whether a form title contains one of the patterns, compared lowercased.
 *
 * @param string   $title    Form title
 * @param string[] $patterns Lowercased substrings
 * @return bool
 */
function pixelflow_form_title_matches(string $title, array $patterns): bool
{
    $title = mb_strtolower($title, 'UTF-8');
    if ($title === '') {
        return false;
    }

    foreach ($patterns as $pattern) {
        if ($pattern !== '' && mb_strpos($title, (string) $pattern, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Lowercases a key or label and turns every run of non-letters into one space, so
 * `billing_phone`, `billing-phone` and `Billing Phone` read the same.
 *
 * @param string $text Key or label
 * @return string
 */
function pixelflow_form_normalise_words(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

    return is_string($text) ? trim($text) : '';
}

/**
 * Whether a key or label contains one of the patterns at the start of a word.
 *
 * @param string   $text     Key or label
 * @param string[] $patterns Identifier-name patterns
 * @return bool
 */
function pixelflow_form_words_match(string $text, array $patterns): bool
{
    $haystack = ' ' . pixelflow_form_normalise_words($text);
    if ($haystack === ' ') {
        return false;
    }

    foreach ($patterns as $pattern) {
        $needle = pixelflow_form_normalise_words((string) $pattern);
        if ($needle !== '' && mb_strpos($haystack, ' ' . $needle, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a field's key or label names one of the given identifiers.
 *
 * @param array  $field      Field with key and label
 * @param string $identifier Key into PIXELFLOW_FORM_IDENTIFIER_PATTERNS
 * @return bool
 */
function pixelflow_form_field_names_identifier(array $field, string $identifier): bool
{
    $patterns = PIXELFLOW_FORM_IDENTIFIER_PATTERNS[$identifier] ?? [];

    return pixelflow_form_words_match((string) ($field['key'] ?? ''), $patterns)
        || pixelflow_form_words_match((string) ($field['label'] ?? ''), $patterns);
}

/**
 * Whether a field's whole key or whole label is one of an identifier's patterns.
 *
 * @param array  $field      Field with key and label
 * @param string $identifier Key into PIXELFLOW_FORM_IDENTIFIER_PATTERNS
 * @return bool
 */
function pixelflow_form_field_is_identifier(array $field, string $identifier): bool
{
    $patterns = array_map('pixelflow_form_normalise_words', PIXELFLOW_FORM_IDENTIFIER_PATTERNS[$identifier] ?? []);

    foreach ([(string) ($field['key'] ?? ''), (string) ($field['label'] ?? '')] as $text) {
        $text = pixelflow_form_normalise_words($text);
        if ($text !== '' && in_array($text, $patterns, true)) {
            return true;
        }
    }

    return false;
}

/**
 * The first text field that names an identifier: one whose whole key or label is a pattern
 * when the identifier's group needs it, else the first whose key or label contains one.
 *
 * @param array  $text_fields Plain text fields, in form order
 * @param array  $used        Field keys already taken
 * @param string $identifier  Key into PIXELFLOW_FORM_IDENTIFIER_PATTERNS
 * @return array{key: string, confirmed: bool}|null
 */
function pixelflow_form_find_named_field(array $text_fields, array $used, string $identifier): ?array
{
    $whole_text = in_array($identifier, PIXELFLOW_FORM_WHOLE_TEXT_PATTERN_GROUPS, true);
    $partial    = null;

    foreach ($text_fields as $field) {
        $key = (string) $field['key'];
        if (isset($used[$key]) || ! pixelflow_form_field_names_identifier($field, $identifier)) {
            continue;
        }
        if ( ! $whole_text || pixelflow_form_field_is_identifier($field, $identifier)) {
            return ['key' => $key, 'confirmed' => true];
        }
        $partial = $partial ?? $key;
    }

    return $partial !== null ? ['key' => $partial, 'confirmed' => false] : null;
}

/**
 * Which field supplies each identifier, from the fields alone.
 *
 * @param array $fields Fields with key, type and label, in form order
 * @return array<string, string> Identifier → field key, only for identifiers found
 */
function pixelflow_form_detect_map(array $fields): array
{
    return pixelflow_form_detect($fields)['map'];
}

/**
 * Which field supplies each identifier, and which field only looks like it might.
 *
 * Native type first, then the key and label of plain text fields; the first field in form
 * order wins at each stage. A message or text-area field is never considered. A single field
 * holding the whole name is mapped to both `fn` and `ln`, which tells the extractor to split
 * it; next to a detected first name it supplies the last name only.
 *
 * For the pattern groups in PIXELFLOW_FORM_WHOLE_TEXT_PATTERN_GROUPS, a field whose whole key
 * or label is a pattern wins over one that only contains it. A field that only contains it is
 * returned as unconfirmed: never sent, only offered to a person.
 *
 * @param array $fields Fields with key, type and label, in form order
 * @return array{map: array<string, string>, unconfirmed: array<string, string>}
 */
function pixelflow_form_detect(array $fields): array
{
    $map         = [];
    $unconfirmed = [];
    $used        = [];

    foreach ($fields as $field) {
        $type = (string) ($field['type'] ?? '');
        $key  = (string) ($field['key'] ?? '');
        if ($key === '') {
            continue;
        }

        if (isset(PIXELFLOW_FORM_NATIVE_TYPE_IDENTIFIERS[$type])) {
            $identifier = PIXELFLOW_FORM_NATIVE_TYPE_IDENTIFIERS[$type];
            if ( ! isset($map[$identifier])) {
                $map[$identifier] = $key;
                $used[$key]       = true;
            }
            continue;
        }

        if ($type === 'name' && ! isset($map['fn']) && ! isset($map['ln'])) {
            $map['fn']  = $key;
            $map['ln']  = $key;
            $used[$key] = true;
        }
    }

    $text_fields = array_values(array_filter($fields, static function ($field) {
        return ($field['type'] ?? '') === 'text' && ($field['key'] ?? '') !== '';
    }));

    foreach (PIXELFLOW_FORM_IDENTIFIERS as $identifier) {
        if (isset($map[$identifier])) {
            continue;
        }
        $found = pixelflow_form_find_named_field($text_fields, $used, $identifier);
        if ($found === null) {
            continue;
        }
        if ($found['confirmed']) {
            $map[$identifier] = $found['key'];
        } else {
            $unconfirmed[$identifier] = $found['key'];
        }
        $used[$found['key']] = true;
    }

    if (isset($map['fn']) && isset($map['ln'])) {
        return ['map' => $map, 'unconfirmed' => $unconfirmed];
    }

    $found = pixelflow_form_find_named_field($text_fields, $used, 'name');
    if ($found !== null) {
        if ( ! isset($map['fn']) && ! isset($map['ln'])) {
            $identifiers = ['fn', 'ln'];
        } elseif ( ! isset($map['ln'])) {
            $identifiers = ['ln'];
        } else {
            $identifiers = ['fn'];
        }
        foreach ($identifiers as $identifier) {
            if ($found['confirmed']) {
                $map[$identifier] = $found['key'];
            } else {
                $unconfirmed[$identifier] = $found['key'];
            }
        }
    }

    return ['map' => $map, 'unconfirmed' => $unconfirmed];
}

/**
 * Whether the email field is the form's only input field.
 *
 * @param array $fields Fields with type
 * @return bool
 */
function pixelflow_form_is_email_only(array $fields): bool
{
    $inputs = array_values(array_filter($fields, static function ($field) {
        return ! in_array((string) ($field['type'] ?? ''), PIXELFLOW_FORM_NON_INPUT_TYPES, true);
    }));

    return count($inputs) === 1 && ($inputs[0]['type'] ?? '') === 'email';
}

/**
 * Confidence and suggested event from the title and fields.
 *
 * High confidence needs a field whose native type is email or phone and a title that names
 * no search, login, password or comment form. The suggested event is `CompleteRegistration`
 * for a registration title or an email-only form, `Lead` otherwise; the two title lists are
 * read independently.
 *
 * @param string $title  Form title
 * @param array  $fields Fields with type
 * @return array{confidence: string, suggested_event: string, excluded: bool}
 */
function pixelflow_form_classify(string $title, array $fields): array
{
    $has_native = false;
    foreach ($fields as $field) {
        $type = (string) ($field['type'] ?? '');
        if ($type === 'email' || $type === 'phone') {
            $has_native = true;
            break;
        }
    }

    $excluded     = pixelflow_form_title_matches($title, PIXELFLOW_FORM_TITLE_EXCLUSION_PATTERNS);
    $registration = pixelflow_form_title_matches($title, PIXELFLOW_FORM_TITLE_REGISTRATION_PATTERNS)
        || pixelflow_form_is_email_only($fields);

    return [
        'confidence'      => $has_native && ! $excluded ? 'high' : 'medium',
        'suggested_event' => $registration ? 'CompleteRegistration' : 'Lead',
        'excluded'        => $excluded,
    ];
}

/**
 * The configuration a form runs with: what a person stored, and the computed answer for
 * everything they did not.
 *
 * An absent `enabled` is "currently high confidence", an absent `event` the current suggested
 * event, an absent identifier the detected field. A stored identifier with an empty key is a
 * deliberate "don't send" and stays absent.
 *
 * @param array  $record Stored record for the form (possibly empty)
 * @param string $title  Form title
 * @param array  $fields Current fields of the form
 * @return array{enabled: bool, event: string, value: ?float, map: array<string, string>, confidence: string, suggested_event: string, detected: array<string, string>, unconfirmed: array<string, string>}
 */
function pixelflow_form_effective_config(array $record, string $title, array $fields): array
{
    $classification = pixelflow_form_classify($title, $fields);
    $detection      = pixelflow_form_detect($fields);
    $detected       = $detection['map'];

    $map    = [];
    $stored = isset($record['fields']) && is_array($record['fields']) ? $record['fields'] : [];
    foreach (PIXELFLOW_FORM_IDENTIFIERS as $identifier) {
        if (array_key_exists($identifier, $stored)) {
            $key = isset($stored[$identifier]['key']) ? (string) $stored[$identifier]['key'] : '';
            if ($key !== '') {
                $map[$identifier] = $key;
            }
            continue;
        }
        if (isset($detected[$identifier])) {
            $map[$identifier] = $detected[$identifier];
        }
    }

    return [
        'enabled'         => array_key_exists('enabled', $record)
            ? (bool) $record['enabled']
            : $classification['confidence'] === 'high',
        'event'           => isset($record['event']) && is_string($record['event']) && $record['event'] !== ''
            ? $record['event']
            : $classification['suggested_event'],
        'value'           => isset($record['value']) && is_numeric($record['value']) ? (float) $record['value'] : null,
        'map'             => $map,
        'confidence'      => $classification['confidence'],
        'suggested_event' => $classification['suggested_event'],
        'detected'        => $detected,
        'unconfirmed'     => $detection['unconfirmed'],
    ];
}

/**
 * Normalised and hashed customerData for a submission, and which identifiers it carries.
 *
 * `fn` and `ln` pointing at the same field mean one field holds the whole name: its trimmed
 * value is split on the first space, and a value with no space supplies the first name only.
 *
 * @param array $fields Submission fields with key and value
 * @param array $map    Identifier → field key
 * @return array{customer: array<string, string>, present: string[]}
 */
function pixelflow_form_build_customer_data(array $fields, array $map): array
{
    $values = [];
    foreach ($fields as $field) {
        $key = (string) ($field['key'] ?? '');
        if ($key !== '' && ! isset($values[$key])) {
            $values[$key] = (string) ($field['value'] ?? '');
        }
    }

    $raw = [];
    foreach ($map as $identifier => $key) {
        if (isset($values[$key])) {
            $raw[$identifier] = trim($values[$key]);
        }
    }

    if (isset($map['fn'], $map['ln']) && $map['fn'] === $map['ln'] && isset($raw['fn'])) {
        $parts     = preg_split('/\s+/u', $raw['fn'], 2);
        $raw['fn'] = is_array($parts) ? (string) $parts[0] : $raw['fn'];
        if (is_array($parts) && isset($parts[1]) && trim($parts[1]) !== '') {
            $raw['ln'] = trim($parts[1]);
        } else {
            unset($raw['ln']);
        }
    }

    $normalisers = [
        'em'      => 'pixelflow_normalize_email',
        'ph'      => 'pixelflow_normalize_phone',
        'fn'      => 'pixelflow_normalize_name',
        'ln'      => 'pixelflow_normalize_name',
        'ct'      => 'pixelflow_normalize_city',
        'st'      => 'pixelflow_normalize_state',
        'zp'      => 'pixelflow_normalize_zip',
        'country' => 'pixelflow_normalize_country',
    ];

    $customer = [];
    foreach (PIXELFLOW_FORM_IDENTIFIERS as $identifier) {
        if ( ! isset($raw[$identifier])) {
            continue;
        }
        $hashed = pixelflow_sha256_if_not_empty(call_user_func($normalisers[$identifier], $raw[$identifier]));
        if ($hashed !== '') {
            $customer[$identifier] = $hashed;
        }
    }

    return [
        'customer' => $customer,
        'present'  => array_keys($customer),
    ];
}
