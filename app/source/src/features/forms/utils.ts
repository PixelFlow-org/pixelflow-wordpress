/**
 * @fileoverview Form events helpers
 * @description Pure functions the Forms UI renders from: the effective switch and event of a
 * row, each identifier's state and field, and whether a person's choice has gone stale.
 * They mirror pixelflow_form_effective_config() so the page shows what a submission would do.
 */

import type {
  FormField,
  FormIdentifier,
  FormRow,
  IdentifierState,
  StoredFieldChoice,
} from '@/features/forms/types';

/** Field types that make an identifier "auto" rather than "suggested". */
const NATIVE_TYPES: Record<string, FormIdentifier[]> = {
  email: ['em'],
  phone: ['ph'],
  first_name: ['fn'],
  last_name: ['ln'],
  name: ['fn', 'ln'],
  city: ['ct'],
  state: ['st'],
  zip: ['zp'],
  country: ['country'],
};

/**
 * Warning colours, as the shared Notification uses them. The theme defines no yellow, so a
 * utility class for one compiles to nothing.
 */
export const WARNING_TEXT_STYLE = { color: '#854d0e' };
export const WARNING_ROW_STYLE = { backgroundColor: '#fefce8' };

export const IDENTIFIER_LABELS: Record<FormIdentifier, string> = {
  em: 'Email',
  ph: 'Phone',
  fn: 'First name',
  ln: 'Last name',
  ct: 'City',
  st: 'State',
  zp: 'Postcode',
  country: 'Country',
};

/** Switch a row runs with: stored when a person set it, else "currently high confidence". */
export function effectiveEnabled(row: FormRow): boolean {
  if (row.record.enabled !== undefined) {
    return row.record.enabled === 1;
  }
  return row.confidence === 'high';
}

/** Event a row runs with: stored when a person set it, else the current suggestion. */
export function effectiveEvent(row: FormRow): string {
  return row.record.event || row.suggested_event;
}

/** The stored choice for an identifier, when a person made one. */
export function storedChoice(row: FormRow, identifier: FormIdentifier): StoredFieldChoice | null {
  return row.record.fields?.[identifier] ?? null;
}

/** The field key an identifier reads, or null when it is not sent. */
export function effectiveFieldKey(row: FormRow, identifier: FormIdentifier): string | null {
  const choice = storedChoice(row, identifier);
  if (choice) {
    return choice.key !== '' ? choice.key : null;
  }
  return row.detected[identifier] ?? null;
}

/**
 * The field an identifier only looks like it reads, while nothing is detected or chosen for it.
 * It is not sent until a person chooses it.
 */
export function unconfirmedField(row: FormRow, identifier: FormIdentifier): FormField | null {
  if (storedChoice(row, identifier) || row.detected[identifier]) {
    return null;
  }
  const key = row.unconfirmed[identifier];
  return key ? (row.fields.find((f) => f.key === key) ?? null) : null;
}

/**
 * Choosing an unconfirmed field for every identifier it was offered for, so a whole-name field
 * is confirmed as first and last name together and still splits.
 */
export function confirmFieldPatch(
  row: FormRow,
  field: FormField,
  identifiers: FormIdentifier[]
): Partial<Record<FormIdentifier, StoredFieldChoice>> {
  const patch: Partial<Record<FormIdentifier, StoredFieldChoice>> = {};
  for (const identifier of identifiers) {
    if (unconfirmedField(row, identifier)?.key === field.key) {
      patch[identifier] = choiceForField(field);
    }
  }
  return patch;
}

/** Auto, Suggested, Custom, Unconfirmed or Empty. */
export function identifierState(row: FormRow, identifier: FormIdentifier): IdentifierState {
  const choice = storedChoice(row, identifier);
  if (choice) {
    return choice.key !== '' ? 'custom' : 'empty';
  }
  const key = row.detected[identifier];
  if (!key) {
    return unconfirmedField(row, identifier) ? 'unconfirmed' : 'empty';
  }
  const field = row.fields.find((f) => f.key === key);
  return field && (NATIVE_TYPES[field.type] ?? []).includes(identifier) ? 'auto' : 'suggested';
}

/**
 * Whether a person's choice no longer matches the form: its field is gone, or its type or
 * label changed since the choice was saved. Detected identifiers are never stale.
 */
export function choiceIsStale(row: FormRow, identifier: FormIdentifier): boolean {
  const choice = storedChoice(row, identifier);
  if (!choice || choice.key === '' || row.missing) {
    return false;
  }
  const field = row.fields.find((f) => f.key === choice.key);
  if (!field) {
    return true;
  }
  return field.type !== choice.type || field.label !== choice.label;
}

/** Identifiers the row sends, for the summary line. A choice whose field is gone sends nothing. */
export function mappedIdentifiers(row: FormRow, identifiers: FormIdentifier[]): FormIdentifier[] {
  return identifiers.filter((identifier) => {
    const key = effectiveFieldKey(row, identifier);
    return key !== null && (row.missing || row.fields.some((f) => f.key === key));
  });
}

/** A stored choice pointing at a field, carrying its type and label as they are now. */
export function choiceForField(field: FormField): StoredFieldChoice {
  return { key: field.key, type: field.type, label: field.label };
}
