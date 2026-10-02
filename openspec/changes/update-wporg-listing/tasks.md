## 1. Listing images

- [x] 1.1 Create `.wordpress-org/` and copy, from the listing brief folder the operator supplies (its local path is not recorded in the repo), the ten `screenshot-1.png` … `screenshot-10.png` and `banner-1544x500.png` into it unchanged
- [x] 1.2 Generate `.wordpress-org/banner-772x250.png` by halving `banner-1544x500.png` (ImageMagick, Lanczos); confirm 772×250 with `identify` and check the text is legible
- [x] 1.3 `git mv assets/icon.svg .wordpress-org/icon.svg`
- [x] 1.4 `git rm assets/screenshot-1.jpg … assets/screenshot-7.jpg`; confirm `assets/` now holds only `js/`

## 2. Publish script

- [x] 2.1 In `publish_plugin.sh` Step 4, take assets from `.wordpress-org/` instead of `assets/`; abort if the directory is missing or empty
- [x] 2.2 Before copying, `svn rm` every entry of SVN `assets/` that has no counterpart in `.wordpress-org/` (covers the old `.jpg` screenshots and the stray `js/`), then copy and `svn add` new files
- [x] 2.3 Update the script's messages/summary to name `.wordpress-org/`; `sh -n publish_plugin.sh` passes

## 3. readme.txt

- [x] 3.1 Title line `=== PixelFlow – Meta & TikTok Pixel + Conversions API for WooCommerce ===`; `Tags:` and the short description from the brief; other header fields unchanged
- [x] 3.2 Replace Description with the brief's text, `###` headings converted to `= … =`; in Links use `https://docs.pixelflow.so` (Documentation) and `https://pixelflow.so/contact` (Support), and add `[Source code on GitHub](https://github.com/PixelFlow-org/pixelflow-wordpress)`
- [x] 3.3 Replace Installation with the brief's five steps, step 2 reading "Go to **Settings → PixelFlow Settings** and click **Go to Dashboard** to create your free account. No credit card needed."
- [x] 3.4 Replace Frequently Asked Questions with the brief's FAQ in `= Question =` form
- [x] 3.5 Replace Screenshots with the brief's ten captions as a numbered list
- [x] 3.6 Remove "Filters for Developers", "Privacy Policy" and "Additional Information"; keep Changelog
- [x] 3.7 Diff the result against the brief: the only textual differences are the installation step 2, the two link URLs and the GitHub line

## 4. README.md

- [x] 4.1 Replace the marketing block (top description through "Additional options"), Installation, the FAQ and Privacy Policy with the brief's text in Markdown; leave WooCommerce Features, Development, CI, Deployment, integration sections, Troubleshooting, Requirements, Support, License, Credits untouched
- [x] 4.2 Apply the same three deviations as in readme.txt (menu path, two links, GitHub link)

## 5. Plugin header and version 1.2.1

- [x] 5.1 `pixelflow.php`: `Plugin Name:` = the new display name, `Description:` = the brief's short description
- [x] 5.2 Bump to 1.2.1: `pixelflow.php` `Version:` and `PIXELFLOW_VERSION`, `readme.txt` `Stable tag:`
- [x] 5.3 Changelog entry `1.2.1` at the top of `readme.txt` and `README.md`: "Refreshed the WordPress.org listing (description, screenshots, banner); listing images no longer ship inside the plugin zip."

## 6. Verification

- [x] 6.1 `php -l pixelflow.php`
- [x] 6.2 `sh build_plugin.sh prod`; `unzip -l build/pixelflow.zip` shows `assets/js/held-events.js` and `assets/js/held-form-events.js` and no `banner-*`, `screenshot-*` or `icon.*`
- [x] 6.3 Every external URL in `readme.txt` answers 2xx after redirects (curl loop)
- [x] 6.4 Number of screenshot captions in `readme.txt` = number of `screenshot-N.png` in `.wordpress-org/` = 10, numbered without gaps
- [x] 6.5 `readme.txt` title and `pixelflow.php` `Plugin Name` are identical
- [x] 6.6 `openspec validate update-wporg-listing --strict`

## 7. Release

- [ ] 7.1 Commit on a branch, open a PR to `main`, merge after CI is green
- [ ] 7.2 Run `publish_plugin.sh`; at the commit prompt review `svn status`: `D` only for the old `.jpg` screenshots and `assets/js`, `A` for the new images, trunk changes as expected; then confirm commit and tag 1.2.1
- [ ] 7.3 Check wordpress.org/plugins/pixelflow/: new name, banner, ten screenshots with captions, new description
