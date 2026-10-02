## Context

- `assets/` today serves two purposes: `build_plugin.sh` copies it into the plugin
  zip, and `publish_plugin.sh` copies it on top of the SVN `assets/` directory. The
  plugin loads only `assets/js/*` at runtime; `icon.svg` and the screenshots are not
  referenced from code.
- `publish_plugin.sh` only adds files. Stale SVN assets (the old `.jpg` screenshots
  once `.png` ones exist, and the `js/` copy that already sits in SVN `assets/`) are
  never removed.
- WordPress.org reads the description from the readme in `tags/<Stable tag>/`, so
  text changes reach the page only with a new tag. Assets are version-independent.
- The local `svn/` working copy is git-ignored.
- readme.txt uses `== Section ==` and `= Subsection =` headers. The brief is written
  in Markdown (`##` / `###`, tables for screenshots).

## Goals / Non-Goals

**Goals:**
- The page shows the brief's text, ten screenshots and the banner, with the three
  deviations listed in proposal.md.
- Listing images never ship in the plugin zip, and SVN `assets/` ends up as an exact
  mirror of the repository's listing-image directory.

**Non-Goals:**
- Rewording the brief to remove the accepted guideline risks (proposal.md, "Known,
  accepted risks").
- Moving the settings page to a top-level admin menu to match the mock-up screenshots.
- Publishing the removed developer-filter documentation elsewhere.

## Decisions

**`.wordpress-org/` as the listing-image directory.** It is the directory name the
community deploy tooling (10up's WordPress.org deploy action) uses, so a future CI
deploy can adopt it unchanged. Alternatives: keeping images in `assets/` (zip grows
to about 3.5 MB with files the plugin never loads) or excluding image globs from the
zip in `build_plugin.sh` (keeps two concerns in one directory, and every new asset
type needs a new exclusion).

**Mirror, not copy, in `publish_plugin.sh`.** Before copying, every file in SVN
`assets/` that has no counterpart in `.wordpress-org/` is `svn rm`-ed; then the
directory is copied and new files are `svn add`-ed. This removes the old `.jpg`
screenshots and the stray `js/` in one mechanism instead of a one-off manual cleanup
that the next asset rename would need again. The step keeps the script's existing
"show status, ask before commit" gate, so the removals are visible before anything is
committed.

**772×250 banner by halving the 1544×500 banner** with ImageMagick (Lanczos filter).
Both images have the same 3.088:1 aspect ratio, so there is no crop. The operator
chose this over asking the designer for a separate render.

**PNG screenshots stay as supplied** (1600×1200, about 200–360 KB each). WordPress.org
accepts PNG and JPG; re-encoding to JPG would blur the UI text in the mock-ups.

**Brief → readme.txt conversion.** Headings are mapped mechanically: `## Description`
etc. become `== … ==`, `### …` become `= … =`. Bullet lists, bold and links are kept
as Markdown, which the readme parser renders. The screenshots table becomes the
numbered `== Screenshots ==` list. Text is otherwise verbatim, apart from the three
deviations in proposal.md. Changelog is kept and gains the 1.2.1 line. Header fields
other than the title, tags and short description (`Requires at least`, `Tested up
to`, `Requires PHP`, license) are unchanged.

**`README.md`** gets the same marketing text in plain Markdown (headings one level
down under the existing `# PixelFlow WordPress Plugin`). Developer sections and
Changelog are left untouched apart from the new changelog entry.

**Version 1.2.1** follows the project rule for non-feature releases (third segment).
Changelog line: "Refreshed the WordPress.org listing (description, screenshots,
banner); listing images no longer ship inside the plugin zip."

## Risks / Trade-offs

- [The mirror step deletes from SVN more than intended if `.wordpress-org/` is missing
  or empty] → the step aborts when the directory does not exist or has no files, and
  the existing commit prompt shows every `D` line before committing.
- [Accepted guideline risks in the text lead to a reviewer email] → recorded in
  proposal.md; fixing them is a text-only follow-up release.
- [Downscaled banner text is less crisp than a native render] → inspect the 772×250
  output visually before committing; ask the designer if it is not legible.
- [Removing "Filters for Developers" leaves the `pixelflow_external_id` contract
  documented nowhere public] → accepted by the operator; the filter code and its
  docblocks are unchanged.

## Migration Plan

1. Land the repository changes through a PR to `main`.
2. Run `publish_plugin.sh`: it builds the 1.2.1 zip, refreshes trunk, mirrors
   `.wordpress-org/` into SVN `assets/`, and tags 1.2.1.
3. Check the plugin page: banner, ten screenshots with captions, new name and text.

Rollback: assets can be reverted by re-running the mirror from the previous commit's
`.wordpress-org/` (or the old `assets/` images); text by tagging a 1.2.2 with the
previous readme.

**Verification is targeted, not a full run.** No plugin logic changes, so the release
is checked by `php -l`, the zip's contents, the readme links, the screenshot/caption
count and a review of the SVN status before commit. The full suite is not run, so
`docs/test-scenarios.html` is not updated (a partial run does not update it).
Operator decision.
