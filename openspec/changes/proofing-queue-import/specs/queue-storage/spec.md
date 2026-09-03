## Purpose

Keep a local, private record of imported proofing-queue items — one per ThemeForest item id — with a review status, an optional mapping to an installed theme, and bounded retention.

## ADDED Requirements

### Requirement: Items SHALL be stored privately and keyed by item id
Imported items SHALL be stored as private records (not publicly queryable, not exposed through the public REST index, excluded from search) with the ThemeForest `item_id` as the unique key. Importing an item id that already exists SHALL update the existing record instead of creating a duplicate.

#### Scenario: Re-import of an existing item
- **WHEN** a payload contains item `51234567` that is already stored with a different title
- **THEN** the stored record is updated with the new title, `last_seen_at` is refreshed, and no second record is created

#### Scenario: Public visibility
- **WHEN** an anonymous visitor requests the site's front end or public REST index
- **THEN** no queue item is exposed

### Requirement: Stored fields SHALL mirror the capture payload plus review metadata
Each record SHALL store: `item_id`, `title`, `author`, `author_url`, `item_url`, `thumb_url`, `preview_url`, `excerpt`, `category`, `submitted_at`, `queue_status`, `raw` (≤ 2 KB), plus review metadata `status` (pending | in_review | done), `theme_slug` (installed theme mapping, nullable), `imported_at`, `last_seen_at`, and a `source_hash` used to detect changes.

#### Scenario: Unchanged item re-imported
- **WHEN** an item is re-imported with identical data
- **THEN** `last_seen_at` is updated and the record is reported as "unchanged"

### Requirement: Imported values SHALL be validated
`item_id` SHALL match `^\d{1,12}$`; URLs SHALL be valid `http(s)` URLs whose host is `themeforest.net`, a subdomain of `envato.com`, `envatousercontent.com` or `themeforest.net`; text fields SHALL be sanitized as plain text and truncated to 2,000 characters; `raw` SHALL be capped at 2 KB. Items failing validation SHALL be skipped with a reason and SHALL NOT abort the rest of the import.

#### Scenario: Item with an off-domain URL
- **WHEN** an item's `item_url` points to `https://evil.example/…`
- **THEN** that item is skipped with reason "item_url host not allowed" and the other items are imported

### Requirement: Thumbnails SHALL be stored as URLs only
The plugin SHALL NOT download or sideload thumbnail or preview images; it SHALL store the remote URL and render it with a no-referrer policy.

#### Scenario: Import with thumbnails
- **WHEN** ten items with `thumb_url` are imported
- **THEN** no files are added to the uploads directory

### Requirement: Status SHALL be one of three values
`status` SHALL be `pending` on first import, and SHALL be changeable to `in_review` and `done`; starting a Theme Check run from a queue item SHALL set its status to `in_review`.

#### Scenario: Check started from the queue
- **WHEN** the reviewer clicks "Check this theme" on a pending item and runs the check
- **THEN** the item's status becomes `in_review`

### Requirement: Installed theme mapping SHALL be suggested and editable
On import the plugin SHALL attempt to map an item to an installed theme by comparing the item title (segment before ` - ` or ` | `) with installed theme names/slugs; the reviewer SHALL be able to set or clear the mapping manually.

#### Scenario: Title matches an installed theme
- **WHEN** an item titled "Timbero - Parallax Multipurpose eCommerce WordPress Theme" is imported and a theme with slug `timbero` is installed
- **THEN** `theme_slug` is pre-set to `timbero`

### Requirement: Retention SHALL be bounded
Items with status `done` SHALL be deleted automatically after a configurable number of days (default 30) by a daily scheduled task; the reviewer SHALL be able to purge done items on demand; uninstalling the plugin SHALL delete all queue records, their metadata and the plugin's queue options.

#### Scenario: Old done item
- **WHEN** an item was marked done 31 days ago and the daily task runs
- **THEN** the item is deleted

#### Scenario: Uninstall
- **WHEN** the plugin is uninstalled through WordPress
- **THEN** no queue records, meta or queue options remain
