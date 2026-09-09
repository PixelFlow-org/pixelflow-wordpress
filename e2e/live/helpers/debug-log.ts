/**
 * Reading the plugin's WooCommerce debug log off the test site.
 *
 * The plugin appends one pretty-printed JSON object per event, separated by
 * `\n---\n` (see pixelflow_write_debug_log_entry). The log is truncated before
 * every scenario, so whatever is in it afterwards belongs to that scenario.
 */
import { ssh, wp } from './ssh';

export interface EventContent {
  id?: string | number;
  quantity?: number;
  item_price?: number;
}

export interface AdditionalData {
  value?: number;
  currency?: string;
  num_items?: number;
  content_ids?: Array<string | number>;
  contents?: EventContent[];
  [key: string]: unknown;
}

export interface CustomerData {
  ct?: string;
  st?: string;
  zp?: string;
  country?: string;
  [key: string]: unknown;
}

/** One suppressed event as the blocked-events beacon reports it. */
export interface BlockedEntry {
  eventType?: string;
  reason?: string;
  detail?: string;
  consentSource?: string;
  [key: string]: unknown;
}

export interface EventRecord {
  time: string;
  version: string;
  hook: string;
  payload: {
    siteId?: string;
    /** Present on blocked-events beacons instead of `eventData`. */
    blocked?: BlockedEntry[];
    client_ip_address?: string;
    eventData?: {
      eventName?: string;
      eventTime?: number;
      additionalData?: AdditionalData;
      customerData?: CustomerData;
      [key: string]: unknown;
    };
  };
  response?: unknown;
  cookies?: Record<string, string>;
  server?: Record<string, string>;
}

let cachedPath: string | null = null;

/** Absolute path of the debug log, derived from the site's own pixelflow_debug_log_key option. */
export function debugLogPath(): string {
  if (cachedPath) return cachedPath;

  const key = wp('option get pixelflow_debug_log_key').trim();
  if (!key) {
    throw new Error(
      'pixelflow_debug_log_key is not set on the site — the plugin has not generated a debug log key yet.'
    );
  }
  cachedPath = `wp-content/pixelflow_debug_${key}.log`;
  return cachedPath;
}

/** Empties the log so the next scenario starts from a known-empty file. */
export function truncateDebugLog(): void {
  ssh(`: > ${debugLogPath()}`);
}

/** Raw log contents; empty string when the file does not exist yet. */
export function readDebugLog(): string {
  return ssh(`cat ${debugLogPath()} 2>/dev/null || true`, { check: false });
}

/** Parses the log into event records, skipping any trailing partial write. */
export function readEventRecords(): EventRecord[] {
  const raw = readDebugLog();
  if (!raw.trim()) return [];

  return raw
    .split('\n---\n')
    .map((chunk) => chunk.trim())
    .filter(Boolean)
    .flatMap((chunk) => {
      try {
        return [JSON.parse(chunk) as EventRecord];
      } catch {
        // A record still being written; the polling read will pick it up next round.
        return [];
      }
    });
}

export function eventName(record: EventRecord): string {
  return record.payload?.eventData?.eventName ?? 'unknown';
}

export function additionalData(record: EventRecord): AdditionalData {
  return record.payload?.eventData?.additionalData ?? {};
}

export function customerData(record: EventRecord): CustomerData {
  return record.payload?.eventData?.customerData ?? {};
}

export function recordsNamed(records: EventRecord[], name: string): EventRecord[] {
  return records.filter((record) => eventName(record) === name);
}

/**
 * True when the record describes an event that actually left the site.
 *
 * The debug log records the *attempt*, not only the send: a held or skipped event is
 * written with its full `eventData` and a `response` explaining what happened to it
 * ("EVENT SENDING HELD UNTIL…", "EVENT SENDING SKIPPED (denied)…"). A real dispatch is
 * the only case whose response is the transport's own summary object, carrying `code`.
 * Counting records by name alone therefore reads a correctly held event as a leak.
 */
export function wasDispatched(record: EventRecord): boolean {
  const response = record.response;
  return typeof response === 'object' && response !== null && 'code' in (response as object);
}

/** Records for one event name that actually reached the API. */
export function sentRecords(records: EventRecord[], name: string): EventRecord[] {
  return recordsNamed(records, name).filter(wasDispatched);
}

/** Records for one event name that the plugin held for a consent decision. */
export function heldRecords(records: EventRecord[], name: string): EventRecord[] {
  return recordsNamed(records, name).filter(
    (record) => typeof record.response === 'string' && /HELD/i.test(record.response)
  );
}

/** Records for one event name that the plugin skipped outright. */
export function skippedRecords(records: EventRecord[], name: string): EventRecord[] {
  return recordsNamed(records, name).filter(
    (record) => typeof record.response === 'string' && /SKIPPED/i.test(record.response)
  );
}

/**
 * Blocked-events beacons for one event name.
 *
 * These carry no `eventData` at all — their payload is `{siteId, blocked: [...]}`
 * plus a client IP — so `recordsNamed()` cannot see them. Filtering on the
 * record's `hook` instead of extending `recordsNamed()` keeps every existing
 * call site unambiguous about which kind of record it is counting.
 */
export function blockedRecords(records: EventRecord[], eventNameOrAll?: string): EventRecord[] {
  const wanted = eventNameOrAll ? `BLOCKED_EVENTS ${eventNameOrAll}` : 'BLOCKED_EVENTS ';
  return records.filter((record) =>
    eventNameOrAll ? record.hook === wanted : (record.hook ?? '').startsWith(wanted)
  );
}

