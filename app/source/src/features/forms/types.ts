/**
 * @fileoverview Form events types
 * @description Shapes of the `pixelflow_get_forms` read route and the save patch
 */

/** Canonical field type an adapter reports (see includes/forms/submission.php). */
export type FormFieldType =
  | 'email'
  | 'phone'
  | 'first_name'
  | 'last_name'
  | 'name'
  | 'city'
  | 'state'
  | 'zip'
  | 'country'
  | 'text'
  | 'textarea'
  | 'choice'
  | 'number'
  | 'date'
  | 'file'
  | 'other'
  | 'hidden'
  | 'submit'
  | 'captcha'
  | 'consent'
  | 'layout';

/** Identifier a form event can carry. */
export type FormIdentifier = 'em' | 'ph' | 'fn' | 'ln' | 'ct' | 'st' | 'zp' | 'country';

export interface FormField {
  key: string;
  type: FormFieldType;
  label: string;
}

/** A person's choice for one identifier; an empty key means "don't send". */
export interface StoredFieldChoice {
  key: string;
  type: string;
  label: string;
}

/** What a person set for a form. Anything absent is computed. */
export interface FormRecord {
  enabled?: number;
  event?: string;
  value?: number;
  title?: string;
  fields?: Partial<Record<FormIdentifier, StoredFieldChoice>>;
}

export interface FormRow {
  key: string;
  source: string;
  source_label: string;
  form_id: string;
  title: string;
  fields: FormField[];
  confidence: 'high' | 'medium';
  suggested_event: string;
  detected: Partial<Record<FormIdentifier, string>>;
  /** Fields whose key or label only contains a name or state word: never sent until chosen. */
  unconfirmed: Partial<Record<FormIdentifier, string>>;
  record: FormRecord;
  missing: boolean;
}

export interface FormPluginStatus {
  id: string;
  label: string;
  active: boolean;
}

export interface FormsListing {
  plugins: FormPluginStatus[];
  events: string[];
  identifiers: FormIdentifier[];
  forms: FormRow[];
}

/** Changes to one form: only what the person changed; `null` returns an entry to computed. */
export interface FormPatch {
  title?: string;
  enabled?: number | null;
  event?: string | null;
  value?: number | null;
  fields?: Partial<Record<FormIdentifier, StoredFieldChoice | null>>;
}

export type FormSettingsPatch = Record<string, FormPatch>;

/**
 * How an identifier gets its field: detected by native type, suggested by name, chosen, only
 * offered for a person to confirm, or none.
 */
export type IdentifierState = 'auto' | 'suggested' | 'custom' | 'unconfirmed' | 'empty';
