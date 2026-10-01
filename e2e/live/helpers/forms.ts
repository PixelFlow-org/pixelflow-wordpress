/**
 * Form-event helpers for the live suite: the fixture matrix seeded on the site, the settings
 * the form scenarios run under, and the form entries of the shared debug log.
 *
 * Form entries are written by the form dispatcher (includes/forms/dispatcher.php), not by the
 * WooCommerce hooks, and carry `hook: "FORM <plugin hook>"`, the form key, the event and an
 * `outcome`. The anonymous blocked-events beacon a form sends is its own entry,
 * `hook: "FORM BLOCKED_EVENTS <event>"`.
 */
import { createHash } from 'node:crypto';
import { wp, wpEval } from './ssh';
import { readDebugLog, waitForRecords, type EventRecord } from './debug-log';

/** One seeded form: the plugin that owns it and the page it sits on alone. */
export interface FormFixture {
  source: string;
  form_id: string;
  shape: string;
  page_id: number;
  url: string;
}

/** A form entry of the debug log. */
export interface FormRecord extends EventRecord {
  form?: string;
  event?: string;
  outcome?: 'sent' | 'held' | 'blocked' | 'skipped' | 'failed';
  reason?: string;
  identifiers?: string[];
}

/** The plugins the matrix covers, by the code their fixture titles carry. */
export const FORM_PLUGINS = {
  CF7: 'Contact Form 7',
  WPF: 'WPForms',
  GF: 'Gravity Forms',
  FF: 'Fluent Forms',
  NF: 'Ninja Forms',
  EL: 'Elementor Pro',
} as const;

export type FormPluginCode = keyof typeof FORM_PLUGINS;

let fixtures: Record<string, FormFixture> | null = null;

/** The fixture map the seed stored in the `pf_form_fixtures` option, keyed by form title. */
export function formFixtures(): Record<string, FormFixture> {
  if (fixtures) return fixtures;

  const raw = wp('option get pf_form_fixtures --format=json');
  const parsed = JSON.parse(raw || '{}') as Record<string, FormFixture>;
  if (Object.keys(parsed).length === 0) {
    throw new Error(
      'pf_form_fixtures is empty on the site: the form fixture matrix has not been seeded (see README).'
    );
  }
  fixtures = parsed;
  return parsed;
}

/** A fixture by its title, e.g. `PF-CF7 message`. */
export function formFixture(title: string): FormFixture & { title: string; key: string } {
  const fixture = formFixtures()[title];
  if (!fixture) {
    throw new Error(`form fixture "${title}" is not seeded on the site`);
  }
  return { ...fixture, title, key: `${fixture.source}:${fixture.form_id}` };
}

/**
 * The state every form scenario starts from: the plugin and form tracking on, form debug
 * logging on, and no per-form configuration, so each form runs on its computed defaults.
 */
export function applyFormTracking(): void {
  wpEval(`
    $o = get_option("pixelflow_general_options", array());
    $o["enabled"] = 1;
    $o["forms_enabled"] = 1;
    $o["forms_debug_enabled"] = 1;
    update_option("pixelflow_general_options", $o);
    delete_option("pixelflow_form_settings");
  `);
}

/**
 * Forgets every form repeat window and held recipe on the site, so a scenario is not refused
 * as a repeat of the previous one. A visitor without a visitor cookie is keyed by IP, and every
 * scenario in a run reaches the site from the same address.
 */
export function resetFormState(): void {
  wpEval(`
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pf_form_dedupe_%' OR option_name LIKE '_transient_timeout_pf_form_dedupe_%' OR option_name LIKE '_transient_pf_held_forms_%' OR option_name LIKE '_transient_timeout_pf_held_forms_%'");
    wp_cache_flush();
  `);
}

/** Form entries of the log, beacons excluded. */
export function formRecords(records: EventRecord[], formKey?: string): FormRecord[] {
  return (records as FormRecord[]).filter(
    (record) =>
      (record.hook ?? '').startsWith('FORM ') &&
      !(record.hook ?? '').startsWith('FORM BLOCKED_EVENTS') &&
      (formKey === undefined || record.form === formKey)
  );
}

/** Form entries with the given outcome. */
export function formOutcomes(
  records: EventRecord[],
  formKey: string,
  outcome: FormRecord['outcome']
): FormRecord[] {
  return formRecords(records, formKey).filter((record) => record.outcome === outcome);
}

/** Blocked-events beacons a form sent. */
export function formBeacons(records: EventRecord[], formKey: string): FormRecord[] {
  return (records as FormRecord[]).filter(
    (record) => (record.hook ?? '').startsWith('FORM BLOCKED_EVENTS') && record.form === formKey
  );
}

/** Waits until the form has `count` entries with the outcome. */
export function waitForFormOutcome(
  formKey: string,
  outcome: FormRecord['outcome'],
  count = 1,
  timeoutMs = 45_000
): Promise<EventRecord[]> {
  return waitForRecords((records) => formOutcomes(records, formKey, outcome).length >= count, {
    description: `${count} "${outcome}" entr(ies) for form ${formKey}`,
    timeoutMs,
  });
}

/** Waits until the form has sent `count` blocked-events beacons. */
export function waitForFormBeacon(formKey: string, count = 1, timeoutMs = 45_000): Promise<EventRecord[]> {
  return waitForRecords((records) => formBeacons(records, formKey).length >= count, {
    description: `${count} blocked-events beacon(s) for form ${formKey}`,
    timeoutMs,
  });
}

/** The hash a normalised email is sent as. */
export function hashedEmail(email: string): string {
  return createHash('sha256').update(email.trim().toLowerCase()).digest('hex');
}

/** Whether a string appears anywhere in the raw debug log. */
export function logContains(text: string): boolean {
  return readDebugLog().includes(text);
}
