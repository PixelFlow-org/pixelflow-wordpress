## Purpose

Automated pre-merge verification for the PixelFlow plugin: the checks that must
pass before code reaches `main`, and the protection that makes them unavoidable.
It exists so that a broken frontend bundle cannot reach a published release.
## Requirements
### Requirement: Changes reach main only through a pull request

The `main` branch SHALL reject direct pushes from every account, including
repository administrators, and SHALL accept new commits only through a merged
pull request. The protection SHALL NOT require a reviewer's approval: the
repository has a single maintainer and a self-approval is not possible, so an
approval requirement would make every pull request permanently unmergeable.

#### Scenario: Direct push is rejected

- **WHEN** any account, administrator or not, pushes a commit straight to `main`
- **THEN** the push is rejected by the branch ruleset and `main` is unchanged

#### Scenario: Pull request with a failing check cannot merge

- **WHEN** a pull request targets `main` and any required check reports failure
- **THEN** the merge button is disabled until the check reports success

#### Scenario: Pull request with all checks green can merge

- **WHEN** a pull request targets `main` and every required check reports success
- **THEN** the pull request is mergeable by its own author, with no approval from
  another account

#### Scenario: Maintainer needs an emergency path

- **WHEN** the pipeline itself is broken and no pull request can go green
- **THEN** repository documentation describes how to temporarily lift the
  protection, since no account retains a bypass

### Requirement: The pipeline runs on every pull request and on main

Verification SHALL run automatically on each pull request targeting `main` and on
each push to `main`, without any manual trigger. A superseded run on the same
reference SHALL be cancelled rather than left to compete for runners.

#### Scenario: Pull request opened or updated

- **WHEN** a pull request targeting `main` is opened, or a new commit is pushed to
  an open pull request
- **THEN** the pipeline starts automatically and reports its result on that pull
  request

#### Scenario: New commit supersedes a running check

- **WHEN** a new commit is pushed to a reference whose pipeline is still running
- **THEN** the in-flight run is cancelled and only the newest run reports a result

### Requirement: The pipeline authenticates to the private package registry

The plugin's `@pixelflow-org/*` dependencies resolve from a private registry.
The pipeline SHALL authenticate to that registry before installing, and SHALL NOT
require any developer to hold registry credentials in a tracked file.

#### Scenario: Install succeeds with valid credentials

- **WHEN** the pipeline installs dependencies with a credential that can read the
  organisation's packages
- **THEN** all `@pixelflow-org/*` packages resolve and the install completes

#### Scenario: Missing credentials produce a diagnosable failure

- **WHEN** no usable registry credential is available to the run
- **THEN** the run fails with a message naming the missing credential, rather than
  surfacing only the registry's bare 404

#### Scenario: No credential is committed to the repository

- **WHEN** the repository is checked out by a developer with no registry access
- **THEN** no tracked file requires a registry token to be present, and local
  tooling behaves exactly as it did before the pipeline existed

### Requirement: Dependencies install reproducibly

The pipeline SHALL install the exact dependency versions recorded in the committed
lockfile, and SHALL fail rather than silently resolve different versions.

#### Scenario: Lockfile matches the manifest

- **WHEN** the committed lockfile is consistent with the manifest
- **THEN** the install completes using precisely the locked versions

#### Scenario: Lockfile is stale or hand-edited

- **WHEN** the lockfile disagrees with the manifest, or references a local
  filesystem link instead of a published version
- **THEN** the install fails and the pipeline reports the inconsistency

### Requirement: The pipeline runs on a pinned toolchain version

The runtime version used by the pipeline SHALL be declared explicitly in the
repository and SHALL satisfy the declared requirements of the build and lint
tooling. It SHALL NOT depend on whichever version a hosted runner happens to
preinstall.

#### Scenario: Toolchain version is explicit

- **WHEN** the pipeline sets up its runtime
- **THEN** the version comes from a value declared in the repository, and the same
  value is discoverable by a developer setting up locally

#### Scenario: Runner default would be incompatible

- **WHEN** the hosted runner's preinstalled runtime does not satisfy the tooling's
  declared engine requirements
- **THEN** the pipeline still uses the pinned compatible version and the checks run
  normally

### Requirement: The frontend production build must succeed

