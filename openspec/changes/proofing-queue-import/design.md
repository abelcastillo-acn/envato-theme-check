## Context

See proposal.md – Why and Feasibility summary. Verified constraints:

- `https://themeforest.net/admin/awesome_proofing` returns `302` to `https://envato.cloudflareaccess.com/cdn-cgi/access/login/themeforest.net?…` — Cloudflare Access (Zero Trust SSO). Its DOM structure is unknown until the reviewer supplies a saved HTML sample.
- The reviewer's plugin runs on `http://themeforestcheck.local` (Local by WP Engine; WP 7.1; `WP_ENVIRONMENT_TYPE=local`, so Application Passwords are technically available over http). A page on `https://themeforest.net` cannot `fetch()` an `http://` origin (mixed content, blocked before CORS is even evaluated). WordPress auth cookies default to `SameSite=Lax` and REST cookie auth additionally requires `X-WP-Nonce`, which is unobtainable from themeforest.net.
- Browsers execute `javascript:` bookmarks regardless of the page's CSP; the page's CSP still governs anything the bookmarklet injects (`<script src>`, `<style>`, `fetch`). `window.open()` and `navigator.clipboard.writeText()` (with user activation) are not restricted by CSP.
- Plugin conventions: procedural PHP with a couple of classes, `manage_options` for everything, no build step. `theme-check-cli.php` already registers `wp theme review …`.
- Change `results-plain-text-message` owns the Theme Check form pre-selection (`?themename=`), the hidden `queue_item` field and the author pre-fill; this change consumes them.

## Goals / Non-Goals

**Goals:**
- Zero Envato credentials in the plugin; no bypass of Cloudflare Access.
- v1 usable in two clicks (bookmarklet → Import) with no browser-extension policy dependency.
- Data model simple enough to inspect/purge with core tools; bounded retention.
- v2 path (extension + REST token) fully specified so it can be built once policy allows.

**Non-Goals:**
- Downloading item files, installing themes, or writing back to ThemeForest.
- Multi-user sync of queue state (single reviewer, local site).

## Decisions

### D1. Transport: fragment hand-off (v1), extension + REST (v2)

| Option | Auth/CORS | Clicks | Blocking issue | Verdict |
|---|---|---|---|---|
| A. Bookmarklet copies JSON → paste in plugin | none | 3 | clipboard needs user activation (bookmarklet click provides it) | v1 fallback |
| B. Bookmarklet `window.open( pluginUrl + '#import=' + base64url(JSON) )`; plugin page reads `location.hash`, previews, imports same-origin with nonce | none (fragment never sent to a server) | 2 | URL length (Chrome ~2 MB, Firefox > 1 MB; 200 items × ~600 B ≈ 120 KB) | **v1 primary** |
| C. Bookmarklet `fetch()` POST to plugin REST | token + CORS + CSP `connect-src` | 1 | **mixed content** (https page → http Local site) blocks the request outright | rejected |
| D. Chrome MV3 extension: content script scrapes, service worker POSTs with `host_permissions` | token; no CORS/mixed-content in service worker | 1 | managed-laptop extension policy; maintenance | **v2** |

### D2. Bookmarklet design
`tools/bookmarklet/queue-scraper.src.js` (readable source) → `tools/bookmarklet/build.js` (Node + `terser`) → `tools/bookmarklet/dist/queue-scraper.min.js` and `dist/bookmarklet.txt` (`javascript:(function(){…})();` percent-encoded). `dist/` is committed so installing the plugin needs no build.

```js
const CONFIG = {
  target: '__ETC_TARGET__',          // replaced server-side with admin_url('themes.php?page=themecheck-queue')
  maxItems: 200, maxField: 2000, maxUrlBytes: 1.5 * 1024 * 1024,
  selectors: {                       // TO FILL from the saved HTML sample
    row: '', itemLink: 'a[href*="/item/"]', title: '', author: 'a[href*="/user/"]',
    thumb: 'img', excerpt: '', category: '', submitted: 'time, [datetime]',
    itemId: { from: 'itemLink', regex: /\/item\/[^/]+\/(\d+)/ }
  }
};
```
Flow: `collect()` → `validate()` → if 0 rows: overlay "No queue items found — selectors may need updating" → `encode()` (`btoa(unescape(encodeURIComponent(JSON.stringify(p))))` made URL-safe) → `window.open(target + '#import=' + enc)`; if `enc.length > maxUrlBytes` or `open()` returns null → `<dialog>` overlay with pre-selected `<textarea>` + Copy (`navigator.clipboard.writeText` then `execCommand` fallback). Styles via CSSOM only; no remote assets; no network. The plugin renders the install link with `esc_attr()` (not `esc_url()`, which strips `javascript:`) after substituting `__ETC_TARGET__`.

