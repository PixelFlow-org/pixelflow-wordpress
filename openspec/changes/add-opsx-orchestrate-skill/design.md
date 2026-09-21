## Context

See proposal.md — Why. Requirements are in `specs/opsx-orchestration/spec.md`.

What already exists on this machine and shapes the approach:

- `openspec` CLI: `new change`, `status --json`, `instructions`, `validate
  --strict`, `archive`. There is **no** `verify` subcommand.
- Commands `/opsx:propose`, `/opsx:apply`, `/opsx:update`, `/opsx:archive`
  (project) and `/opsx-check` (global).
- Agents `test-writer`, `developer`, `opsx-checker`, `test-checker` — the first
  two already carry the "tests are the spec / never edit tests" contracts.
  `test-writer` and `test-checker`, plus the
  `~/.claude/skills/pipeline/scripts/check-ac-coverage.sh` they call, are wired to
  the `AC-N` id convention (`grep -oE 'AC-[0-9]+'`, `exit 2` when none is found) —
  see D9.
- Hook `role-guard.sh` (global `PreToolUse`): while
  `<project>/.claude/tasks/.active-role` holds a role name, it **blocks** writes
  outside that role's lane — `test-writer` may write only test paths, `developer`
  may not write test paths. Enforcement is mechanical, covering Edit/Write and
  shell redirection.
- Hook `opsx-ask-first.sh` (`UserPromptSubmit`): appends the "ask, don't decide
  alone" instruction on `/opsx:propose|update` prompts. It matches the literal
  prompt text, so it does not fire when the orchestrator runs the proposal flow
  from inside its own session — see D10.
- Skill `pipeline`: a full stage machine on its own `.claude/tasks/` artifacts.
  Deliberately not the base here — see Decisions.

## Goals / Non-Goals

**Goals:**
- One skill the operator invokes, which then holds both roles and returns to the
  operator only for decisions.
- Reuse the existing agents and hooks rather than adding parallel machinery,
  extending them in place where their conventions do not reach OpenSpec (D9, D10).
- Keep the orchestrator's own context small: it is the most expensive turn-taker
  in the run.

**Non-Goals:**
- No new `/opsx-verify` command.
- No new state file, no `status.yaml`/`journal.ndjson` equivalent. The one
  exception is the checker report the verify stage writes under
  `openspec/changes/<name>/reports/` (D7) — a disposable artifact of a single
  verify run, not run state.
- No changes to the `pipeline` skill or its profiles. Its `test-writer` and
  `test-checker` agent definitions and its `check-ac-coverage.sh` script are
  extended, additively, per D9; `developer` and `opsx-checker` stay untouched.
- No automatic merging, pushing, or archiving — those stay operator actions.

## Decisions

### D1: A standalone skill, not a `pipeline` profile

`pipeline` is a stage machine whose stages are fixed by a profile and whose
state lives in `.claude/tasks/<id>/`. Here the stage list is *data* — the items
of `tasks.md` — and the state is the change itself. Modelling that as a profile
would mean teaching `pipeline` a second source of truth and a second state
store, for one consumer.

*Alternative considered*: `profiles/openspec.md` inside `pipeline`. Rejected:
the shared machinery that would be inherited (branch, journal, status, boundary
script) is exactly the part being replaced by `tasks.md` + git.

What is still borrowed from `pipeline`, by convention rather than by import:
the self-contained agent prompt ("stage packet"), the single fenced `report:`
yaml block agents must return, "trust files, not summaries", the flaky-verdict
rule, and the iteration cap with escalation.

### D2: Role isolation via the existing `role-guard` hook

Before dispatching, the orchestrator writes the role name (`test-writer` or
`developer`) to `<project>/.claude/tasks/.active-role`; after the agent returns,
it removes the file. The hook then blocks a stray write at the moment it is
attempted, with a message the agent can act on.

*Alternative considered*: checking `git diff --name-only` after the agent
returns. Kept as a **backstop**, not the primary mechanism — post-hoc detection
costs a whole wasted agent run and can leave a half-written file behind.

