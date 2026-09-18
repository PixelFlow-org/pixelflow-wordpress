## Why

The operator currently runs OpenSpec changes across two separate Claude Code
sessions — one that writes production code, one that writes and runs tests — and
relays every message between them by hand. The operator is a human message bus:
slow, lossy, and it buries the decisions that actually need a human (invariants,
risks, spec-versus-code contradictions) under mechanical copy-paste traffic.

Nothing on the shelf covers this. `mattpocock/skills` ships `tdd` and `implement`
but no OpenSpec integration and no role separation. `strelov1/spec-driven-tdd`
fuses OpenSpec with a TDD loop, but drives a *single* agent through phases and
has no human gate on what the tests actually assert. The local `pipeline` skill
has the gate machinery but runs on its own `.claude/tasks/` artifacts rather than
on OpenSpec changes.

## What Changes

- A new user-invoked skill `opsx-orchestrate` (global, `~/.claude/skills/`) that
  drives an OpenSpec change end to end: propose → check → implement → verify →
  archive.
- The dev↔test relay moves from the operator to the skill. Two isolated
  subagent roles per task: a test author (writes tests, never touches source)
  and an implementer (writes source, never touches tests).
- Strict TDD per `tasks.md` item: tests first and demonstrably red, then code,
  then green.
- The orchestrator — not the agents — owns the red/green verdict. Agents run
  targeted tests while working; the orchestrator's own run is the only thing
  that closes a task.
- One mandatory operator gate per task, on the red tests: a compact
  scenario table (requirement → test → what it asserts), never a wall of text.
  A green task closes without stopping.
- Unconditional operator stops on: risks, invariants, business-rule choices,
  spec↔code contradictions, and any dev↔test disagreement about a requirement.
- A closing verify stage that reuses the existing `opsx-checker` and
  `test-checker` agents to prove requirement → test → code coverage before
  archive. No new `/opsx-verify` command.
- `test-writer`, `test-checker` and the shared `check-ac-coverage.sh` script are
  taught OpenSpec's `Requirement`/`Scenario` shape. Today all three are hard-wired
  to the `AC-N` id convention, which a delta spec never uses, so they cannot be
  reused against an OpenSpec change as they stand.
- Run state lives only in `tasks.md` checkboxes and git history: the skill
  creates a branch for the change and commits one closed task at a time. No new
  state files.

## Capabilities

### New Capabilities
- `opsx-orchestration`: how the orchestrator skill drives an OpenSpec change
  through a TDD loop of two isolated agent roles, what it decides on its own,
  and what it must escalate to the operator.

### Modified Capabilities

<!-- None: no existing spec's requirements change. -->

## Impact

- **New**: `~/.claude/skills/opsx-orchestrate/SKILL.md` — deliberately outside
  this repository. The skill is global tooling used across projects; only its
  spec lives here, since this repo holds the only OpenSpec root on the machine.
- **Reused unchanged**: agents `developer`, `opsx-checker`; commands
  `/opsx:propose`, `/opsx:apply`, `/opsx:archive`, `/opsx-check`.
- **Modified in place**: agents `test-writer` and `test-checker`, and
  `~/.claude/skills/pipeline/scripts/check-ac-coverage.sh`. Each learns to accept
  `#### Scenario:` headings as coverage ids alongside `AC-N`; the `AC-N` path is
  left intact so the existing `/fix` and `/feature` pipelines keep working.
- **Not touched**: the `pipeline` skill and its profiles, the plugin's own
  source and tests.
- **Dependency**: the `openspec` CLI. It has no `verify` subcommand — the
  verify stage is built from the two checker agents instead.
