## Context

Both sniffs are shape-matchers, not data-flow analysers.

`WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` fires when a
superglobal is read into a variable without a sanitizer in the same statement. All
three flagged cookie reads do sanitize — just later, at the point where the value
and its order-meta override converge on one code path. That is deliberate and
readable, and it is exactly what the sniff cannot see.

`WordPress.Security.NonceVerification.Recommended` fires on any `$_GET` read in a
request that is not nonce-checked. `resolve_held_events_on_page_view()` reads
`$_GET['wc-ajax']` only to step aside for two of the plugin's own AJAX routes; it
changes no state on that branch, so no nonce belongs there. The author knew this —
the `phpcs:ignore` with that rationale is already in the file. It sits on the
ternary's second line, while both `$_GET` reads are on the first. PHPCS scopes an
inline ignore to its own line, so the annotation covers a line with nothing to
ignore and the two warnings pass through.

See proposal.md — Why for the motivation.

## Goals / Non-Goals

**Goals:**

- Silence the five plugin-code warnings by making the code say what it already
  does, not by suppressing the report.
- Keep the diff to the four flagged lines.

**Non-Goals:**

- Restructuring how consent cookies and their order-meta overrides converge. The
  late `sanitize_text_field()` calls stay where they are; sanitizing earlier makes
  them redundant, not wrong.
- Auditing the plugin for other sniffs Plugin Check does not currently run.

## Decisions

**Sanitize at the read site rather than annotate.** Chosen over adding three
`phpcs:ignore ... -- sanitized below` comments. `sanitize_text_field()` is
idempotent, so hoisting it costs nothing and leaves no suppression to keep true as
the code around it moves. A wordpress.org reviewer also reads suppressions with
more suspicion than they read a sanitizer. The requester confirmed this direction.

**`consent.php:406` is annotated, not sanitized, to keep the comparison
byte-exact.** Unlike the other two cookies, `_pf_no_consent_decision` is never
passed through `sanitize_text_field()` downstream — it is compared straight to the
literal `'true'`. Sanitizing first would widen, by a hair, what counts as a hold: a
malformed cookie such as `tr<b>ue` sanitizes to `true` and would register as a
decision-pending marker where today it does not. This change exists to fix a
static-analysis report and is not allowed to move behaviour, so this one read
carries a `phpcs:ignore ... -- compared byte-exact to a literal below` instead. The
other two cookies keep the hoisted sanitizer: `sanitize_text_field()` is idempotent
and both already pass through it downstream, so there the hoist is provably
behaviour-neutral.

**Annotate both lines 73 and 74.** The rationale comment is already written and
correct; line 73's reads simply carry none. An earlier reading of this file held
that both `$_GET` reads sit on line 73, so that moving the single annotation up
would cover them — a local `phpcs` + WPCS 3.4.1 run disproves it: there are three
flagged reads, line 74's `sanitize_key(wp_unslash($_GET['wc-ajax']))` among them,
and PHPCS scopes an inline ignore to its own line. An annotation on line 73 alone
leaves one warning on 74; on both lines the file reports zero.

## Risks / Trade-offs

- [The `sanitize_text_field()` call left further down becomes dead weight and a
  later reader deletes it, breaking the order-meta override path that still needs
  it] → Leave those calls untouched and unremarked; they are load-bearing for
  `$raw_override`, which never passes through the new call site.
- [Plugin Check's line numbers drift, and a re-run reports the same warnings
  against edited code] → The verification step is a fresh Plugin Check run on the
  pushed branch, not a local diff read.
- [The `phpcs:ignore` at `consent.php:406` outlives the byte-exact comparison it
  excuses] → Its rationale names that comparison explicitly, so a reader who
  changes the line sees what the suppression was bought for.
