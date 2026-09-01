# wordpress-release-tracking Specification

## Purpose
Keeps the plugin's declared WordPress compatibility from silently falling behind
the platform. A plugin whose `Tested up to` value lags the current WordPress
release is dropped from WordPress.org search results, so the lag has to surface as
work for the maintainer rather than as a quiet loss of visibility.
## Requirements
### Requirement: A WordPress release the plugin has not caught up with is reported

The repository SHALL detect, without anyone asking, that the current WordPress
release is newer than the version the plugin declares it was tested against, and
SHALL report that as tracked work assigned to the repository.

Detection SHALL run on a schedule rather than only in response to repository
activity, because the triggering event happens outside the repository: WordPress
ships a release whether or not anyone commits.

#### Scenario: WordPress releases a newer version

- **WHEN** the current WordPress release is newer than the plugin's declared tested
  version, and the repository has seen no commit since
- **THEN** the repository carries an open item recording the gap, naming the
  version the plugin must be tested against

#### Scenario: The declared version is current

- **WHEN** the plugin's declared tested version matches the current WordPress
  release
- **THEN** nothing is reported and no item is opened

#### Scenario: The gap is already recorded

- **WHEN** the gap is detected again while an item for it is still open
- **THEN** no duplicate item is created

### Requirement: Only finished WordPress releases count

Detection SHALL compare against WordPress releases that have actually shipped, not
against beta or release-candidate builds. Declaring compatibility with a version
that does not yet exist for users is a false claim, and reacting to a
pre-release would produce one.

#### Scenario: A release candidate is published

- **WHEN** WordPress publishes a release candidate newer than the plugin's declared
  tested version, and no final release of that version exists yet
- **THEN** nothing is reported

#### Scenario: The final release follows

- **WHEN** that version reaches its final release
- **THEN** the gap is reported

### Requirement: The report never blocks a merge

Reporting a compatibility gap SHALL NOT cause any pull request to fail, and SHALL
NOT depend on a pull request existing. It is a notification about repository
maintenance, and its failure mode is a missed notification, not a blocked
contributor.

#### Scenario: A gap exists while a pull request is open

- **WHEN** a compatibility gap is outstanding and a contributor opens a pull request
  unrelated to it
- **THEN** that pull request's checks are unaffected by the gap

#### Scenario: Detection itself fails

- **WHEN** the detection run cannot complete, for example because the release
  information is unreachable
- **THEN** no pull request is blocked, and the failure is visible in the repository's
  workflow history

