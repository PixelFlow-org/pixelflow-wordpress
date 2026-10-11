/**
 * A fixture page holding exactly one form, whatever plugin rendered it.
 *
 * Every fixture labels its fields the same way (Name, Email, Phone, Your name, Message, Query,
 * Phone number) and its button "Send", so one page object fills and submits all of them by
 * accessible name rather than by each plugin's own markup.
 */
import type { Page } from '@playwright/test';

export class FormPage {
  constructor(private readonly page: Page) {}

  async open(url: string): Promise<void> {
    await this.page.goto(url, { waitUntil: 'domcontentloaded' });
    // Ninja Forms renders its form client-side; wait for the button of whichever plugin.
    await this.submitButton().waitFor({ state: 'visible', timeout: 30_000 });
    // Elementor Pro binds its AJAX submit from a chunk it loads after DOMContentLoaded. A click
    // before that chunk runs submits the form natively: the page reloads and admin-ajax.php,
    // where the plugin sees the submission, is never called. Scripts added before the load
    // event delay it, so waiting for it covers the chunk.
    await this.page.waitForLoadState('load');
  }

  /** Fills fields by their label. */
  async fill(values: Record<string, string>): Promise<void> {
    for (const [label, value] of Object.entries(values)) {
      await this.page.getByLabel(label, { exact: true }).first().fill(value);
    }
  }

  async submit(): Promise<void> {
    await this.submitButton().click();
  }

  private submitButton() {
    return this.page.getByRole('button', { name: 'Send', exact: true }).first();
  }
}