Two consequences to handle in the skill:
- The role file must be removed on every exit path, including abort and error;
  a stale file silently constrains the operator's next session. The skill
  removes any stale file at startup.
- The hook resolves the path from `CLAUDE_PROJECT_DIR`; `.claude/tasks/` must
  exist. The skill creates it if missing and adds nothing else to it.

### D3: The verdict is one truncated run by the orchestrator

Agents run whatever they need while working — that is their business and their
context. Their reported results are never a verdict. At each boundary the
orchestrator runs the tests itself in **one** Bash block with output truncated
(`… 2>&1 | tail -20`), scoped to the task's tests, and uses only that.

Boundaries where the orchestrator runs: after the test author (expects red,
naming the failing assertions), after the implementer (expects green), and once
over the full suite when the last task closes.

A verdict that flips between two identical runs is a flake, never a result: on
an unexpected result the failing test is re-run once in the same block, and a
flip is reported to the operator rather than resolved by re-running to green.
This matters most at the red gate — a flaky red is not proof the test bites.

*Alternative considered*: trusting the test agent's run and re-checking only at
the end. Rejected: it is exactly the claim the operator asked to make
unforgeable.

### D4: The red gate is a table, and it is the only planned stop

The gate shows one row per scenario: `requirement → test name → what it asserts
→ observed failure`, plus a short risk list, then `AskUserQuestion` with
approve / amend / rework options. Test file contents are not pasted; the paths
are given and the orchestrator offers to show any test on request.

The gate also carries the orchestrator's own judgement on *test quality*: a test
that asserts on source text, symbol names or call structure instead of
observable behavior is flagged as a defect in its row. This is the operator's
stated reason for wanting the gate at all.

A green task prints one line and continues. Everything else that stops the run
is event-driven (D5).

### D5: Escalation is a fixed trigger list, not a judgement call

The orchestrator stops and asks — with concrete options and the consequence of
each — on: an unfixed invariant or business rule; a risk the spec does not
cover; a contradiction between spec and code, or inside the spec; a dev↔test
disagreement about a requirement; an agent returning `BLOCKED`; a flaky verdict;
the third iteration on one task. The disagreement case is always escalated even
when the spec appears to settle it, per the operator's decision — the
orchestrator may quote the deciding line as its recommendation, but the operator
answers.

Answers that change a requirement are folded back into the change's artifacts
(the `/opsx-check` update discipline: dependent artifacts are re-read from disk
and edited together), not just into the current test.

### D6: State is `tasks.md` checkboxes plus git

Branch `change/<change-name>` created from the current branch if not already on
it. One commit per closed task, containing that task's tests, its source, and
the ticked checkbox — the commit is the record of the whole red→green cycle.

A task is ticked only after the orchestrator's own green run **and** the
`git diff --name-only` write-scope backstop of D2 comes back clean, never merely
because tests passed — a rule borrowed from `strelov1/spec-driven-tdd`. There is
no separate per-task review stage; the only review of the work as a whole is the
verify stage of D7.

Resume is derived: first unchecked item in `tasks.md`. A dirty working tree at
startup is an operator question, since the per-task commit would otherwise
swallow unrelated edits.

*Alternative considered*: a `session.md` run log in the change folder, which the
operator rejected. Consequence accepted and stated: decisions made at gates
survive only in commit messages and in the artifacts they amended.

### D7: `verify` is a stage built from two checkers

`openspec` has no verify. When the last task closes, the orchestrator dispatches
`opsx-checker` (artifacts against each other and against code reality) and
`test-checker` (tests really assert the claimed criteria; the green status is
real), then prints one coverage table: requirement → asserting test → implementing
file, with gaps named. Archive is offered, never performed silently.

`test-checker`'s contract obliges it to write a report file and defaults that path
to a `<task folder>` this workflow has no notion of. The orchestrator therefore
passes an explicit path, `openspec/changes/<name>/reports/test-checker.md`, and
includes it in that agent's write scope. `opsx-checker` is report-only and writes
nothing.

