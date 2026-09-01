/**
 * @fileoverview Component tests for LogViewerModal — Refresh button (T-002)
 * @description Covers AC-1, AC-4, AC-5, AC-8 from prd.md, per design.md's
 * suggested seam: component tests on LogViewerModal, mocking onRefresh /
 * onOpenChange, since the modal itself has no internal fetch logic (that
 * lives in AdvancedSettings — see AdvancedSettings.test.tsx for AC-2, AC-3,
 * AC-6, AC-7, which require asserting real fetch re-invocation).
 *
 * `onRefresh` is a REQUIRED prop per design.md's `LogViewerModalProps`, so it
 * is passed in every render below even though some tests do not assert on it
 * directly.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import LogViewerModal from '@/features/settings/components/LogViewerModal';

describe('LogViewerModal — Refresh button (T-002)', () => {
  it('AC-1: clicking "Refresh" invokes the onRefresh callback', () => {
    const onRefresh = vi.fn();
    render(
      <LogViewerModal
        open
        onOpenChange={vi.fn()}
        content="some log content"
        isLoading={false}
        onRefresh={onRefresh}
      />
    );

    fireEvent.click(screen.getByText('Refresh'));

    expect(onRefresh).toHaveBeenCalledTimes(1);
  });

  it('AC-4: the "Refresh" button is positioned before the "Close" button in the footer', () => {
    render(
      <LogViewerModal
        open
        onOpenChange={vi.fn()}
        content="some log content"
        isLoading={false}
        onRefresh={vi.fn()}
      />
    );

    // The header's icon-only close button has no text content ("✕"), so
    // matching on exact textContent 'Close' isolates the footer button.
    const buttonTexts = screen.getAllByRole('button').map((b) => b.textContent);
    const refreshIndex = buttonTexts.indexOf('Refresh');
    const closeIndex = buttonTexts.indexOf('Close');

    expect(refreshIndex).toBeGreaterThanOrEqual(0);
    expect(closeIndex).toBeGreaterThanOrEqual(0);
    expect(refreshIndex).toBeLessThan(closeIndex);
  });

  it('AC-5: the "Refresh" button is disabled while a fetch is in progress (isLoading=true)', () => {
    render(<LogViewerModal open onOpenChange={vi.fn()} content="" isLoading onRefresh={vi.fn()} />);

    expect(screen.getByText('Refresh')).toBeDisabled();
  });

  it('AC-8: clicking "Close" still calls onOpenChange(false), unaffected by the Refresh addition', () => {
    // Green-by-design: this pins pre-existing Close behavior, unchanged by
    // this feature — Close's onClick wiring is untouched per design.md.
    const onOpenChange = vi.fn();
    render(
      <LogViewerModal
        open
        onOpenChange={onOpenChange}
        content="some log content"
        isLoading={false}
        onRefresh={vi.fn()}
      />
    );

    fireEvent.click(screen.getByText('Close'));

    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});
