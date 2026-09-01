/**
 * @fileoverview Integration tests for AdvancedSettings — Refresh button (T-002)
 * @description Covers AC-1, AC-2, AC-3, AC-6, AC-7 from prd.md, per
 * design.md's suggested seam: integration tests on AdvancedSettings that mock
 * the RTK Query hook `useLazyGetDebugLogQuery` (real shape verified in
 * `app/source/src/features/settings/api/index.ts`: a tuple whose first
 * element is the trigger function, called as
 * `triggerGetDebugLog(undefined, false).unwrap()`) to control resolved /
 * rejected values across the initial load and a subsequent Refresh click.
 *
 * `useSettingsContext` is mocked directly rather than wrapped
 * in a real `SettingsProvider`, since this feature does not touch settings
 * state — only the debug log fetch/modal wiring is under test.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { AdvancedSettings } from '@/features/settings/components/AdvancedSettings';
import type { PixelFlowGeneralOptions } from '@/features/settings/types/settings.types.ts';

const mockTriggerGetDebugLog = vi.fn();
const mockClearDebugLogMutation = vi.fn();

vi.mock('@/features/settings/api', () => ({
  useLazyGetDebugLogQuery: () => [mockTriggerGetDebugLog, {}],
  useClearDebugLogMutation: () => [mockClearDebugLogMutation, {}],
}));

const generalOptions: PixelFlowGeneralOptions = {
  enabled: 1,
  woo_enabled: 1,
  woo_disable_add_to_cart: 0,
  woo_disable_initiate_checkout: 0,
  woo_disable_purchase: 0,
  woo_disable_add_to_cart_freebies: 0,
  woo_disable_initiate_checkout_freebies: 0,
  woo_disable_purchase_freebies: 0,
  excluded_user_roles: [],
  woo_excluded_skus: [],
  woo_product_id_format: 'product_id',
  remove_on_uninstall: 0,
  woo_debug_enabled: 1,
};

vi.mock('@/features/settings/contexts/useSettingsContext.ts', () => ({
  useSettingsContext: () => ({
    generalOptions,
    availableRoles: [],
    toggleExcludedRole: vi.fn(),
    saveSettings: vi.fn(),
    isSaving: false,
    updateGeneralOption: vi.fn(),
    isWooCommerceActive: true,
    wooDebugLogUrl: 'https://example.test/wp-content/uploads/pixelflow-woo-debug.log',
  }),
}));

/** Deferred promise helper to control fetch resolution timing across a test. */
function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

async function openLogModalWithInitialContent(content: string) {
  mockTriggerGetDebugLog.mockReturnValueOnce({
    unwrap: () => Promise.resolve({ content }),
  });
  fireEvent.click(screen.getByRole('button', { name: /see logs/i }));
  await waitFor(() => screen.getByText(content));
}

describe('AdvancedSettings — Debug Log Refresh (T-002)', () => {
  beforeEach(() => {
    mockTriggerGetDebugLog.mockReset();
    mockClearDebugLogMutation.mockReset();
  });

  it('AC-1: clicking "Refresh" triggers a new fetch of the log file', async () => {
    render(<AdvancedSettings />);
    await openLogModalWithInitialContent('initial log content');

    mockTriggerGetDebugLog.mockReturnValueOnce({
      unwrap: () => Promise.resolve({ content: 'refreshed log content' }),
    });
    fireEvent.click(screen.getByRole('button', { name: /^refresh$/i }));

    await waitFor(() => expect(mockTriggerGetDebugLog).toHaveBeenCalledTimes(2));
  });

  it('AC-2: while the refresh fetch is in progress, the popup shows "Loading…" and hides the previous content', async () => {
    render(<AdvancedSettings />);
    await openLogModalWithInitialContent('initial log content');

    const pending = deferred<{ content: string }>();
    mockTriggerGetDebugLog.mockReturnValueOnce({ unwrap: () => pending.promise });
    fireEvent.click(screen.getByRole('button', { name: /^refresh$/i }));

    await waitFor(() => expect(screen.getByText('Loading…')).toBeInTheDocument());
    expect(screen.queryByText('initial log content')).not.toBeInTheDocument();

    // Clean up the pending promise so it doesn't leak into other tests.
    pending.resolve({ content: 'refreshed log content' });
    await waitFor(() => screen.getByText('refreshed log content'));
  });

  it('AC-3: when the refresh fetch succeeds, the popup displays the newly fetched log content', async () => {
    render(<AdvancedSettings />);
    await openLogModalWithInitialContent('initial log content');

    mockTriggerGetDebugLog.mockReturnValueOnce({
      unwrap: () => Promise.resolve({ content: 'newly fetched content' }),
    });
    fireEvent.click(screen.getByRole('button', { name: /^refresh$/i }));

    await waitFor(() => screen.getByText('newly fetched content'));
    expect(screen.queryByText('initial log content')).not.toBeInTheDocument();
  });

  it('AC-6: if the refreshed log file is empty, the popup shows "Log file is empty."', async () => {
    render(<AdvancedSettings />);
    await openLogModalWithInitialContent('initial log content');

    mockTriggerGetDebugLog.mockReturnValueOnce({
      unwrap: () => Promise.resolve({ content: '' }),
    });
    fireEvent.click(screen.getByRole('button', { name: /^refresh$/i }));

    await waitFor(() => screen.getByText('Log file is empty.'));
    expect(screen.queryByText('initial log content')).not.toBeInTheDocument();
  });

  it('AC-7: if the refresh fetch fails (CUSTOM_ERROR-shaped rejection), the popup shows the error and hides previous content', async () => {
    render(<AdvancedSettings />);
    await openLogModalWithInitialContent('initial log content');

    mockTriggerGetDebugLog.mockReturnValueOnce({
      unwrap: () => Promise.reject({ error: 'Failed to fetch log file' }),
    });
    fireEvent.click(screen.getByRole('button', { name: /^refresh$/i }));

    await waitFor(() => screen.getByText('Failed to fetch log file'));
    expect(screen.queryByText('initial log content')).not.toBeInTheDocument();
  });

  it('AC-7: if the refresh fetch fails (generic rejection with no .error), the popup falls back to the default error message', async () => {
    render(<AdvancedSettings />);
    await openLogModalWithInitialContent('initial log content');

    mockTriggerGetDebugLog.mockReturnValueOnce({
      unwrap: () => Promise.reject(new Error('network down')),
    });
    fireEvent.click(screen.getByRole('button', { name: /^refresh$/i }));

    await waitFor(() => screen.getByText('Failed to load log file'));
    expect(screen.queryByText('initial log content')).not.toBeInTheDocument();
  });
});
