## 0. Shared tooling prerequisites

- [ ] 0.1 Extend `~/.claude/skills/pipeline/scripts/check-ac-coverage.sh`: keep `AC-[0-9]+` as the first branch, and when an artifact yields no `AC-N`, fall back to `#### Scenario:` headings as coverage ids. Existing `AC-N` artifacts must behave exactly as before.
- [ ] 0.2 Extend `~/.claude/agents/test-writer.md`: accept either id shape, put whichever the artifact uses into test names, and run the self-check against that same artifact. The `AC-N` wording stays for `/fix` and `/feature`.
- [ ] 0.3 Extend `~/.claude/agents/test-checker.md`: same dual id shape in its checks and in its report table, and make the report path an explicit input rather than a `<task folder>` default.

## 1. Skill skeleton

- [ ] 1.1 Create `~/.claude/skills/opsx-orchestrate/SKILL.md` with frontmatter (`name`, user-invocable `description` naming the trigger phrases `/opsx-orchestrate`, "прогони фичу через оркестратор", "drive this change") and the top-level role statement: the skill runs in the main session, never writes product code itself, and returns to the operator only for decisions.
- [ ] 1.2 Write the **Entry** section: resolve the target change via `openspec list --json` / the operator's argument; the cases from the spec (no match → propose, exactly one unfinished → resume, several → ask, chosen change has no `tasks.md` → finish planning first), counting a change with no `tasks.md` as unfinished; state which change and step were picked.
- [ ] 1.3 Add the **ask-first** rule to Entry: whenever the orchestrator authors or amends change artifacts itself it asks the operator instead of deciding alone, since `opsx-ask-first.sh` only fires on the operator's own `/opsx:propose|update` prompt and not on an internal proposal flow.
- [ ] 1.4 Write the **Preflight** section: clear a stale `.claude/tasks/.active-role`, create `.claude/tasks/` if absent, check `git status --porcelain` and stop on a dirty tree, create/checkout `change/<name>`.

## 2. Spec readiness

- [ ] 2.1 Write the **Readiness** section: run `openspec validate <change> --type change --strict`, fix structural errors with the operator before any dispatch.
- [ ] 2.2 Add the semantic-check offer: propose `/opsx-check` when the change has not been checked in this run, and record the operator's answer; never dispatch an agent against unchecked artifacts without it.

## 3. Per-task TDD loop

- [ ] 3.1 Write the **Task loop** section header: take the first unchecked `tasks.md` item, restate it, and drive it through red → gate → green → close.
- [ ] 3.2 Write the **Dispatch contract**: the self-contained packet fields (task, goal, verbatim requirements from the delta spec, decisions already fixed, `read` paths, `write` scope, the untrusted-content rule, the self-check-on-disk rule) and the single fenced `report:` yaml block every agent must return.
- [ ] 3.3 Write the **Role isolation** procedure: write the role name to `.claude/tasks/.active-role` before each spawn, remove it after every exit path including error and abort, and run the `git diff --name-only` backstop when the agent returns; a cross-lane write returns the task to its author instead of being committed.
- [ ] 3.4 Write the **red step**: dispatch `test-writer` for the task's scenarios only; then the orchestrator's own truncated run; assert the new tests actually fail, and treat tests that pass on unimplemented code as defective.
- [ ] 3.5 Write the **flake rule**: an unexpected verdict is re-run once in the same block *before* it is classified — a test that passes on unimplemented code is called defective only once the re-run confirms it; a flip is reported to the operator as a flake, never resolved by re-running to green.
- [ ] 3.6 Write the **red gate**: the per-scenario table (requirement → test → what it asserts → observed failure), the risk lines, the test-quality flag for assertions on source text/symbols/call structure, and the `AskUserQuestion` options (approve / amend / rework). Explicitly forbid pasting test file contents.
- [ ] 3.7 Write the **green step**: dispatch `developer` with the failing test output; the orchestrator's own run is the verdict; on green print one line (outcome + changed files) and continue without a gate.
- [ ] 3.8 Write the **iteration cap**: three executor↔verdict rounds on one task, then escalate to the operator.

## 4. Operator decisions

- [ ] 4.1 Write the **Escalation triggers** section as an explicit list (invariant/business rule, uncovered risk, spec↔code or spec-internal contradiction, dev↔test disagreement, agent `BLOCKED`, flake, iteration cap) with the rule that every stop offers concrete options and the consequence of each.
- [ ] 4.2 Write the **Disagreement** rule: a dev↔test conflict is always the operator's call; the orchestrator may quote the deciding spec line as a recommendation but does not settle it.
- [ ] 4.3 Write the **Artifact write-back** rule: an answer that changes a requirement is folded into the change's artifacts together with their dependents, re-read from disk, following the `/opsx-check` update discipline.
- [ ] 4.4 Write the **Scope discipline** rule: discoveries outside the current task are reported at the next stop and never implemented unasked.
- [ ] 4.5 Write the **Role relay** rule: the orchestrator keeps the id of each role's live agent for the current task and, when the operator asks something aimed at one role, resumes that agent with `SendMessage` and returns its answer — the operator never switches sessions. Name which role a question goes to when it is ambiguous rather than guessing.

## 5. Closing a task and a change

- [ ] 5.1 Write the **Task close** rule: tick the `tasks.md` checkbox only after the orchestrator's own green run and a clean `git diff --name-only` write-scope backstop (3.3) — there is no separate per-task review stage — then one commit carrying tests + source + checkbox, with the operator's gate decisions recorded in the commit message.
- [ ] 5.2 Write the **Verify** section: after the last task, run the full suite once, then dispatch `opsx-checker` and `test-checker`, and print the coverage table (requirement → asserting test → implementing file) with gaps named. Pass `test-checker` an explicit report path, `openspec/changes/<name>/reports/test-checker.md`, and add it to that agent's write scope.
- [ ] 5.3 Write the **Archive** section: a clean verify reports the change ready and offers `/opsx:archive`; a gap blocks the offer until the operator decides. Never merge, push, or archive without the operator.

## 6. Economy and self-check

- [ ] 6.1 Write the **Turn economy** section: batch independent calls, truncate every command's output, read an artifact once and reuse it, never re-read a just-written file, keep gate output within its stated size.
- [ ] 6.2 Add a **Fallbacks** section covering the spec's missing-precondition scenarios: `openspec` absent → report and stop; no `tasks.md` → finish planning with the operator first (1.2); no runnable test suite → stop and ask the operator how the verdict is to be established, closing no task until answered; an agent dead mid-work → resume that same agent rather than respawning, and re-run the write-scope check on what it left behind.
- [ ] 6.3 Dry-run the skill against an existing change in this repo without letting it write code — verify entry resolution, preflight, readiness and the first red gate render as specified — and correct the wording where the run diverges from the spec.
- [ ] 6.4 Regression-check the tooling touched in section 0: run `check-ac-coverage.sh` against an `AC-N` artifact from an existing `.claude/tasks/` task and against this change's delta spec, and confirm both pass.
