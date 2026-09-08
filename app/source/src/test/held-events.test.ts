/**
 * Storefront hold-queue script (assets/js/held-events.js).
 *
 * The script has to work from a page served by a full-page cache, so it takes
 * both the queue flag and the flush nonce from the server at the moment it acts.
 */

import { readFileSync } from 'node:fs';
import * as path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const SCRIPT = readFileSync(
  path.resolve(__dirname, '../../../../assets/js/held-events.js'),
  'utf8'
);

interface HeldEventsConfig {
  stateUrl: string;
  flushUrl: string;
  holdCookie: string;
  holdValue: string;
  heldCookie: string;
}

const CONFIG: HeldEventsConfig = {
  stateUrl: 'https://shop.test/?wc-ajax=pixelflow_held_state',
  flushUrl: 'https://shop.test/?wc-ajax=pixelflow_resolve_held_events',
  holdCookie: '_pf_no_consent_decision',
  holdValue: 'true',
  heldCookie: '_pf_held_woo_events',
};

/** Loads the script into the current jsdom window. */
function loadScript(): void {
  // The file is a plain IIFE, so evaluating it is the only way to exercise it.
  new Function(SCRIPT).call(window);
}

function jsonResponse(data: unknown): Response {
  return {
    ok: true,
    json: () => Promise.resolve({ success: true, data }),
  } as unknown as Response;
}

function refusedResponse(): Response {
  return { ok: false, json: () => Promise.resolve({}) } as unknown as Response;
}

/** Runs every pending timer callback and lets queued promises settle. */
async function advance(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms);
}

describe('held-events storefront script', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    // Each case loads its own copy of the IIFE into this window; clearing the
    // clock stops the previous copy's pending timer from firing into this test.
    vi.clearAllTimers();
    document.cookie = `${CONFIG.heldCookie}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    document.cookie = `${CONFIG.holdCookie}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    (window as unknown as { pixelflowHeldEvents?: HeldEventsConfig }).pixelflowHeldEvents = {
      ...CONFIG,
    };
  });

  afterEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('flushes with a nonce minted by the server, not one baked into the page', async () => {
    document.cookie = `${CONFIG.heldCookie}=["AddToCart"];path=/`;
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ hasQueue: true, nonce: 'fresh-nonce' }))
      .mockResolvedValueOnce(jsonResponse(null));
    vi.stubGlobal('fetch', fetchMock);

    loadScript();
    await advance(10000);

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[0][0]).toBe(CONFIG.stateUrl);

    const [flushUrl, flushInit] = fetchMock.mock.calls[1];
    expect(flushUrl).toBe(CONFIG.flushUrl);
    expect(String(flushInit.body)).toContain('nonce=fresh-nonce');
  });

  it('finds a queue the server knows about even when no cookie was written', async () => {
    // No held-events cookie: the queue was created in a request whose headers had
    // already been sent, so setcookie() was skipped.
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ hasQueue: true, nonce: 'n1' }))
      .mockResolvedValueOnce(jsonResponse(null));
    vi.stubGlobal('fetch', fetchMock);

    loadScript();
    await advance(10000);

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[1][0]).toBe(CONFIG.flushUrl);
  });

  it('acts on the consent-change signal without waiting for the timer', async () => {
    document.cookie = `${CONFIG.heldCookie}=["AddToCart"];path=/`;
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ hasQueue: false, nonce: 'n1' }));
    vi.stubGlobal('fetch', fetchMock);

    loadScript();
    expect(fetchMock).not.toHaveBeenCalled();

    document.dispatchEvent(new CustomEvent('wp_listen_for_consent_change'));
    await advance(0);

    expect(fetchMock).toHaveBeenCalledWith(CONFIG.stateUrl, expect.anything());
  });

  it('waits while the banner is still unanswered', async () => {
    document.cookie = `${CONFIG.heldCookie}=["AddToCart"];path=/`;
    document.cookie = `${CONFIG.holdCookie}=${CONFIG.holdValue};path=/`;
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ hasQueue: true, nonce: 'n1' }));
    vi.stubGlobal('fetch', fetchMock);

    loadScript();
    await advance(60000);

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('catches a consent grant that emits no signal on the next poll', async () => {
    // A CMP that changes its state without announcing it through the WP Consent
    // API leaves no event to act on. The timer is the fallback that keeps such a
    // grant from stranding the queue until the shopper loads another page.
    document.cookie = `${CONFIG.heldCookie}=["AddToCart"];path=/`;
    document.cookie = `${CONFIG.holdCookie}=${CONFIG.holdValue};path=/`;
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ hasQueue: true, nonce: 'silent-nonce' }))
      .mockResolvedValueOnce(jsonResponse(null));
    vi.stubGlobal('fetch', fetchMock);

    loadScript();
    await advance(10000);
    expect(fetchMock, 'the script acted while the banner was still unanswered').not.toHaveBeenCalled();

    // The grant lands: the hold is lifted, but nothing is dispatched to say so.
    document.cookie = `${CONFIG.holdCookie}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;

    await advance(10000);

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[0][0]).toBe(CONFIG.stateUrl);
    const [flushUrl, flushInit] = fetchMock.mock.calls[1];
    expect(flushUrl).toBe(CONFIG.flushUrl);
    expect(String(flushInit.body)).toContain('nonce=silent-nonce');
  });

  it('stops calling a permanently refusing endpoint after the backoff reaches its ceiling', async () => {
    document.cookie = `${CONFIG.heldCookie}=["AddToCart"];path=/`;
    const fetchMock = vi.fn().mockResolvedValue(refusedResponse());
    vi.stubGlobal('fetch', fetchMock);

    loadScript();
    // 10s to the first refusal, then the 2s, 4s, 8s and 16s waits between the
    // remaining four. The refusal that lands at the 16s ceiling is the last.
    await advance(10000 + 2000 + 4000 + 8000 + 16000);
    const afterCeiling = fetchMock.mock.calls.length;

    await advance(600000);

    expect(afterCeiling).toBe(5);
    expect(fetchMock).toHaveBeenCalledTimes(afterCeiling);
  });
});
