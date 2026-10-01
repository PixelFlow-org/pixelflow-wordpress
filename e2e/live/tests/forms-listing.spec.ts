/**
 * Every seeded form appears on the settings page's Form Settings tab before anything is
 * submitted, with the confidence (its switch), the suggested event and the identifiers the
 * adapter detected from the real plugin's own form definition.
 */
import { test, expect, ADMIN_STATE } from '../fixtures';
import { URLS } from '../site';
import { FORM_PLUGINS, applyFormTracking, formFixtures, type FormPluginCode } from '../helpers/forms';

/** What each fixture shape should be read as, whatever plugin built it. */
const EXPECTED: Record<string, { on: boolean; event: string; sends: string }> = {
  email: { on: true, event: 'Lead', sends: 'Sends: Email, First name, Last name' },
  phone: { on: true, event: 'Lead', sends: 'Sends: Phone, First name, Last name' },
  'email-only': { on: true, event: 'CompleteRegistration', sends: 'Sends: Email' },
  'combined name': { on: true, event: 'Lead', sends: 'Sends: Email, First name, Last name' },
  message: { on: true, event: 'Lead', sends: 'Sends: Email' },
  search: { on: false, event: 'Lead', sends: 'Sends: Email' },
  inferred: { on: false, event: 'Lead', sends: 'Sends: Phone' },
};

test.use({ storageState: ADMIN_STATE });

test.beforeAll(() => {
  applyFormTracking();
});

for (const code of Object.keys(FORM_PLUGINS) as FormPluginCode[]) {
  test(`${FORM_PLUGINS[code]}: its forms are listed with their confidence, event and fields`, async ({
    page,
  }) => {
    await page.goto(URLS.settings, { waitUntil: 'domcontentloaded' });
    await page.locator('text=Form Settings').first().click();
    await expect(page.getByTestId('forms-plugin-status')).toContainText(FORM_PLUGINS[code]);

    const fixtures = Object.entries(formFixtures()).filter(([title]) => title.startsWith(`PF-${code} `));
    expect(fixtures.length, `no ${code} fixtures are seeded`).toBeGreaterThan(0);

    for (const [title, fixture] of fixtures) {
      const expected = EXPECTED[fixture.shape];
      const row = page.getByTestId(`form-row-${fixture.source}:${fixture.form_id}`);

      await expect(row, `${title} is not listed`).toBeVisible();
      await expect(row.getByRole('switch'), `${title} switch`).toHaveAttribute(
        'aria-checked',
        String(expected.on)
      );
      await expect(
        row.getByRole('button', { name: `Event for ${title}` }),
        `${title} suggested event`
      ).toHaveText(expected.event);
      await expect(row.getByTestId(`form-summary-${fixture.source}:${fixture.form_id}`)).toHaveText(
        expected.sends
      );
    }
  });
}
