/**
 * The Complianz consent banner, and the cookies a consent decision leaves behind.
 *
 * Every Complianz selector in the suite lives in this file: the banner's markup
 * belongs to a plugin we do not control, so a Complianz upgrade must break one
 * file and not every consent scenario. Scenarios address the banner through
 * `accept()`, `deny()` and `withdraw()` and never touch a selector themselves.
 *
 * Consent is always driven through the banner rather than by writing cookies.
 * The plugin does not read Complianz's cookies — it reads the WP Consent API
 * state Complianz sets, and writes its own `_pf_consent` /
 * `_pf_no_consent_decision` from the tracking script in response to the
 * consent-change signal. A fabricated cookie would prove only that the plugin
 * honours a cookie; clicking the banner proves the whole chain.
 */
import { expect, type Page } from '@playwright/test';

/** The banner dialog itself. `cmplz-hidden` is how Complianz keeps it out of view. */
const BANNER = '#cmplz-cookiebanner-container .cmplz-cookiebanner';
const ACCEPT = `${BANNER} .cmplz-accept`;
const DENY = `${BANNER} .cmplz-deny`;
/** Reopens the banner after a decision has been made — the entry point for a withdrawal. */
const MANAGE_CONSENT = '#cmplz-manage-consent button.cmplz-manage-consent';

/** Cookies the plugin writes in response to the consent signal. */
export interface PixelflowConsentCookies {
  /** `true` while the shopper has answered neither way. */
  noDecision: string | null;
  /** The granted/denied state the server hooks read. */
  consent: string | null;
  /** Which CMP the decision came from. */
  source: string | null;
  /** The hold queue, present only while events are waiting for a decision. */
  heldEvents: string | null;
}

export class ConsentBanner {
  constructor(private readonly page: Page) {}

  /** True while the banner is on screen waiting for an answer. */
  async isVisible(): Promise<boolean> {
    return this.page
      .locator(BANNER)
      .first()
      .isVisible()
      .catch(() => false);
  }

  /** Waits for the banner to appear, so a click never races the CMP's own script. */
  async waitUntilVisible(timeoutMs = 20_000): Promise<void> {
    await expect(
      this.page.locator(BANNER).first(),
      'the consent banner never appeared — is the CMP still configured for an opt-in region?'
    ).toBeVisible({ timeout: timeoutMs });
  }

  /** True when the banner offers both an accept and a deny choice, as opt-in mode requires. */
  async offersBothChoices(): Promise<boolean> {
    const accept = this.page.locator(ACCEPT).first();
    const deny = this.page.locator(DENY).first();
    return (await accept.isVisible().catch(() => false)) && (await deny.isVisible().catch(() => false));
  }

  /** Grants consent and waits until the plugin has recorded the decision. */
  async accept(): Promise<void> {
    await this.waitUntilVisible();
    await this.page.locator(ACCEPT).first().click();
    await this.waitUntilDecided();
  }

  /** Declines and waits until the plugin has recorded the decision. */
  async deny(): Promise<void> {
    await this.waitUntilVisible();
    await this.page.locator(DENY).first().click();
    await this.waitUntilDecided();
  }

  /**
   * Reopens the banner after a decision has already been made, through the
   * "Manage consent" control Complianz leaves on the page. This is the most
   * fragile interaction in the suite; the two scenarios that change their mind
   * are the only callers.
   */
  private async reopen(): Promise<void> {
    const opener = this.page.locator(MANAGE_CONSENT).first();
    await expect(
      opener,
      'the "Manage consent" control is not on the page — changing a decision needs an existing one'
    ).toBeVisible({ timeout: 20_000 });
    await opener.click();
    await this.waitUntilVisible();
  }

  /** Withdraws a consent that was already granted. */
  async withdraw(): Promise<void> {
    await this.reopen();
    await this.page.locator(DENY).first().click();
    await this.waitUntilDecided();
  }

  /** Grants consent after an earlier decline — the buyer changing their mind. */
  async regrant(): Promise<void> {
    await this.reopen();
    await this.page.locator(ACCEPT).first().click();
    await this.waitUntilDecided();
  }

  /**
   * Waits until the banner is gone and the plugin's script has written its own
   * cookies. Without this a scenario can act while the decision is still in
   * flight and read a hold that is about to be flushed.
   */
  private async waitUntilDecided(timeoutMs = 20_000): Promise<void> {
    await expect(this.page.locator(BANNER).first()).toBeHidden({ timeout: timeoutMs });
    await expect
      .poll(async () => (await this.pixelflowCookies()).consent !== null || (await this.cmplzCookies()).length > 0, {
        message: 'the consent decision never reached the plugin cookies',
        timeout: timeoutMs,
      })
      .toBe(true);
  }

  /** Complianz's own cookies — read to confirm the CMP recorded what the click meant. */
  async cmplzCookies(): Promise<Array<{ name: string; value: string }>> {
    const cookies = await this.page.context().cookies();
    return cookies
      .filter((cookie) => cookie.name.startsWith('cmplz_'))
      .map(({ name, value }) => ({ name, value }));
  }

  /** True when the CMP recorded marketing consent, which is the category the plugin registers under. */
  async marketingConsent(): Promise<string | null> {
    const cookies = await this.cmplzCookies();
    return cookies.find((cookie) => cookie.name === 'cmplz_marketing')?.value ?? null;
  }

  /** The plugin's own cookies, which are what the server hooks actually read. */
  async pixelflowCookies(): Promise<PixelflowConsentCookies> {
    const cookies = await this.page.context().cookies();
    const value = (name: string): string | null =>
      cookies.find((cookie) => cookie.name === name)?.value ?? null;

    return {
      noDecision: value('_pf_no_consent_decision'),
      consent: value('_pf_consent'),
      source: value('_pf_consent_source'),
      heldEvents: value('_pf_held_woo_events'),
    };
  }

  /**
   * Waits until the plugin knows the shopper has not answered yet.
   *
   * The `_pf_no_consent_decision` cookie is written by the tracking script,
   * which loads asynchronously — it lands a little under a second after
   * DOMContentLoaded. A scenario that acts before it exists is testing that
   * window rather than the hold, so the undecided baseline waits for it.
   */
  async waitForUndecidedState(timeoutMs = 20_000): Promise<void> {
    await expect
      .poll(async () => (await this.pixelflowCookies()).noDecision, {
        message:
          'the plugin never learned that the banner is unanswered — the tracking script did not ' +
          'write _pf_no_consent_decision',
        timeout: timeoutMs,
      })
      .toBe('true');
  }

  /** True while events are waiting in the hold queue for a decision. */
  async hasHeldEvents(): Promise<boolean> {
    return (await this.pixelflowCookies()).heldEvents !== null;
  }
}