*Alternative considered*: a separate `/opsx-verify` command, which the operator
declined as extra surface.

### D8: Skill shape

`~/.claude/skills/opsx-orchestrate/SKILL.md`, user-invoked as
`/opsx-orchestrate`, with the argument being a change name or a free-text
description. One file; no scripts, no templates — the templates it would need
(`report:` block, gate table) are a few lines each and belong inline.

The skill lives outside this repository on purpose (proposal — Impact); the
change's `tasks.md` therefore names absolute paths under `~/.claude/`.

### D9: Coverage ids — teach the `AC-N` tooling OpenSpec's `Scenario` shape

`test-writer` must cover "every AC" and self-check with
`check-ac-coverage.sh <spec artifact> <test paths>`; `test-checker` reports an
`| AC | Covered by | Status |` table. The script greps `AC-[0-9]+` and exits 2
when it finds none. An OpenSpec delta spec has no `AC-N` anywhere — its units are
`### Requirement:` and `#### Scenario:` — so dispatching either agent against a
delta spec fails deterministically at the red step, before any test is written.

Decision: extend all three in place, additively. The script falls back to
`#### Scenario:` headings as coverage ids when the artifact holds no `AC-N`; the
two agents accept either id shape and name whichever one they found in test names
and in their report table. The `AC-N` path is untouched, so `/fix` and `/feature`
keep behaving exactly as before.

*Alternative considered*: the orchestrator synthesising `AC-N` ids into a throwaway
file and feeding that to the agents instead of the delta spec. Rejected by the
operator: it puts a fabricated artifact between the agents and the spec, and the
gate table would then quote ids that exist nowhere in the change.

*Alternative considered*: forking the two agents into `opsx-`prefixed copies.
Rejected: two near-identical definitions to keep in sync for one difference.

### D10: The orchestrator carries "ask, don't decide alone" itself

`opsx-ask-first.sh` is a `UserPromptSubmit` hook matching the literal text of the
operator's prompt. When the orchestrator runs the proposal flow from inside its own
session (Entry, first scenario), no new prompt event occurs and the hook never
fires — precisely on the path where the operator installed that protection.

Decision: the skill states the ask-first rule in its own Entry section and applies
it whenever it authors or amends change artifacts, rather than relying on the hook.
The hook stays as-is and keeps covering the operator's direct `/opsx:propose|update`
invocations; the two paths simply do not depend on each other.

## Risks / Trade-offs

- **A run's rationale is unrecoverable.** No run log means the "why" behind a
  gate decision survives only if it lands in an artifact or a commit message →
  the skill writes each operator decision into the commit message of the task it
  affected, and requirement-level decisions back into the change's artifacts.
- **Stale `.active-role` silently constrains later sessions.** → removed on
  every exit path and cleared at startup; the skill states when it clears one.
- **`role-guard` is best-effort for Bash writes.** A determined agent could
  still write across lanes → the post-return `git diff --name-only` backstop
  (D2) catches what the hook missed, and the task is returned rather than
  committed.
- **Orchestrator context growth.** Relaying two roles over many tasks inflates
  the main session → agent prompts are self-contained packets, test output is
  truncated, artifacts are read once and reused from context, and the skill
  never re-reads a file it just wrote.
- **The red gate is only as good as its table.** A dishonest or shallow row
  hides a bad test → the row must quote the observed failure from the
  orchestrator's own run, which is hard to fabricate from a green test.
- **Two roles cost roughly twice one agent.** Accepted deliberately: the
  isolation is the point, and it replaces the operator's manual relay.
- **Editing shared agents and the shared coverage script can regress `/fix` and
  `/feature`.** They are the same files those pipelines dispatch → every change is
  additive with `AC-N` as the first branch, and the dry run (tasks 6.3) exercises
  an `AC-N` artifact as well as an OpenSpec delta before the skill is used for
  real.