/** The suppressed entries a blocked-events record reports. */
export function blockedEntries(record: EventRecord): BlockedEntry[] {
  return record.payload?.blocked ?? [];
}

/** Waits for exactly `count` blocked-events beacons for the given event name. */
export function waitForBlocked(
  name: string,
  count = 1,
  options: Partial<WaitOptions> = {}
): Promise<EventRecord[]> {
  return waitForRecords((records) => blockedRecords(records, name).length >= count, {
    description: `${count} blocked-events record(s) for ${name}`,
    ...options,
  });
}

/**
 * Fields that would identify the visitor. A blocked beacon exists to tell the
 * API that an event was suppressed — it must not smuggle the visitor out with
 * the news, so anything resembling identity is a defect, wherever it sits in
 * the payload.
 */
const IDENTIFIER_KEYS = [
  'em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id', 'fbp', 'fbc',
  'client_user_agent', 'customerData', 'userData', 'eventData', 'attribution', 'utm',
];

/**
 * Asserts a blocked payload carries no visitor identity.
 *
 * `client_ip_address` is deliberately allowed: the API reads it to tell whether the
 * visitor is in an opt-in or an opt-out region, and neither stores nor forwards it.
 * The plugin attaches it only when the address is public.
 */
export function expectNoIdentifiers(record: EventRecord): void {
  const payload = record.payload as Record<string, unknown>;
  const offenders: string[] = [];

  const walk = (value: unknown, path: string): void => {
    if (value === null || typeof value !== 'object') return;
    for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
      const here = path ? `${path}.${key}` : key;
      if (IDENTIFIER_KEYS.includes(key)) offenders.push(here);
      walk(child, here);
    }
  };
  walk(payload, '');

  if (offenders.length > 0) {
    throw new Error(
      `Blocked payload for "${record.hook}" carries visitor identity: ${offenders.join(', ')}. ` +
        `Payload was ${JSON.stringify(payload)}.`
    );
  }
}

/**
 * Product identifiers the record reports. AddToCart carries them only inside
 * `contents`; the cart-level events also emit a flat `content_ids`.
 */
export function contentIds(record: EventRecord): string[] {
  const data = additionalData(record);
  const fromContents = (data.contents ?? []).map((item) => String(item.id));
  const fromIds = (data.content_ids ?? []).map(String);
  return [...new Set([...fromContents, ...fromIds])];
}

/** True when the record reports the given product. */
export function mentionsProduct(record: EventRecord, productId: number): boolean {
  return contentIds(record).includes(String(productId));
}

export interface WaitOptions {
  timeoutMs?: number;
  pollMs?: number;
  /** Describes what was expected; used in the failure message. */
  description: string;
}

/**
 * Waits for the log to satisfy `predicate`. Events are dispatched from PHP after
 * the browser request returns, so a single read can race the write.
 */
export async function waitForRecords(
  predicate: (records: EventRecord[]) => boolean,
  options: WaitOptions
): Promise<EventRecord[]> {
  const { timeoutMs = 20_000, pollMs = 1_000, description } = options;
  const deadline = Date.now() + timeoutMs;
  let records: EventRecord[] = [];

  for (;;) {
    records = readEventRecords();
    if (predicate(records)) return records;
    if (Date.now() >= deadline) break;
    await new Promise((resolve) => setTimeout(resolve, pollMs));
  }

  const seen = records.length
    ? records.map((r) => `${eventName(r)} (${r.hook})`).join(', ')
    : 'nothing';
  throw new Error(
    `Timed out after ${timeoutMs}ms waiting for ${description}. Log contained: ${seen}.`
  );
}

/** Waits for exactly `count` records of the given event name. */
export function waitForEvent(
  name: string,
  count = 1,
  options: Partial<WaitOptions> = {}
): Promise<EventRecord[]> {
  return waitForRecords((records) => sentRecords(records, name).length >= count, {
    description: `${count} dispatched ${name} record(s)`,
    ...options,
  });
}

/** Waits for the plugin to record that it held `count` events of this name. */
export function waitForHeld(
  name: string,
  count = 1,
  options: Partial<WaitOptions> = {}
): Promise<EventRecord[]> {
  return waitForRecords((records) => heldRecords(records, name).length >= count, {
    description: `${count} held ${name} record(s)`,
    ...options,
  });
}

/**
 * Asserts no real event was logged, allowing blocked-events beacons.
 *
 * A decline is expected to leave a trace: no tracked event, one anonymous
 * blocked row. `expectNoEvents()` cannot express that, because it treats any
 * record as a failure.
 */
export async function expectNoTrackedEvents(settleMs = 6_000): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, settleMs));
  const sent = readEventRecords().filter(
    (record) => record.payload?.eventData !== undefined && wasDispatched(record)
  );
  if (sent.length > 0) {
    const seen = sent.map((r) => `${eventName(r)} (${r.hook})`).join(', ');
    throw new Error(`Expected no event to be sent, but these were dispatched: ${seen}.`);
  }
}

/**
 * Asserts no event was logged. Waits out the dispatch window first, otherwise
 * an event that simply had not been written yet would read as an absence.
 */
export async function expectNoEvents(settleMs = 6_000): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, settleMs));
  const records = readEventRecords();
  if (records.length > 0) {
    const seen = records.map((r) => `${eventName(r)} (${r.hook})`).join(', ');
    throw new Error(`Expected no events to be logged, but found: ${seen}.`);
  }
}
