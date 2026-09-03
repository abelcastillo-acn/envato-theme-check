# Importing the ThemeForest proofing queue — feasibility, design and usage

Status: **v1 implemented (2.3.0), pending: real page selectors and internal approvals**. Specs and tasks live in `openspec/changes/proofing-queue-import/`.

## Using it (v1)

1. In WordPress go to **Appearance → Review Queue**. Drag the **Import ThemeForest queue** button to your bookmarks bar (or copy the code into a new bookmark's URL field).
2. Open `https://themeforest.net/admin/awesome_proofing` (logged in as usual) and click the bookmark. A new tab opens on the Review Queue page with a preview of the captured items (already-imported ones are marked). Click **Import N items**.
   - If the tab does not open (pop-up blocked) or the list is huge, the bookmarklet shows an overlay with the JSON: copy it and paste it into **Paste a captured payload instead** on the Review Queue page.
   - If it reports **No queue items found**, the page markup differs from the selectors in `tools/bookmarklet/queue-scraper.src.js` (`CONFIG.selectors`); save the page HTML and update the selectors, then `node build.js` in `tools/bookmarklet`.
3. Map each item to the installed theme (auto-guessed from the title), then **Check this theme**: Theme Check opens pre-selected, the item moves to *In review*, and the author username is pre-filled in the message panel.
4. Mark items *Done*; they are purged after the retention period (default 30 days) or with **Purge done items now**.

WP-CLI: `wp theme review queue list [--status=…] [--format=json]`, `wp theme review queue import capture.json`, `wp theme review queue purge [--days=N]`.

Filters: `etc_queue_allowed_hosts` (URL allow-list for imported links), `themecheck_author_username`, action `themecheck_run_from_queue`.

## Goal

Let a reviewer pull the items waiting in `https://themeforest.net/admin/awesome_proofing` (title, author, links, thumbnail, excerpt, category, submission date) into the local Envato Theme Check install, so they can track what is pending, jump into a check with one click, and have the author username pre-filled in the review message.

## What we verified

- The proofing page is behind **Cloudflare Access** (Envato SSO): an unauthenticated request is redirected to `envato.cloudflareaccess.com/cdn-cgi/access/login/themeforest.net`. Server-side access would need a Cloudflare Access *service token* issued by Envato Infra — the plugin would then hold Envato credentials. **Rejected.**
- The reviewer's plugin runs on `http://themeforestcheck.local`. A script on the HTTPS ThemeForest page cannot `fetch()` an `http://` origin — browsers block it as **mixed content** before CORS is considered. WordPress cookies are `SameSite=Lax` and REST cookie auth needs `X-WP-Nonce`, which is unobtainable from themeforest.net. So a bookmarklet **cannot** post directly to the local plugin.
- Browsers run `javascript:` bookmarks regardless of the page's Content-Security-Policy; the CSP only restricts what the bookmarklet injects or fetches. `window.open()` and clipboard writes (with user activation) are allowed.
- We have **no sample of the page's DOM** yet; selectors cannot be written until a reviewer saves the page HTML.

## Options

| Option | How | Verdict |
|---|---|---|
| Server-side crawl | WP cron fetches the page with a Cloudflare service token | Rejected: credentials in the plugin, Infra dependency |
| Bookmarklet → `fetch()` | Script posts JSON to the plugin's REST API | Not viable: mixed content, no cross-site auth |
| **Bookmarklet → new tab** | Script scrapes the DOM, encodes JSON, opens `http://<site>/wp-admin/themes.php?page=themecheck-queue#import=<payload>`; the plugin page previews and imports same-origin with a nonce | **v1.** Two clicks. The fragment never leaves the browser. Fallback: copy JSON → paste into the plugin |
| Chrome extension (MV3) | Content script scrapes; service worker POSTs to the plugin REST API with a plugin-issued token (`host_permissions` bypass CORS/mixed-content) | **v2**, gated on managed-laptop extension policy |

## v1 flow

1. Reviewer drags the "Import ThemeForest queue" bookmarklet from the plugin's Review Queue page to the bookmarks bar (one time).
2. On the proofing page, reviewer clicks the bookmarklet. It reads the item rows, builds an `etc-queue/1` payload (max 200 items), and opens the plugin's Review Queue page with the payload in the URL fragment. If the tab is blocked or the payload is too large, an overlay offers the JSON to copy.
3. The Review Queue page shows a preview (duplicates marked) → "Import N items" → items are stored as private `etc_queue_item` records.
4. Reviewer maps an item to the installed theme (auto-guessed from the title), clicks "Check this theme"; Theme Check opens pre-selected; after the run, the message panel has the author username pre-filled and the item is marked *in review*.

## Data stored locally

Item metadata and the author's ThemeForest username (PII under Envato policy), in the reviewer's local WordPress database. Thumbnails are stored as remote URLs only — nothing is downloaded. Items marked *done* are purged after 30 days (configurable) by a daily task; there is a "Purge now" action and uninstall removes everything.

## Security posture

- No Envato/Cloudflare cookies, tokens or passwords are read, stored or transmitted by the plugin.
- Import requires `manage_options` and a WordPress nonce (v1) or a plugin-issued, hashed, revocable, namespace-scoped token (v2). Application Passwords were considered and rejected because they grant the whole REST surface.
- REST endpoints (v2) default to loopback/private-network clients only and allow CORS only for `https://themeforest.net`.
- URLs in payloads are validated against an allowlist of Envato hosts; text is sanitized and truncated.

## Fragility and maintenance

DOM selectors are the weak point. They live in one `CONFIG.selectors` object in `tools/bookmarklet/queue-scraper.src.js`; a saved page sample in `tests/fixtures/awesome_proofing.sample.html` drives a jsdom test. If the page changes, the bookmarklet reports "No queue items found — selectors may need updating" instead of importing nothing silently.

## Needed before implementation

1. **Saved HTML** of the proofing page (after load, internal names sanitized) → selectors, pagination/infinite-scroll behaviour, whether thumbnails load without auth.
2. **Sign-off** from Envato Security / the Review lead on browser-side capture and local storage of queue metadata; preferred retention period.
3. **Extension policy**: are unpacked or enterprise-distributed Chrome/Edge extensions allowed on managed laptops? Which browsers do reviewers use? (Gates v2.)

## Out of scope

Downloading item zips, installing themes, or updating the ThemeForest queue from the plugin.
