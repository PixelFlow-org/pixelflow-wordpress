## Purpose

Defines how the plugin's WordPress.org page is assembled and published: where the
listing images live, what reaches the SVN assets directory and the plugin package,
and the completeness and link checks that keep the page correct and within the
directory guidelines.

## ADDED Requirements

### Requirement: Listing images are kept out of the plugin package

Banner, screenshot and icon files used only by the WordPress.org page SHALL live
outside the directories copied into the plugin package, and the built plugin zip
SHALL contain none of them. Files the plugin loads at runtime SHALL remain in the
package.

#### Scenario: Built zip carries no listing images

- **WHEN** the plugin is built for production
- **THEN** the zip contains no `banner-*`, `screenshot-*` or `icon.*` file
- **AND** it still contains the runtime scripts under `assets/js/`

### Requirement: The SVN assets directory mirrors the listing images

Publishing SHALL make the WordPress.org SVN `assets/` directory contain exactly the
files of the repository's listing-image directory: new and changed files are added,
and files no longer present in the repository are removed from SVN.

#### Scenario: Superseded screenshots are removed

- **WHEN** SVN `assets/` holds `screenshot-1.jpg` and the repository's listing-image
  directory holds `screenshot-1.png` but no `screenshot-1.jpg`
- **THEN** after publishing, SVN `assets/` holds `screenshot-1.png` and no
  `screenshot-1.jpg`

#### Scenario: Runtime files do not leak into SVN assets

- **WHEN** publishing completes
- **THEN** SVN `assets/` contains no `js/` directory or other runtime plugin file

### Requirement: The banner is published in both sizes

The listing SHALL provide `banner-772x250` and `banner-1544x500` together, because
WordPress.org does not display the high-resolution banner without the standard one.

#### Scenario: Both banner sizes present

- **WHEN** the listing-image directory is inspected
- **THEN** it contains a 772×250 `banner-772x250.png` and a 1544×500
  `banner-1544x500.png` of the same artwork

### Requirement: Every screenshot has exactly one caption

The number of `screenshot-N` files SHALL equal the number of entries under the
readme's Screenshots section, numbered 1 to N without gaps, each number having one
file in one format.

#### Scenario: Captions and files match

- **WHEN** the readme lists ten screenshot captions
- **THEN** the listing-image directory contains `screenshot-1` to `screenshot-10`,
  one file each, and no `screenshot-11`

### Requirement: The readme links to the plugin's source code

While the plugin package ships compiled or minified code without its source, the
readme SHALL link to the public development repository, as WordPress.org guideline 4
requires.

#### Scenario: Source link present

- **WHEN** the published readme is rendered on WordPress.org
- **THEN** it contains a link to the plugin's public GitHub repository

### Requirement: Readme links resolve

Every external link in the readme SHALL resolve to a page that answers with HTTP
success at release time.

#### Scenario: No broken links at release

- **WHEN** each external URL in `readme.txt` is requested before tagging a release
- **THEN** every request ends in a 2xx response after redirects

### Requirement: The display name matches across readme and plugin header

The name in the readme title line and the `Plugin Name` header of the main plugin
file SHALL be identical, so the WordPress.org page and the site's Plugins screen show
the same name.

#### Scenario: Names agree

- **WHEN** the readme title and the plugin header are compared
- **THEN** both read "PixelFlow – Meta & TikTok Pixel + Conversions API for WooCommerce"
