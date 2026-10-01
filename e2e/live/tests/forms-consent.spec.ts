/**
 * Form submissions under the consent banner: held while it is unanswered and sent once, with
 * the original submission time, after a grant; reported anonymously and never sent after a
 * denial.
 */
import { test, expect } from "../fixtures";
import { FormPage } from "../pages/form-page";
import {
  readEventRecords,
  waitForRecords,
  type EventRecord,
} from "../helpers/debug-log";
import {
  FORM_PLUGINS,
  applyFormTracking,
  resetFormState,
  formBeacons,
  formFixture,
  formOutcomes,
  formRecords,
  waitForFormBeacon,
  waitForFormOutcome,
  type FormPluginCode,
  type FormRecord,
} from "../helpers/forms";

const FORM = "PF-CF7 message";

test.beforeAll(() => {
  applyFormTracking();
});

test.beforeEach(() => {
  resetFormState();
});

/** Sent entries that carry a given event id, whichever request sent them. */
function sentWithEventId(
  records: EventRecord[],
  eventId: string,
): FormRecord[] {
  return formRecords(records).filter(
    (record) =>
      record.outcome === "sent" &&
      record.payload.eventData?.event_id === eventId,
  );
}

test.describe("Form submission under an unanswered banner", () => {
  test.use({ consent: "undecided" });

  // Every plugin, because the hold depends on the plugin's own response still being able to
  // set the hold cookie, and each plugin answers a submission its own way.
  for (const code of Object.keys(FORM_PLUGINS) as FormPluginCode[]) {
    test(`${FORM_PLUGINS[code]}: is held, and sent once after the grant with the original submission time`, async ({
      page,
      consentBanner,
    }) => {
      const fixture = formFixture(`PF-${code} message`);
      const form = new FormPage(page);
      await form.open(fixture.url);
      await form.fill({
        Email: "pf-form-hold@example.test",
        Message: "Held until a decision",
      });
      await form.submit();

      const heldRecords = await waitForFormOutcome(fixture.key, "held");
      const held = formOutcomes(heldRecords, fixture.key, "held")[0];
      const eventId = String(held.payload.eventData?.event_id ?? "");
      const eventTime = held.payload.eventData?.eventTime;
      expect(eventId, "the held entry carries no event id").not.toBe("");
      expect(
        formOutcomes(readEventRecords(), fixture.key, "sent"),
        "the submission was sent before any decision",
      ).toHaveLength(0);

      await consentBanner.accept();

      const records = await waitForRecords(
        (all) => sentWithEventId(all, eventId).length >= 1,
        {
          description: `the held form event ${eventId} sent after the grant`,
          timeoutMs: 60_000,
        },
      );
      const sent = sentWithEventId(records, eventId);
      expect(sent, "the held form event was sent more than once").toHaveLength(
        1,
      );
      expect(
        sent[0].payload.eventData?.eventTime,
        "the grant replaced the submission time",
      ).toBe(eventTime);
      expect(sent[0].payload.eventData?.eventName).toBe("Lead");
    });
  }
});

test.describe("Form submission after a denial", () => {
  test.use({ consent: "declined" });

  test("sends one anonymous denied report and no event", async ({ page }) => {
    const fixture = formFixture(FORM);
    const form = new FormPage(page);
    await form.open(fixture.url);
    await form.fill({
      Email: "pf-form-denied@example.test",
      Message: "Refused",
    });
    await form.submit();

    const records = await waitForFormBeacon(fixture.key);
    const beacons = formBeacons(records, fixture.key);
    expect(beacons, "more than one blocked report was sent").toHaveLength(1);

    const rows = beacons[0].payload.blocked ?? [];
    expect(rows).toHaveLength(1);
    expect(rows[0].reason).toBe("denied");
    expect(rows[0].eventType).toBe("Lead");
    expect(
      JSON.stringify(beacons[0].payload),
      "the report identifies the visitor",
    ).not.toMatch(/customerData|external_id|em"|pf-form-denied/);

    expect(
      formOutcomes(readEventRecords(), fixture.key, "sent"),
      "a denied submission was sent",
    ).toHaveLength(0);
  });
});
