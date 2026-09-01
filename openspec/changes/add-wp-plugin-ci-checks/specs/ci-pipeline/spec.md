## ADDED Requirements

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

## MODIFIED Requirements

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
