/**
 * @fileoverview "Only the customer's first purchase" controls
 * @description Defaults, disabled states and the day-count validation of the settings group.
 * The settings context is mocked; the component and the parser are real.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { TooltipProvider } from '@pixelflow-org/plugin-ui';
import { FirstPurchaseSettings } from '@/features/settings/components/FirstPurchaseSettings';
import { parseWholeDays } from '@/features/settings/utils/first-purchase';

const DAYS_ERROR = 'Enter a whole number of days, 1 or more';

const settings = {
  generalOptions: {} as Record<string, unknown>,
  updateGeneralOption: vi.fn(),
  saveSettings: vi.fn(),
  isSaving: false,
};

vi.mock('@/features/settings/contexts/useSettingsContext.ts', () => ({
  useSettingsContext: () => settings,
}));

const DEFAULTS = {
  woo_disable_purchase: 0,
  woo_purchase_first_only: 0,
  woo_purchase_first_only_lookback: 'all',
  woo_purchase_first_only_days: 60,
  woo_purchase_first_only_ignore_free: 1,
};

function renderWith(overrides: Record<string, unknown> = {}) {
  settings.generalOptions = { ...DEFAULTS, ...overrides };
  return render(
    <TooltipProvider>
      <FirstPurchaseSettings />
    </TooltipProvider>
  );
}

function switchFor(id: string): HTMLElement {
  const el = document.getElementById(id);
  if (!el) throw new Error(`no element #${id}`);
  return el;
}

beforeEach(() => {
  settings.updateGeneralOption.mockReset();
  settings.saveSettings.mockReset();
  settings.saveSettings.mockResolvedValue(undefined);
  settings.isSaving = false;
});

describe('parseWholeDays', () => {
  it.each([
    ['60', 60],
    [' 007 ', 7],
    ['1', 1],
  ])('accepts %j as %j', (raw, expected) => {
    expect(parseWholeDays(raw)).toBe(expected);
  });

  it.each(['', '0', '000', '-5', '7.5', '7.0', '1e3', 'abc', ' '])('rejects %j', (raw) => {
    expect(parseWholeDays(raw)).toBeNull();
  });

  it('keeps a count too large for a JS number as a digit string', () => {
    expect(parseWholeDays('99999999999999999999')).toBe('99999999999999999999');
  });
});

describe('FirstPurchaseSettings', () => {
  it('shows the defaults: switch off, all time, ignore-free on', () => {
    renderWith();

    expect(switchFor('woo_purchase_first_only')).toHaveAttribute('aria-checked', 'false');
    expect(screen.getByText("Only the customer's first purchase")).toBeInTheDocument();
    expect(switchFor('woo_purchase_first_only_lookback')).toHaveTextContent('All time');
    expect(switchFor('woo_purchase_first_only_ignore_free')).toHaveAttribute(
      'aria-checked',
      'true'
    );
    expect(document.getElementById('woo_purchase_first_only_days')).toBeNull();
  });

  it('disables the sub-controls while the switch is off', () => {
    renderWith();

    expect(switchFor('woo_purchase_first_only')).not.toBeDisabled();
    expect(switchFor('woo_purchase_first_only_lookback')).toBeDisabled();
    expect(switchFor('woo_purchase_first_only_ignore_free')).toBeDisabled();
  });

  it('disables the whole group while Purchase is disabled, keeping the saved values', () => {
    renderWith({ woo_disable_purchase: 1, woo_purchase_first_only: 1 });

    expect(switchFor('woo_purchase_first_only')).toBeDisabled();
    expect(switchFor('woo_purchase_first_only')).toHaveAttribute('aria-checked', 'true');
    expect(switchFor('woo_purchase_first_only_lookback')).toBeDisabled();
    expect(switchFor('woo_purchase_first_only_ignore_free')).toBeDisabled();
  });

  it('saves the switch when it is turned on', async () => {
    renderWith();

    fireEvent.click(switchFor('woo_purchase_first_only'));

    await waitFor(() =>
      expect(settings.saveSettings).toHaveBeenCalledWith({
        generalOptionsOverride: { woo_purchase_first_only: 1 },
      })
    );
  });

  it.each(['', '0', '-5', '7.5', '7.0', '1e3'])(
    'shows the message and saves nothing for %j',
    async (raw) => {
      renderWith({ woo_purchase_first_only: 1, woo_purchase_first_only_lookback: 'days' });
      const input = switchFor('woo_purchase_first_only_days');

      fireEvent.change(input, { target: { value: raw } });
      fireEvent.blur(input);

      expect(await screen.findByText(DAYS_ERROR)).toBeInTheDocument();
      expect(input).toHaveAttribute('aria-invalid', 'true');
      expect(settings.saveSettings).not.toHaveBeenCalled();
    }
  );

  it('saves " 007 " as 7 on Enter', async () => {
    renderWith({ woo_purchase_first_only: 1, woo_purchase_first_only_lookback: 'days' });
    const input = switchFor('woo_purchase_first_only_days');

    fireEvent.change(input, { target: { value: ' 007 ' } });
    fireEvent.keyDown(input, { key: 'Enter' });

    await waitFor(() =>
      expect(settings.saveSettings).toHaveBeenCalledWith({
        generalOptionsOverride: { woo_purchase_first_only_days: 7 },
      })
    );
    expect(screen.queryByText(DAYS_ERROR)).toBeNull();
  });

  it('does not save an unchanged day count', async () => {
    renderWith({
      woo_purchase_first_only: 1,
      woo_purchase_first_only_lookback: 'days',
      woo_purchase_first_only_days: 90,
    });
    const input = switchFor('woo_purchase_first_only_days');

    fireEvent.blur(input);

    await waitFor(() => expect(screen.queryByText(DAYS_ERROR)).toBeNull());
    expect(settings.saveSettings).not.toHaveBeenCalled();
  });
});