### D3. Plugin-side importer (shared by admin, REST and CLI)
`includes/class-queue-importer.php` — `ETC_Queue_Importer::validate_payload( array $payload ): array{items, skipped}` enforces: `schema === 'etc-queue/1'`; `item_id` `^\d{1,12}$`; URLs via `wp_http_validate_url()` + host allowlist (`themeforest.net`, `*.themeforest.net`, `*.envato.com`, `*.envatousercontent.com`); text via `sanitize_text_field`/`sanitize_textarea_field`, truncated to 2,000 chars; `raw` JSON ≤ 2 KB; max 200 items. `import( array $payload ): array{imported, updated, unchanged, skipped[]}` calls `ETC_Queue_Store::upsert()` per item. Admin path: `admin-ajax` action `etc_queue_import` with `check_ajax_referer( 'etc-queue-import' )` + `current_user_can( 'manage_options' )`.

Client side (`assets/queue.js`): on load, if `location.hash` starts with `#import=`, decode, validate schema, render preview table with duplicate marks (known `item_id`s injected server-side as JSON), "Import N items" → `fetch( ajaxurl, FormData )`, then `history.replaceState()` to clear the hash. Same preview for the paste textarea.

### D4. Storage: custom post type `etc_queue_item`
`includes/class-queue-cpt.php` registers on `init`: `register_post_type( 'etc_queue_item', [ public=false, show_ui=false, show_in_rest=false, exclude_from_search=true, supports=[title, editor, excerpt], capability_type=post, map_meta_cap=true ] )` and statuses `etc_pending`, `etc_in_review`, `etc_done` (`internal=true`). Mapping: `post_title` = title, `post_content` = description, `post_excerpt` = excerpt, `post_date` = `submitted_at`, `post_status` = status. Meta (registered with sanitize callbacks): `_etc_item_id` (unique), `_etc_author`, `_etc_author_url`, `_etc_item_url`, `_etc_thumb_url`, `_etc_preview_url`, `_etc_category`, `_etc_submitted_at`, `_etc_imported_at`, `_etc_last_seen_at`, `_etc_theme_slug`, `_etc_source_hash`, `_etc_raw`.

`includes/class-queue-store.php`: `find_by_item_id()`, `upsert()`, `set_status()`, `set_theme_slug()`, `guess_theme_slug( $title )` (compare `sanitize_title()` of the title segment before ` - `/` | ` with `wp_get_themes()` stylesheets and names), `purge_older_than( $days, ['etc_done'] )`.

*Alternatives rejected*: option array (no per-item querying, one autoloaded blob); custom table (schema migrations for tens–hundreds of rows). CPT gives CRUD, meta queries, `wp post` tooling and uninstall simplicity for free.

### D5. Admin page
`admin/queue-page.php` + `admin/class-queue-list-table.php` (`WP_List_Table`): registered via `add_theme_page( 'Review Queue', 'Review Queue', 'manage_options', 'themecheck-queue', … )`. Kept as a separate page (not a tab of Theme Check) because the Theme Check callback `include`s and instantiates all 60 checks on load. Columns: thumbnail (60 px, `referrerpolicy="no-referrer" loading="lazy"`), title (link, `target=_blank rel="noopener noreferrer"`), author (link), category, submitted, imported, status `<select>` (AJAX `etc_queue_set_status`), installed theme `<select>` (AJAX `etc_queue_set_theme`, pre-filled by `guess_theme_slug`), actions (Check this theme → `themes.php?page=themecheck&themename={slug}&queue_item={post_id}`, Mark done, Delete). Views with counts, search, bulk actions. Top: import widget (bookmarklet link + code + paste box), retention control, "Purge done items now".

