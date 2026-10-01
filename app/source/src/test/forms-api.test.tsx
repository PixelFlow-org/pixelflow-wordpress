/**
 * @fileoverview Forms API endpoints
 * @description The read and save endpoints against a stubbed admin-ajax: what they POST, how
 * they unwrap WordPress's JSON envelope, and what a refusal or a network failure becomes.
 */
import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { authApi } from '@pixelflow-org/plugin-core/dist';

const AJAX_URL = 'https://example.test/wp-admin/admin-ajax.php';
const LISTING = { plugins: [], events: ['Lead'], identifiers: ['em'], forms: [] };

type FormsApi = typeof import('@/features/forms/api');

let api: FormsApi;
const fetchMock = vi.fn();

/** A response whose body is WordPress's JSON envelope. */
function envelope(body: unknown): { json: () => Promise<unknown> } {
  return { json: () => Promise.resolve(body) };
}

/** Both hooks, mounted on a fresh store. */
function renderApi() {
  const store = configureStore({
    reducer: { [authApi.reducerPath]: authApi.reducer },
    middleware: (getDefault) => getDefault().concat(authApi.middleware),
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <Provider store={store}>{children}</Provider>
  );
  const { result } = renderHook(
    () => ({
      load: api.useLazyGetFormsQuery()[0],
      save: api.useSaveFormSettingsMutation()[0],
    }),
    { wrapper }
  );
  return result.current;
}

/** Runs one endpoint call to its end and returns what it resolved or rejected with. */
async function settle(call: () => Promise<unknown>): Promise<{ value?: unknown; error?: unknown }> {
  let outcome: { value?: unknown; error?: unknown } = {};
  await act(async () => {
    outcome = await call().then(
      (value) => ({ value }),
      (error) => ({ error })
    );
  });
  return outcome;
}

/** Fields of the FormData the last request carried. */
function postedFields(): Record<string, string> {
  const body = fetchMock.mock.calls[fetchMock.mock.calls.length - 1][1].body as FormData;
  return Object.fromEntries(Array.from(body.entries()).map(([key, value]) => [key, String(value)]));
}

describe('forms API', () => {
  beforeAll(async () => {
    // The ajax config is read once, when the settings module loads.
    (window as unknown as { pixelflowSettings: unknown }).pixelflowSettings = {
      nonce: 'nonce-test',
      ajax_url: AJAX_URL,
    };
    api = await import('@/features/forms/api');
  });

  beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('reads the listing with the read action and the nonce', async () => {
    fetchMock.mockResolvedValue(envelope({ success: true, data: LISTING }));

    const { load } = renderApi();
    expect(await settle(() => load(undefined, false).unwrap())).toEqual({ value: LISTING });
    expect(fetchMock.mock.calls[0][0]).toBe(AJAX_URL);
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
    expect(postedFields()).toEqual({ action: 'pixelflow_get_forms', nonce: 'nonce-test' });
  });

  it('saves a patch as JSON and returns the fresh listing', async () => {
    fetchMock.mockResolvedValue(envelope({ success: true, data: LISTING }));
    const patch = { 'cf7:12': { title: 'Contact us', event: 'Contact' } };

    const { save } = renderApi();
    expect(await settle(() => save(patch).unwrap())).toEqual({ value: LISTING });
    expect(postedFields()).toEqual({
      action: 'pixelflow_save_form_settings',
      nonce: 'nonce-test',
      forms: JSON.stringify(patch),
    });
  });

  it('turns a refused read into an error carrying the server message', async () => {
    fetchMock.mockResolvedValue(
      envelope({ success: false, data: { message: 'Unauthorized access' } })
    );

    const { load } = renderApi();
    expect((await settle(() => load(undefined, false).unwrap())).error).toMatchObject({
      status: 'CUSTOM_ERROR',
      error: 'Unauthorized access',
    });
  });

  it('falls back to its own message when a refused save carries none', async () => {
    fetchMock.mockResolvedValue(envelope({ success: false }));

    const { save } = renderApi();
    expect((await settle(() => save({}).unwrap())).error).toMatchObject({
      status: 'CUSTOM_ERROR',
      error: 'Failed to save form settings',
    });
  });

  it('turns a network failure into a fetch error', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));

    const { load } = renderApi();
    expect((await settle(() => load(undefined, false).unwrap())).error).toMatchObject({
      status: 'FETCH_ERROR',
      error: 'Failed to load forms',
    });
  });

  it('turns an answer that is not JSON into a fetch error', async () => {
    fetchMock.mockResolvedValue({ json: () => Promise.reject(new SyntaxError('Unexpected <')) });

    const { save } = renderApi();
    expect((await settle(() => save({}).unwrap())).error).toMatchObject({
      status: 'FETCH_ERROR',
      error: 'Failed to save form settings',
    });
  });
});