The pipeline SHALL build the admin frontend in production mode and SHALL fail the
pull request if the build does not complete. The production build SHALL run
exactly once per pipeline run: the same build whose output is packaged into the
plugin is the build that this requirement verifies.

#### Scenario: Build succeeds

- **WHEN** the frontend sources compile and bundle without error
- **THEN** the build step passes

#### Scenario: Build fails

- **WHEN** the bundler reports any error
- **THEN** the step fails, the pull request is blocked, and the bundler's output is
  visible in the run log

#### Scenario: Bundle output is reused rather than rebuilt

- **WHEN** a later step in the run needs the production bundle
- **THEN** it consumes the output of the build already performed, and the bundler
  is not invoked a second time

### Requirement: Static analysis must report zero problems

The pipeline SHALL run the project's linter, its type checker and its formatting
check over the frontend sources. Both errors and warnings SHALL fail the run — the
project's lint configuration already treats warnings as fatal, and the pipeline
SHALL NOT relax that threshold.

#### Scenario: Lint reports an error or a warning

- **WHEN** the linter reports at least one error or at least one warning
- **THEN** the lint step fails and the pull request is blocked

#### Scenario: Type checker reports an error

- **WHEN** type checking reports at least one error
- **THEN** the typecheck step fails and the pull request is blocked

#### Scenario: Sources are not formatted

- **WHEN** any checked source file does not match the project's formatting rules
- **THEN** the formatting step fails and the pull request is blocked

#### Scenario: Generated files are exempt from formatting

- **WHEN** the formatting check runs
- **THEN** the dependency lockfile and build output are excluded from it, and a
  formatting failure can never be resolved by rewriting a lockfile

#### Scenario: A developer reproduces a failure locally

- **WHEN** a developer wants to reproduce a static-analysis failure from the run log
- **THEN** a single documented command run locally produces the same result, with
  no pipeline-only configuration involved

### Requirement: Frontend unit tests must pass

The pipeline SHALL execute the frontend unit test suite and SHALL fail the pull
request on any failing test.

#### Scenario: All tests pass

- **WHEN** every test in the suite passes
- **THEN** the test step passes

#### Scenario: A test fails

- **WHEN** at least one test fails
- **THEN** the step fails, the pull request is blocked, and the failing test's name
  and assertion appear in the run log

#### Scenario: The suite is empty or was not discovered

- **WHEN** the test runner discovers no test files at all
- **THEN** the step fails rather than reporting success on an empty run

### Requirement: The PHP sources must parse on every supported PHP version

The pipeline SHALL check that every PHP file in the plugin parses without error on
each PHP version the plugin declares support for, and SHALL execute the plugin's
standalone PHP tests on each of those versions.

#### Scenario: Syntax error on a supported version

- **WHEN** any tracked PHP file fails to parse on a supported PHP version
- **THEN** the run for that version fails and the pull request is blocked

#### Scenario: A standalone PHP test fails

- **WHEN** a `tests/test-*.php` script exits non-zero on any supported version
- **THEN** the step fails, the pull request is blocked, and the script's output is
  visible in the run log

#### Scenario: Version support is read from the plugin's own declaration

- **WHEN** the set of PHP versions under test is chosen
- **THEN** it covers the minimum version the plugin advertises to users, not only
  the version the maintainer develops on

### Requirement: Every required check reports on every pull request

A check marked as required SHALL run and report on every pull request targeting
`main`, regardless of which paths the pull request touches.

#### Scenario: Pull request touches no frontend file

- **WHEN** a pull request changes only documentation or only PHP sources
- **THEN** every required check still runs and reports, and the pull request does
  not wait indefinitely on a check that was skipped

### Requirement: One failing check does not hide the others

A single failing verification step SHALL NOT prevent the remaining verification
steps from running and reporting. A developer SHALL see the full set of problems
from one run.

#### Scenario: Lint fails while tests would also fail

- **WHEN** the lint step fails and the test suite also contains a failure
- **THEN** the run reports both failures, not only the first one encountered

#### Scenario: A step that consumes another step's output

- **WHEN** a step depends on an artefact produced by an earlier step
- **THEN** it does not run after that earlier step failed, so nothing downstream
  operates on stale or absent output

### Requirement: Credentials are never exposed by the pipeline