### D6. Retention, CLI, uninstall
Option `etc_queue_retention_days` (30). Daily cron `etc_queue_cleanup` → `purge_older_than()`. `theme-check-cli.php`: `wp theme review queue list|import <file.json>|purge [--days=N] [--status=done]`. `uninstall.php` deletes all `etc_queue_item` posts + meta, options and the cron hook.

### D7. REST API and token (v2)
`includes/class-rest-queue.php` (`WP_REST_Controller`, namespace `envato-theme-check/v1`): routes per the spec. `includes/class-import-token.php`: `issue( $user_id )` → `etc_{user_id}_{32 hex}` from `random_bytes(16)`, stored as `hash( 'sha256', $token )` in user meta with issue date; shown once; revoke/regenerate; TTL option (90 days). Auth: `determine_current_user` (priority 20) reads `$_SERVER['HTTP_X_ETC_TOKEN']`, parses the user id, `hash_equals()` against the stored hash, requires `manage_options`, sets a static "token-authenticated" flag; `permission_callback` requires the flag (cookie sessions must use admin-ajax). Scope: `rest_authentication_errors` returns `WP_Error( 'etc_token_scope', …, 403 )` when the flag is set and the route is outside `/envato-theme-check/v1/`. Header `X-ETC-Token` (not `Authorization`, which Apache/PHP-FPM setups may strip and which collides with Application Passwords' Basic auth) added to `rest_allowed_cors_headers`. CORS: `allowed_http_origins` += `https://themeforest.net` so core's `rest_send_cors_headers()` emits the right headers; core answers `OPTIONS`. Default local-only guard on `REMOTE_ADDR` (loopback/RFC1918), filterable.

*Alternative rejected*: Application Passwords — available on Local (`WP_ENVIRONMENT_TYPE=local`), but grants the extension the user's entire REST surface and Basic auth carries the username; the plugin token is scoped to one namespace.

### D8. Extension (v2, design only)
`tools/extension/` MV3: `manifest.json` (`content_scripts.matches: ["https://themeforest.net/admin/awesome_proofing*"]`, `host_permissions: ["http://themeforestcheck.local/*", "https://themeforestcheck.local/*"]`), `content.js` (reuses `queue-scraper.src.js` collect/validate), `background.js` (service worker: `fetch( endpoint + '/queue', { headers: { 'X-ETC-Token': token } } )`), `options.html/js` (endpoint + token in `chrome.storage.local`, "Test connection" → `/queue/ping`). Requires "Load unpacked" or enterprise distribution; check `ExtensionInstallBlocklist`/`ExtensionSettings`/`DeveloperToolsAvailability` policies.

### D9. Fixture-driven selector maintenance
`tests/fixtures/awesome_proofing.sample.html` (sanitized sample from the reviewer) + `tools/bookmarklet/test/scrape.test.js` (Node + `jsdom`) asserting item count and fields. A selector regression fails the test and, at runtime, surfaces as the explicit "0 items" message.

## Risks / Trade-offs

- [DOM changes silently break capture] → single `CONFIG.selectors`, fixture test, explicit 0-items UX.
- [Fragment size limit] → 200-item cap, 1.5 MB guard, clipboard fallback.
- [`javascript:` href stripped by security plugins/kses contexts] → render with `esc_attr()` only; also expose the code in a textarea.
- [PII (author usernames) stored locally] → retention default 30 days, purge action, uninstall cleanup, no thumbnails downloaded; needs Envato Security sign-off.
- [Status not synced to ThemeForest] → documented; reviewers still update the real queue.
- [v2 token = local admin credential] → hashed at rest, scoped namespace, TTL, never in the bookmarklet, `chrome.storage.local` only.
- [Local proxy shows non-loopback `REMOTE_ADDR`] → verify with `/queue/ping`; guard is filterable.

## Migration Plan

1. v1 ships with the CPT, admin page, importer, bookmarklet and docs. No schema migration.
2. v2 (token + REST + extension) ships separately once policy questions are answered; v1 keeps working unchanged.
3. Rollback: deactivate; data stays until uninstall.

## Open Questions

- Are thumbnails on the proofing page publicly loadable without auth (decides `<img>` vs placeholder)? Needs the HTML sample.
- Is the queue paginated or infinite-scroll (affects `maxItems` and a "scroll to load all" step)?
- Retention period preferred by the Review team (default 30 days).
- Are unpacked or enterprise extensions allowed on managed laptops; which browser(s) are in use? (Gates v2.)
