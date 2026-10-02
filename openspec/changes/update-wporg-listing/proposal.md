## Why

The WordPress.org listing still describes the plugin as "Facebook Conversions API for
WooCommerce": Meta only, no TikTok, no form tracking, seven screenshots of older admin
screens and no banner at all. Since 1.1.20 (TikTok identifiers) and 1.2.0 (form events)
the plugin does considerably more than the page says. A new listing brief (2026-10-02)
supplies the name, short description, tags, description, installation steps, FAQ, ten
screenshots with captions and a 1544×500 banner.

## What Changes

**Listing text (`readme.txt`)**

- Plugin display name becomes "PixelFlow – Meta (Facebook) & TikTok Pixel + Conversions
  API for WooCommerce"; short description, tags, Description, Installation, FAQ and
  Privacy are replaced with the brief's text, converted to readme.txt syntax.
- The brief's text is taken verbatim except for three deliberate deviations:
  - Installation step 2 names the real menu location, **Settings → PixelFlow Settings**
    (`pixelflow.php:167`, `add_options_page`), instead of "Open PixelFlow in your
    WordPress admin".
  - Two links that return 404 are replaced: `pixelflow.so/docs` →
    `https://docs.pixelflow.so`, `pixelflow.so/support` → `https://pixelflow.so/contact`.
  - One line linking the source code on GitHub is added to Links. The zip ships only the
    minified admin bundle (`app/dist/index.js`), and guideline 4 requires either the
    source in the package or "a link in the readme to the development location"; today
    that link lives in the section being removed.
- **Removed**: the "Filters for Developers" and "Additional Information" sections, and
  the old "Privacy Policy" section (superseded by the brief's Privacy section). The
  `pixelflow_external_id` and `pixelflow_useragent_bot_patterns` filters themselves are
  unchanged; only their public description on WordPress.org goes away.
- Screenshot captions become the brief's ten captions.

**Plugin header (`pixelflow.php`)**

- `Plugin Name` takes the same display name; `Description` takes the brief's short
  description. Comment header only, no logic change.

**Listing images**

- New `.wordpress-org/` directory holds the WordPress.org assets: the ten
  `screenshot-N.png`, `banner-1544x500.png`, a `banner-772x250.png` produced by halving
  the supplied banner (WordPress.org does not show the retina banner without the
  772×250 one), and `icon.svg` moved from `assets/`.
- The seven `assets/screenshot-N.jpg` files are deleted. `assets/` keeps only `js/`,
  which the plugin loads at runtime.
- `publish_plugin.sh` mirrors `.wordpress-org/` into the SVN `assets/` directory,
  removing what is no longer there (the old `.jpg` screenshots and the stray `js/`
  copy), instead of copying `assets/` on top of it.
- `build_plugin.sh` is unchanged in what it copies. Because the images leave `assets/`,
  the plugin zip stops carrying them (about 0.7 MB today, about 2.7 MB if the new set
  had stayed there).

**Release 1.2.1**

- WordPress.org renders the readme of the tag that `Stable tag` points to, so the new
  text needs a new tag. Version bumped to 1.2.1 in all five places, with a one-line
  changelog entry.

**`README.md`**

- The marketing part (top description, Installation, FAQ, Privacy Policy) is replaced
  with the same text in Markdown. Developer sections (Development, CI, Deployment,
  integrations, Troubleshooting) and Changelog stay.

**Known, accepted risks** (from the guideline check against the Detailed Plugin
Guidelines; the operator chose to keep the brief's wording):

- The display name repeats "Meta (Facebook)" and three of the five tags; a reviewer may
  treat it as keyword stuffing (guidelines 9, 12).
- The Privacy section says events go to Meta and TikTok and does not mention that the
  plugin posts them to `api.pixelflow.so` first (guideline 7).
- "Honours … Do Not Track" has no counterpart in the plugin code; "GDPR and CCPA
  friendly" sits close to the ban on implying legal compliance (guideline 9).
- "Zero impact on page speed" while the plugin injects the PixelFlow browser script on
  storefront pages.
- Screenshots 2, 3, 9, 10 show a top-level "PixelFlow" admin menu item that does not
  exist.

## Capabilities

### New Capabilities

- `wordpress-org-listing`: how the plugin's WordPress.org page is assembled and
  published: where listing images live and how they reach SVN, what the plugin package
  must not carry, banner and screenshot completeness, the source-code link, and link
  health.

### Modified Capabilities

None.

## Impact

- Files: `readme.txt`, `README.md`, `pixelflow.php` (header comment and version
  constant), `publish_plugin.sh`, new `.wordpress-org/`, `assets/` (images removed).
- WordPress.org: SVN `assets/` contents replaced; new tag `1.2.1`. Sites get an update
  with no functional change.
- No PHP logic, React code, event payloads or settings change.
