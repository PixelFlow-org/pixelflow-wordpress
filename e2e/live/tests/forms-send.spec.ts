/**
 * A real form submission, with consent granted, becomes one server-side event in every
 * supported form plugin: the hashed email and the form title, never the message text.
 */
import { test, expect } from '../fixtures';
import { FormPage } from '../pages/form-page';
import { readEventRecords } from '../helpers/debug-log';
import {
  FORM_PLUGINS,
  applyFormTracking,
  resetFormState,
  formFixture,
  formOutcomes,
  hashedEmail,
  logContains,
  waitForFormOutcome,
  type FormPluginCode,
} from '../helpers/forms';

test.beforeAll(() => {
  applyFormTracking();
});

test.beforeEach(() => {
  resetFormState();
});

for (const code of Object.keys(FORM_PLUGINS) as FormPluginCode[]) {
  test(`${FORM_PLUGINS[code]}: a submitted form sends one Lead with the hashed email and no message text`, async ({
    page,
  }) => {
    const fixture = formFixture(`PF-${code} message`);
    const email = `pf-form-${code.toLowerCase()}@example.test`;
    const secret = `PF-SECRET-${Date.now()}`;

    const form = new FormPage(page);
    await form.open(fixture.url);
    await form.fill({ Email: email, Message: `Please call me back ${secret}` });
    await form.submit();

    const records = await waitForFormOutcome(fixture.key, 'sent');
    const sent = formOutcomes(records, fixture.key, 'sent');
    expect(sent, 'the submission was sent more than once').toHaveLength(1);

    const eventData = sent[0].payload.eventData ?? {};
    expect(eventData.eventName).toBe('Lead');
    expect(eventData.additionalData?.contentName).toBe(fixture.title);
    expect(eventData.customerData?.em, 'the email was not sent as its hash').toBe(hashedEmail(email));
    expect(eventData.additionalData, 'a currency was sent with a form event').not.toHaveProperty(
      'currency'
    );

    expect(logContains(secret), 'the message text reached the debug log').toBe(false);
    expect(logContains(email), 'the raw email reached the debug log').toBe(false);
    expect(
      formOutcomes(readEventRecords(), fixture.key, 'held'),
      'a granted submission was held'
    ).toHaveLength(0);
  });
}

// Every form plugin reaches the same dispatcher, so one plugin covers the location fallback.
test('A form with no address fields takes city, state, postcode and country from pf_loc', async ({
  page,
  context,
}) => {
  const fixture = formFixture('PF-CF7 message');

  const form = new FormPage(page);
  await form.open(fixture.url);
  // The tracking script resolves the visitor's location asynchronously before writing pf_loc.
  await expect
    .poll(async () => (await context.cookies()).some((cookie) => cookie.name === 'pf_loc'), {
      message: 'pf_loc cookie was never set on the form page',
      timeout: 30_000,
    })
    .toBe(true);
  const cookie = (await context.cookies()).find((c) => c.name === 'pf_loc')!;
  const location = JSON.parse(decodeURIComponent(cookie.value)) as Record<string, string>;

  await form.fill({ Email: 'pf-form-location@example.test', Message: 'Location check' });
  await form.submit();

  const sent = formOutcomes(await waitForFormOutcome(fixture.key, 'sent'), fixture.key, 'sent');
  expect(sent, 'the submission was sent more than once').toHaveLength(1);

  const customer = (sent[0].payload.eventData?.customerData ?? {}) as Record<string, unknown>;
  const keys = (['ct', 'st', 'zp', 'country'] as const).filter((key) => location[key]);
  expect(keys.length, 'pf_loc carried no location at all').toBeGreaterThan(0);
  for (const key of keys) {
    expect(customer[key], `customerData.${key} does not match the pf_loc cookie`).toBe(location[key]);
  }
});
