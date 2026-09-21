## Purpose

Defines how a single orchestrator drives an OpenSpec change from proposal to
archive by relaying work between two isolated agent roles — a test author and an
implementer — so the operator makes decisions instead of carrying messages.

## ADDED Requirements

### Requirement: Entry mode resolution

The orchestrator SHALL determine, before any work starts, which OpenSpec change
it is driving, and SHALL NOT guess when the answer is ambiguous.

#### Scenario: No change named and none in progress
- **WHEN** the operator invokes the orchestrator with a free-text description and `openspec list` reports no change matching it
- **THEN** the orchestrator runs the proposal flow to create the change and its artifacts before any code is written

#### Scenario: Exactly one change in progress
- **WHEN** the operator invokes the orchestrator without naming a change and exactly one change is unfinished — it has unchecked `tasks.md` items, or it has no `tasks.md` yet
- **THEN** the orchestrator resumes that change and states which change and which step it resumed at

#### Scenario: Several candidate changes
- **WHEN** more than one change is unfinished in that sense and the operator named none
- **THEN** the orchestrator asks the operator which change to drive, listing each candidate with its state, and starts no work until answered

#### Scenario: The chosen change has no tasks yet
- **WHEN** the change being driven has planning artifacts but no `tasks.md` items to work through
- **THEN** the orchestrator completes the missing planning artifacts with the operator before dispatching any implementation role

### Requirement: Missing preconditions stop the run

The orchestrator SHALL stop and ask rather than proceed when something it depends
on to do its job is absent.

#### Scenario: The openspec CLI is unavailable
- **WHEN** the `openspec` CLI cannot be run on the machine
- **THEN** the orchestrator reports that and stops, rather than working the change folder by hand

#### Scenario: The project has no runnable test suite
- **WHEN** the project offers no test command the orchestrator can run itself
- **THEN** the orchestrator stops and asks the operator how the red and green verdicts are to be established, and closes no task until that is answered

#### Scenario: A dispatched role dies mid-work
- **WHEN** a dispatched role terminates before returning its report
- **THEN** the orchestrator resumes that same agent rather than spawning a replacement, and re-runs the write-scope check on what it left behind before continuing

### Requirement: Spec readiness precedes implementation

The orchestrator SHALL NOT dispatch any implementation work against artifacts
that are structurally invalid or that carry unresolved ambiguity.

#### Scenario: Artifacts fail structural validation
- **WHEN** `openspec validate <change> --type change --strict` reports errors before the first task
- **THEN** the orchestrator reports the errors and resolves them with the operator before dispatching any agent

#### Scenario: Spec never semantically checked
- **WHEN** the change's artifacts have not been through a semantic check in this run
- **THEN** the orchestrator offers the operator to run the semantic check, and proceeds to implementation only after the operator's answer

### Requirement: Isolated agent roles

The orchestrator SHALL dispatch work to two separate subagent roles with
disjoint write scopes, and SHALL be the only channel between them.

#### Scenario: Test author writes only tests
- **WHEN** the test-authoring role returns its work for a task
- **THEN** the changed files are test files only, and any production-source edit it made is rejected and sent back

#### Scenario: Implementer writes only source
- **WHEN** the implementing role returns its work for a task
- **THEN** the changed files contain no test file, and any test edit it made is rejected and sent back

#### Scenario: Operator addresses one role directly
- **WHEN** the operator asks a question aimed at one of the roles
- **THEN** the orchestrator relays it to that role's live agent and returns the answer, without the operator switching sessions

### Requirement: Strict TDD order per task

For each `tasks.md` item the orchestrator SHALL obtain failing tests before any
implementation of that item begins.

#### Scenario: Tests written and failing first
- **WHEN** a task is started
- **THEN** the test-authoring role runs first, and the implementing role is dispatched only after the new tests have been observed failing

#### Scenario: New tests pass before implementation
- **WHEN** the tests written for a task pass on the unimplemented code
- **THEN** the orchestrator treats this as a defective test, not as a completed task, and returns it to the test-authoring role with the observation

### Requirement: The orchestrator owns the red/green verdict

An agent's claim about test results SHALL NOT close a task. Only a test run
performed by the orchestrator itself SHALL count as a verdict.

#### Scenario: Agent reports green
- **WHEN** the implementing role reports that tests pass
- **THEN** the orchestrator runs the task's tests itself and uses its own result as the verdict

#### Scenario: Verdict runs stay cheap
- **WHEN** the orchestrator runs tests to establish a verdict at a task boundary
- **THEN** it runs the tests relevant to that task, with output truncated, rather than the whole suite

