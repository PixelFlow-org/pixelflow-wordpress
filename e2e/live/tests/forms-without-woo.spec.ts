/**
 * Form tracking on a site without WooCommerce — the common case for this feature. WooCommerce
 * is deactivated for this file only and reactivated afterwards whatever the outcome.
 */
import { test, expect } from '../fixtures';
import { FormPage } from '../pages/form-page';
import { readEventRecords, waitForRecords } from '../helpers/debug-log';
import {
  applyFormTracking,
  formFixture,
  formOutcomes,
  formRecords,
  resetFormState,
  waitForFormOutcome,
} from '../helpers/forms';
import { wp } from '../helpers/ssh';

const FORM = 'PF-CF7 message';

test.beforeAll(() => {
  applyFormTracking();
  wp('plugin deactivate woocommerce');
});

test.afterAll(() => {
  wp('plugin activate woocommerce');
});

test.beforeEach(() => {
  resetFormState();
  expect(wp('plugin is-active woocommerce && echo active || echo inactive', { check: false })).toBe(
    'inactive'
  );
});

test.describe('without WooCommerce, consent granted', () => {
  test('the flush script loads and a submission sends one event', async ({ page }) => {
    const fixture = formFixture(FORM);
    const form = new FormPage(page);
    await form.open(fixture.url);

    await expect(
      page.locator('script[src*="held-form-events.js"]'),
      'the form flush script is not on the page'
    ).toHaveCount(1);

    await form.fill({ Email: 'pf-form-nowoo@example.test', Message: 'No store here' });
    await form.submit();

    const records = await waitForFormOutcome(fixture.key, 'sent');
    expect(formOutcomes(records, fixture.key, 'sent')).toHaveLength(1);
  });
});

test.describe('without WooCommerce, banner unanswered', () => {
  test.use({ consent: 'undecided' });

  test('a submission is held and sent once after the grant', async ({ page, consentBanner }) => {
    const fixture = formFixture(FORM);
    const form = new FormPage(page);
    await form.open(fixture.url);
    await form.fill({ Email: 'pf-form-nowoo-hold@example.test', Message: 'Held without a store' });
    await form.submit();

    const held = formOutcomes(await waitForFormOutcome(fixture.key, 'held'), fixture.key, 'held')[0];
    const eventId = String(held.payload.eventData?.event_id ?? '');
    expect(formOutcomes(readEventRecords(), fixture.key, 'sent')).toHaveLength(0);

    await consentBanner.accept();

    const records = await waitForRecords(
      (all) =>
        formRecords(all).filter(
          (record) => record.outcome === 'sent' && record.payload.eventData?.event_id === eventId
        ).length >= 1,
      { description: `the held form event ${eventId} sent after the grant`, timeoutMs: 60_000 }
    );
    const sent = formRecords(records).filter(
      (record) => record.outcome === 'sent' && record.payload.eventData?.event_id === eventId
    );
    expect(sent).toHaveLength(1);
    expect(sent[0].payload.eventData?.eventTime).toBe(held.payload.eventData?.eventTime);
  });
});
