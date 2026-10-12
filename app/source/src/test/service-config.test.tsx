/**
 * @fileoverview Service addresses from the installed build
 * @description The plugin inlines `window.pixelflowSettings.config` before the bundle runs. The
 * app passes it to plugin-core, and every "open the dashboard" link uses its dashboard origin.
 */
import { describe, it, expect, vi, beforeAll } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { getApiBaseUrl, getCdnUrl, getUiBaseUrl } from '@pixelflow-org/plugin-core';
import { platformConfig } from '@/config/platform.config';
import Header from '@/shared/components/Header/Header';
import StartSetupModal from '@/features/home/components/start-setup-modal';
import { FormsSettings } from '@/features/forms/components/FormsSettings';
import type { FormsListing } from '@/features/forms/types';

const CONFIG = vi.hoisted(() => {
  const config = {
    apiBaseUrl: 'https://api.example.test',
    uiBaseUrl: 'https://dashboard.example.test',
    cdnUrl: 'https://cdn.example.test/pfm.js',
  };
  // Inlined by PHP before the bundle, so it exists before any module of the app loads.
  (window as unknown as { pixelflowSettings: unknown }).pixelflowSettings = {
    general_options: {},
    nonce: 'nonce',
    ajax_url: 'https://example.test/wp-admin/admin-ajax.php',
    is_woocommerce_active: false,
    config,
  };
  return config;
});

const DASHBOARD = 'https://dashboard.example.test/dashboard/overview';

// The entry point is imported for its configuration call only; it never renders here.
vi.mock('@/App', () => ({ App: () => null }));

const mockLoadForms = vi.fn();

vi.mock('@/features/forms/api', () => ({
  useLazyGetFormsQuery: () => [mockLoadForms, {}],
  useSaveFormSettingsMutation: () => [vi.fn(), {}],
}));

vi.mock('@/features/settings/contexts/useSettingsContext.ts', () => ({
  useSettingsContext: () => ({
    generalOptions: { enabled: 1, forms_enabled: 1, forms_debug_enabled: 0 },
    updateGeneralOption: vi.fn(),
    saveSettings: vi.fn(),
    isSaving: false,
    wooDebugLogUrl: '',
  }),
}));

beforeAll(async () => {
  // The entry point configures plugin-core; with no #pixelflowroot it renders nothing.
  await import('@/main');
});

describe('Service addresses', () => {
  it('passes the configured addresses to plugin-core', () => {
    expect(getApiBaseUrl()).toBe(CONFIG.apiBaseUrl);
    expect(getUiBaseUrl()).toBe(CONFIG.uiBaseUrl);
    expect(getCdnUrl()).toBe(CONFIG.cdnUrl);
  });

  it('gives the platform adapter the configured addresses', () => {
    expect(platformConfig).toMatchObject(CONFIG);
  });

  it('points the header dashboard link at the configured dashboard', () => {
    render(<Header selectedCurrency="USD" updateCurrency={vi.fn()} user={null} />);

    expect(screen.getByRole('link', { name: /Go to Dashboard/ })).toHaveAttribute(
      'href',
      DASHBOARD
    );
  });

  it('opens the configured dashboard from the setup modal', async () => {
    const open = vi.spyOn(window, 'open').mockImplementation(() => null);
    render(<StartSetupModal open onOpenChange={vi.fn()} />);

    await userEvent.setup().click(screen.getByRole('button', { name: 'Start Setup' }));

    expect(open).toHaveBeenCalledWith(DASHBOARD, '_blank');
    open.mockRestore();
  });

  it('points the forms double-count link at the configured dashboard', async () => {
    const listing: FormsListing = {
      plugins: [{ id: 'cf7', label: 'Contact Form 7', active: true }],
      events: ['Lead'],
      identifiers: ['em'],
      forms: [],
    };
    mockLoadForms.mockReturnValue({ unwrap: () => Promise.resolve(listing) });
    render(<FormsSettings />);
    await waitFor(() => expect(mockLoadForms).toHaveBeenCalled());

    const warning = await screen.findByTestId('forms-double-count-warning');
    expect(within(warning).getByRole('link')).toHaveAttribute('href', DASHBOARD);
  });
});