#### Scenario: Full suite before the change closes
- **WHEN** the last `tasks.md` item is closed
- **THEN** the orchestrator runs the project's full test suite once and reports its result

### Requirement: Operator gate on the failing tests

Each task SHALL stop for operator approval once its tests are written and
failing, and the stop SHALL show what the tests assert, compactly.

#### Scenario: Red gate presentation
- **WHEN** a task's tests are written and observed failing
- **THEN** the orchestrator presents one line per scenario — requirement, test name, and what the test asserts — plus the risks it sees, and waits for the operator's decision

#### Scenario: Gate output stays compact
- **WHEN** the red gate is presented
- **THEN** it shows the per-scenario table and not the full text of the test files

#### Scenario: Tests assert implementation shape rather than behavior
- **WHEN** a test asserts on source text, symbol names, or call structure instead of observable behavior
- **THEN** the orchestrator names it at the red gate as a defect for the operator to decide on, rather than passing it as covered

#### Scenario: Operator rejects the scenarios
- **WHEN** the operator rejects or amends the scenarios at the red gate
- **THEN** the orchestrator returns the task to the test-authoring role with the operator's wording and re-presents the gate

### Requirement: A green task closes without a gate

The orchestrator SHALL NOT stop for approval when a task completes cleanly.

#### Scenario: Task turns green
- **WHEN** the orchestrator's own run shows the task's tests passing and no escalation trigger fired
- **THEN** it reports the outcome in one line with the changed files, marks the `tasks.md` item complete, and starts the next task without waiting

### Requirement: Unconditional escalation to the operator

The orchestrator SHALL NOT decide matters reserved for the operator, and SHALL
stop and ask with options whenever one arises.

#### Scenario: An invariant or business rule must be chosen
- **WHEN** the work requires choosing an invariant, a business rule, or the handling of a risk not already fixed by the spec
- **THEN** the orchestrator stops and asks the operator with concrete options and the consequence of each, and does not pick one itself

#### Scenario: Spec and code contradict each other
- **WHEN** the code's actual behavior contradicts the spec, or the spec contradicts itself
- **THEN** the orchestrator stops, states both sides with file and line evidence, and asks the operator which one is authoritative

#### Scenario: The roles disagree about a requirement
- **WHEN** the implementing role argues that a test's expectation contradicts the spec
- **THEN** the orchestrator escalates the disagreement to the operator as a question about the requirement, and neither role's view prevails on its own

#### Scenario: An agent returns blocked
- **WHEN** a dispatched role returns with open questions instead of finished work
- **THEN** the orchestrator relays those questions to the operator and resumes the same agent with the answers

### Requirement: Scope discipline

The orchestrator SHALL keep the run inside the change's `tasks.md`, and SHALL
surface discoveries rather than acting on them.

#### Scenario: An agent finds unrelated work
- **WHEN** a role reports a defect or improvement outside the current task
- **THEN** the orchestrator records it as a discovery, reports it at the next stop, and does not implement it without the operator's word

### Requirement: Run state lives in tasks.md and git

The orchestrator SHALL keep no state file of its own; a run SHALL be resumable
from the change's `tasks.md` and the repository history alone.

#### Scenario: Change work starts
- **WHEN** the orchestrator begins implementing a change and the repository is not already on a branch for it
- **THEN** it creates a branch named after the change and works there

#### Scenario: A task closes
- **WHEN** a task is marked complete
- **THEN** its tests, its source changes, and the `tasks.md` checkbox land in a single commit naming the task

#### Scenario: The session is lost mid-change
- **WHEN** the orchestrator is invoked again on a change with some tasks checked
- **THEN** it resumes at the first unchecked task without needing any file other than the change's artifacts and the git history

#### Scenario: Uncommitted unrelated work is present
- **WHEN** the working tree carries uncommitted changes at the start of the run
- **THEN** the orchestrator stops and asks the operator how to proceed rather than folding them into a task commit

### Requirement: Verification before archive

Before proposing to archive a change, the orchestrator SHALL prove that every
specified requirement is covered by a test and by implementation, using checks
independent of the roles that produced the work.

#### Scenario: Coverage report at close
- **WHEN** every `tasks.md` item is complete
- **THEN** the orchestrator dispatches the artifact-versus-code checker and the test checker, and presents a table of requirement to test to implementing file, naming every gap

#### Scenario: A requirement is uncovered
- **WHEN** the verification finds a requirement with no asserting test or no implementation
- **THEN** the orchestrator reports it as a gap and does not offer to archive until the operator decides how to close it

#### Scenario: Verification is clean
- **WHEN** verification finds no gaps and the full suite passed
- **THEN** the orchestrator reports the change ready and offers to archive it, leaving the archive decision to the operator
