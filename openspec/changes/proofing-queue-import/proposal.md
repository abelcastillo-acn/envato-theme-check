## Why

Reviewers start every review by opening the ThemeForest proofing queue (`https://themeforest.net/admin/awesome_proofing`), then manually re-typing item title, author and links into their notes and into the Theme Check workflow. The queue page is protected by Cloudflare Access (Envato SSO), so the plugin cannot fetch it server-side without handling Envato credentials — which we will not do. A capture that runs inside the reviewer's already-authenticated browser and hands the data to the local plugin removes the re-typing, pre-fills the author username for the review message, and gives the reviewer a local "Review Queue" list to work from.

## What Changes

- **Capture (v1):** a self-contained bookmarklet that scrapes the queue page DOM (item id, title, author, item URL, thumbnail URL, excerpt, category, submission date) and opens the plugin's Review Queue page in a new tab with the JSON payload in the URL fragment; fallback: copy the JSON to the clipboard and paste it into the plugin page.
- **Storage:** a private custom post type `etc_queue_item` with custom statuses (pending / in review / done) and post meta; thumbnails stored as remote URLs only; retention cron and uninstall cleanup.
- **Admin:** new submenu Appearance → Review Queue (list table with thumbnail, title, author, category, dates, status, mapped installed theme, actions), an import widget (bookmarklet install link + JSON paste box), and a "Check this theme" hand-off that opens Theme Check pre-selected and pre-fills the author username.
- **REST API (v2, gated):** `envato-theme-check/v1/queue` endpoints authenticated with a plugin-issued import token, intended for a browser extension (Chrome MV3) that can POST directly without the mixed-content/CORS limits a bookmarklet has.
- **Documentation:** `docs/proofing-import.md` with the feasibility analysis, transport options, security/compliance notes, and the list of inputs still needed from Envato (HTML sample, security sign-off, extension policy).

## Feasibility summary

| Option | Verdict |
|---|---|
| Server-side crawl from WordPress | Rejected — requires a Cloudflare Access service token from Envato Infra and would store Envato credentials in the plugin. |
| Bookmarklet `fetch()` to the local plugin | Not viable — themeforest.net is HTTPS and the Local site is `http://`, so the request is blocked as mixed content; WP cookies are not sent cross-site and no `X-WP-Nonce` is obtainable. |
| **Bookmarklet → new tab with `#import=<payload>`** | **v1** — the fragment never leaves the browser; import happens same-origin with a nonce and `manage_options`. Bookmarklets run regardless of the page's CSP as long as they are self-contained. |
| Chrome MV3 extension + REST token | v2 — no CORS/mixed-content constraints from a service worker with `host_permissions`; depends on managed-laptop extension policy. |

## Capabilities

### New Capabilities
- `queue-capture`: browser-side capture of queue items into a versioned JSON payload and hand-off to the plugin (fragment URL or clipboard).
- `queue-storage`: local storage of queue items with unique item id, statuses, theme mapping, retention and cleanup.
- `queue-admin`: Review Queue admin page (list, import widget, status/theme mapping, hand-off to Theme Check).
- `queue-rest-api`: token-authenticated REST endpoints for importing and managing queue items (v2).

### Modified Capabilities
<!-- None: the Theme Check form pre-selection and author pre-fill are specified in results-plain-text-message. -->

## Out of scope

- Downloading item zips or installing themes automatically (requires Envato authentication).
- Writing status changes back to the ThemeForest queue.
- Sideloading thumbnails.
- Building the browser extension itself in v1 (design only; gated on policy confirmation).

## Impact

- **Code:** new `includes/class-queue-cpt.php`, `includes/class-queue-store.php`, `includes/class-queue-importer.php`, `admin/queue-page.php`, `admin/class-queue-list-table.php`, `assets/queue.js`, `assets/queue.css`, `tools/bookmarklet/*`, `uninstall.php`, `docs/proofing-import.md`; v2 adds `includes/class-import-token.php`, `includes/class-rest-queue.php`, `tools/extension/*`. Modified: `theme-check.php` (registration), `theme-check-cli.php` (`queue` subcommands), `main.php` (hand-off, via `results-plain-text-message`).
- **Data:** posts/meta rows in the reviewer's local database (item metadata, author usernames = PII under Envato policy); default retention 30 days after "done"; purge command and uninstall cleanup.
- **Compliance:** scraping an internal admin tool and storing queue metadata locally needs sign-off from Envato Security / the Review lead before rollout. No Envato credentials are stored; Cloudflare Access is not bypassed.
- **Fragility:** DOM selectors will break when the proofing page changes; mitigated by a single selectors config, a saved HTML fixture and an explicit "0 items matched" message.
