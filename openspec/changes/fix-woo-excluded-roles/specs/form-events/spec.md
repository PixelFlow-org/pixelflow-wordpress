## MODIFIED Requirements

### Requirement: The excluded-roles setting states where it does not apply

The settings page SHALL title the control `Exclude Tracking for User Roles` and SHALL state that, for
a selected role, the plugin's tracking script is not loaded and form and WooCommerce events are not
sent while such a user is signed in. Once at least one role is selected, the page SHALL state that a
tracking script added to the site by hand is not affected by the setting. When WooCommerce tracking
is on, the page SHALL also state that a Purchase can still be sent when a request other than the
excluded user's own, such as a payment gateway webhook or another staff member, changes the order's
status; that statement SHALL NOT be shown when WooCommerce tracking is off, since such a site has no
such exception. These statements SHALL NOT change what any event sends.

#### Scenario: The setting states what it excludes

- **WHEN** the settings page is viewed
- **THEN** the control is titled `Exclude Tracking for User Roles` and says that the tracking script
  is not loaded and that form and WooCommerce events are not sent for the selected roles

#### Scenario: The hand-added script limit is stated

- **WHEN** the settings page is viewed with at least one role selected
- **THEN** the page states that a tracking script added to the site by hand is not affected

#### Scenario: The limitation is stated on a store

- **WHEN** the settings page is viewed on a site where WooCommerce is active and
  WooCommerce tracking is on
- **THEN** the control carries a note that a Purchase can still be sent when a request other than the
  excluded user's own changes the order's status

#### Scenario: The limitation is not stated without WooCommerce tracking

- **WHEN** the settings page is viewed on a site with no WooCommerce, or with
  WooCommerce tracking off
- **THEN** no such note is shown
