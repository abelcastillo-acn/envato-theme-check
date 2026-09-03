## 1. Inputs and approvals (before coding)

- [ ] 1.1 Obtain a saved, sanitized HTML sample of `themeforest.net/admin/awesome_proofing` from the reviewer and store it as `tests/fixtures/awesome_proofing.sample.html`; verify it contains the item list markup (rows with item links)
- [ ] 1.2 Confirm with Envato Security / Review lead that browser-side capture and local storage of queue metadata is acceptable, and agree the retention period; record the outcome in `docs/proofing-import.md`
- [ ] 1.3 Confirm whether unpacked/enterprise Chrome extensions are permitted on managed laptops and which browsers reviewers use; record in `docs/proofing-import.md` (gates section 6)

## 2. Storage

- [x] 2.1 Create `includes/class-queue-cpt.php` (CPT `etc_queue_item`, statuses `etc_pending|etc_in_review|etc_done`, `register_post_meta` with sanitizers) and register it from `theme-check.php`; verify `wp post-type list` shows the type as non-public
- [x] 2.2 Create `includes/class-queue-store.php` (`find_by_item_id`, `upsert`, `set_status`, `set_theme_slug`, `guess_theme_slug`, `purge_older_than`); verify with `wp eval` that upserting the same `item_id` twice yields one post and `guess_theme_slug( 'Timbero - …' )` returns `timbero`
- [x] 2.3 Add option `etc_queue_retention_days`, daily cron `etc_queue_cleanup`, and `uninstall.php`; verify `wp cron event run etc_queue_cleanup` purges a back-dated done item and that uninstall removes posts/meta/options

## 3. Importer and admin page

- [x] 3.1 Create `includes/class-queue-importer.php` (`validate_payload`, `import`, AJAX `etc_queue_import` with nonce + capability); verify a payload with one off-domain URL imports the other items and reports the skip reason
- [x] 3.2 Create `admin/queue-page.php` and `admin/class-queue-list-table.php` (submenu, columns, views with counts, search, bulk actions, inline status/theme AJAX `etc_queue_set_status` / `etc_queue_set_theme`, retention control, purge button); verify the page renders without running theme checks (no "Running N tests" and no increase in page time)
- [x] 3.3 Create `assets/queue.js` and `assets/queue.css` (hash decode, preview with duplicate marks, paste box, import call, `history.replaceState`, inline AJAX actions); verify opening the page with a hand-built `#import=` fragment shows the preview and imports on click
- [x] 3.4 Implement "Check this theme" → `themes.php?page=themecheck&themename={slug}&queue_item={post_id}` and set `etc_in_review` when the check runs (hook provided by `results-plain-text-message`); verify the Theme Check form is pre-selected and the results page shows the author username

## 4. Bookmarklet

- [x] 4.1 Write `tools/bookmarklet/queue-scraper.src.js` with `CONFIG.selectors`, collect/validate/encode, `window.open` hand-off and overlay fallback; `tools/bookmarklet/build.js` + `package.json` (terser) producing `dist/queue-scraper.min.js` and `dist/bookmarklet.txt`; verify `node build.js` produces both files and the bookmarklet is < 20 KB
- [ ] 4.2 Fill `CONFIG.selectors` from the HTML sample and write `tools/bookmarklet/test/scrape.test.js` (jsdom) asserting item count and fields against the fixture; verify `npm test` passes in `tools/bookmarklet`
- [x] 4.3 Render the bookmarklet install widget on the Review Queue page (draggable link via `esc_attr`, code textarea, target substituted with `admin_url()`); verify dragging the link to the bookmarks bar and clicking it on the queue page opens the plugin tab with a populated preview
- [x] 4.4 Verify fallback: block pop-ups in the browser and confirm the overlay with JSON + Copy appears; paste into the plugin's box and import

## 5. CLI, docs, release (v1)

- [x] 5.1 `theme-check-cli.php`: add `wp theme review queue list|import <file>|purge [--days] [--status]`; verify each subcommand against the Local site
- [x] 5.2 Write `docs/proofing-import.md` (feasibility, options table, security/compliance notes, install steps, troubleshooting "0 items", needs-from-user) and link it from `readme.md`; verify it renders on GitHub
- [x] 5.3 Bump plugin version and add a changelog entry; verify the plugin activates cleanly on a fresh Local site

## 6. v2 — REST token and extension (gated on 1.3)

- [ ] 6.1 Create `includes/class-import-token.php` (issue/verify/revoke, TTL, `determine_current_user`, scope guard) and the token panel on the Review Queue page; verify a request with a valid token to `/queue/ping` returns the user and a request to `/wp/v2/users` returns 403
- [ ] 6.2 Create `includes/class-rest-queue.php` (routes, schemas, permission callbacks, CORS filters, local-only guard); verify `POST /queue` imports the fixture payload and `OPTIONS` from `https://themeforest.net` returns the allow headers
- [ ] 6.3 Create `tools/extension/` (manifest, content script reusing the scraper, service worker POST, options page with Test connection); verify "Load unpacked" install captures the fixture page and items appear in the Review Queue
