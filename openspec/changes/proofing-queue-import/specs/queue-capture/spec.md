## Purpose

Capture the items visible on the ThemeForest proofing queue page from inside the reviewer's authenticated browser session and hand them to the local plugin without the plugin ever touching Envato credentials.

## ADDED Requirements

### Requirement: Capture SHALL run in the reviewer's browser session only
The capture tool SHALL execute in the context of the already-authenticated proofing page and SHALL NOT store, transmit or require any Envato/Cloudflare credential, cookie or token.

#### Scenario: Reviewer not logged in
- **WHEN** the reviewer triggers the capture on the Cloudflare Access login page instead of the queue
- **THEN** the tool reports "No queue items found on this page" and sends nothing

### Requirement: Captured items SHALL follow a versioned payload schema
The capture SHALL produce a JSON object `{ "schema": "etc-queue/1", "source": <page URL>, "captured_at": <ISO-8601 UTC>, "captured_by": <tool name/version>, "items": [ … ] }` where each item has `item_id` (string of 1–12 digits), `title`, `author`, `author_url`, `item_url`, `thumb_url`, `preview_url`, `excerpt`, `category`, `submitted_at`, `queue_status`, and an optional `raw` object capped at 2 KB. Missing fields SHALL be present as empty strings.

#### Scenario: Item without a thumbnail
- **WHEN** a queue row has no image
- **THEN** the item is captured with `thumb_url` equal to `""` and all other fields filled

### Requirement: Hand-off SHALL open the plugin page with the payload in the URL fragment
On success the tool SHALL open the plugin's Review Queue page in a new tab with `#import=` followed by the URL-safe base64 encoding of the payload. The fragment SHALL never be sent to any server.

#### Scenario: Twenty items captured
- **WHEN** 20 items are captured from the queue page
- **THEN** a new tab opens on the Review Queue page whose URL fragment decodes to a payload with 20 items

### Requirement: Clipboard fallback SHALL be offered
When the encoded payload exceeds 1.5 MB or opening the new tab fails, the tool SHALL show an overlay with the raw JSON pre-selected and a "Copy" button so the reviewer can paste it into the plugin's import box.

#### Scenario: Pop-up blocked
- **WHEN** the browser blocks the new tab
- **THEN** the overlay with the JSON and Copy button is shown on the queue page

### Requirement: The tool SHALL be self-contained and CSP-safe
The capture tool SHALL NOT load remote scripts or stylesheets, SHALL NOT perform network requests from the queue page, and SHALL apply styles only via element style properties or classes so it works under a strict Content-Security-Policy.

#### Scenario: Strict CSP on the queue page
- **WHEN** the page's CSP forbids inline scripts and external script sources
- **THEN** the capture still runs and hands off via the new tab or overlay

### Requirement: Selectors SHALL be centrally configurable and failures explicit
DOM selectors for rows and fields SHALL live in a single configuration object; when the row selector matches zero elements the tool SHALL report "No queue items found — selectors may need updating" with a link to the documentation, instead of silently opening an empty import.

#### Scenario: Page markup changed
- **WHEN** the row selector matches nothing
- **THEN** the reviewer sees the explicit message and no tab is opened

### Requirement: The capture tool SHALL be installable from the plugin
The Review Queue page SHALL show the bookmarklet as a draggable link (bookmark) and as copyable code, with the target URL set to the current site's Review Queue page.

#### Scenario: Different local site URL
- **WHEN** the plugin runs on `http://another-site.local`
- **THEN** the bookmarklet offered on that site targets `http://another-site.local/wp-admin/themes.php?page=themecheck-queue`

### Requirement: Capture SHALL be limited in size
The tool SHALL capture at most 200 items per run and SHALL truncate each text field to 2,000 characters.

#### Scenario: Very long queue
- **WHEN** the page lists 350 items
- **THEN** the payload contains the first 200 and the overlay/status notes that 150 were not captured