Registry credentials handled by the pipeline SHALL NOT appear in run logs, in
generated files that outlive the job, or in any uploaded artifact.

#### Scenario: Credential is used but not printed

- **WHEN** the pipeline writes registry configuration and installs dependencies
- **THEN** the token value appears nowhere in the run log, in any step's output, or
  in any artifact the run produces

#### Scenario: Pull request from a fork

- **WHEN** a pull request originates from a fork and therefore has no access to
  repository secrets
- **THEN** the run fails or skips with a clear message and does not leak the absence
  or presence of any secret value

### Requirement: The pipeline's checks and setup are documented

The repository SHALL document which checks run, the local command that reproduces
each one, the exact status-check names to mark as required, and the secrets an
operator must provision.

#### Scenario: Operator configures branch protection

- **WHEN** a repository administrator sets up the ruleset for `main`
- **THEN** repository documentation gives the exact settings and the exact
  status-check names to select — taken from a completed run, since a version
  matrix expands one job into several differently-named checks — with no guessing
  from the workflow file

#### Scenario: Developer hits a red check

- **WHEN** a developer sees a failing check on their pull request
- **THEN** repository documentation names the local command that reproduces it

### Requirement: The packaged plugin is verified, not the working tree

The pipeline SHALL run WordPress's own plugin checker against the plugin exactly
as it is distributed to users, assembled by the repository's release packaging.
It SHALL NOT check the repository working tree, which contains sources, tests,
planning documents and tooling that are never shipped.

The set of files under check SHALL be derived from the packaging process itself,
so that a change to what the plugin contains cannot leave the check inspecting a
stale or hand-maintained file list.

#### Scenario: A file that never ships produces no finding

- **WHEN** the repository contains a file that the packaging process excludes from
  the distributed plugin, and that file would violate a plugin rule
- **THEN** the check reports nothing about it, because it is not present in what
  is checked

#### Scenario: A file that ships is checked

- **WHEN** a file included in the distributed plugin violates a plugin rule
- **THEN** the check reports it against that file

#### Scenario: The shipped file list changes

- **WHEN** the packaging process starts including or excluding a file
- **THEN** the check follows that change with no separate list to update

### Requirement: Plugin errors block a merge and plugin warnings do not

A finding the checker classifies as an error SHALL fail the pipeline and block the
pull request. A finding it classifies as a warning SHALL be reported where a
developer can see it, on the pull request, and SHALL NOT block the merge.

Warnings SHALL remain visible. Suppressing a warning so that it neither blocks nor
appears is not an acceptable way to keep the pipeline green.

#### Scenario: The packaged plugin has an error

- **WHEN** the checker reports at least one error against the packaged plugin
- **THEN** the pipeline fails and the pull request is blocked

#### Scenario: The packaged plugin has only warnings

- **WHEN** the checker reports warnings and no errors
- **THEN** the pipeline passes, and each warning is visible to the developer on the
  pull request, attributed to its file

#### Scenario: The full report survives the run

- **WHEN** the check has run, whatever its result
- **THEN** the complete report is retrievable from the run, not only the portion
  rendered inline

### Requirement: A WordPress release does not by itself block merges

No pipeline check that gates a merge SHALL depend on the current WordPress release
being newer or older than a value declared in the repository. Compatibility
metadata falling behind a WordPress release is a maintenance task, not a defect in
the code under review, and SHALL NOT block unrelated work.

#### Scenario: WordPress publishes a new release

- **WHEN** a new WordPress version is released and the plugin's declared
  compatibility has not yet been updated
- **THEN** every pull request whose own content is sound still passes, and no
  previously green commit turns red without a commit

#### Scenario: The metadata is genuinely wrong in a checked file

- **WHEN** the packaged plugin's metadata violates a rule that does not depend on
  the live WordPress release
- **THEN** the check still reports it normally

### Requirement: The packaged plugin must load in WordPress

Verification SHALL include activating the packaged plugin in a WordPress
installation, so that a plugin which cannot load is caught before release rather
than by a user.

#### Scenario: The plugin activates

- **WHEN** the packaged plugin is activated in a WordPress installation carrying
  none of its optional integrations
- **THEN** activation succeeds and the remaining checks run

#### Scenario: The plugin fails to activate

- **WHEN** activation raises a fatal error
- **THEN** the pipeline fails and the pull request is blocked

