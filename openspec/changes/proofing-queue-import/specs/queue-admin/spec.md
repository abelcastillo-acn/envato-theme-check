## Purpose

Give reviewers a Review Queue page in WordPress admin to import captured items, see what is pending, track status, map items to installed themes and jump into Theme Check with the author pre-filled.

## ADDED Requirements

### Requirement: A Review Queue page SHALL exist under Appearance
The plugin SHALL register a submenu "Review Queue" under Appearance, sibling of Theme Check, restricted to users who can manage options. The page SHALL NOT load or run the theme checks.

#### Scenario: Page load
- **WHEN** an administrator opens Appearance → Review Queue
- **THEN** the page renders the list, the import widget and the retention controls without executing any theme check

#### Scenario: Insufficient capability
- **WHEN** a user without `manage_options` requests the page
- **THEN** access is denied

### Requirement: Import SHALL accept the payload from the URL fragment or a paste box
When the page URL contains `#import=<payload>`, the page SHALL decode it client-side, validate the schema, show a preview table (title, author, item id, submitted date, duplicate marker) and an "Import N items" button; the same preview SHALL be available for JSON pasted into a textarea. The actual import SHALL be a same-origin request protected by a nonce and the manage-options capability, and the fragment SHALL be cleared from the URL after a successful import.

#### Scenario: Hand-off from the bookmarklet
- **WHEN** the page opens with a valid fragment containing 12 items, 3 of which already exist
- **THEN** the preview shows 12 rows with 3 marked as "already imported" and the button reads "Import 12 items"

#### Scenario: Invalid payload
- **WHEN** the fragment or pasted text is not valid `etc-queue/1` JSON
- **THEN** an error "Unrecognised payload" is shown and nothing is imported

#### Scenario: Import without nonce
- **WHEN** an import request is sent without a valid nonce
- **THEN** it is rejected and no items are stored

### Requirement: Import results SHALL be reported
After an import the page SHALL show how many items were created, updated, unchanged and skipped, listing each skipped item id with its reason.

#### Scenario: Mixed import
- **WHEN** 10 items are imported: 6 new, 3 existing, 1 invalid
- **THEN** the page reports "6 imported, 3 updated, 1 skipped" and lists the skipped id and reason

### Requirement: The list SHALL show items with status filters, search and bulk actions
The list SHALL display thumbnail, title (linked to the item, opening in a new tab), author (linked), category, submitted date, imported date, status control, installed-theme control and actions; SHALL offer views All / Pending / In review / Done with counts; SHALL support search by title, author or item id; and SHALL support bulk "Mark done" and "Delete".

#### Scenario: Filter pending
- **WHEN** the reviewer clicks the "Pending" view
- **THEN** only items with status pending are listed and the view shows their count

#### Scenario: Change status inline
- **WHEN** the reviewer changes an item's status control to "Done"
- **THEN** the status is saved without a full page reload and the view counts update

### Requirement: "Check this theme" SHALL hand off to Theme Check
Each item with a mapped installed theme SHALL offer a "Check this theme" action that opens Theme Check with that theme pre-selected and the item reference attached; items without a mapping SHALL show the action disabled with a hint to map a theme first. The check SHALL still be started by the reviewer's form submission with the Theme Check nonce.

#### Scenario: Mapped item
- **WHEN** the reviewer clicks "Check this theme" on an item mapped to `timbero`
- **THEN** Theme Check opens with Timbero selected in the form and, after submitting, the results page pre-fills the author username with the item's ThemeForest author and the item status becomes in review

#### Scenario: Unmapped item
- **WHEN** an item has no mapped theme
- **THEN** the action is disabled with the hint "Select an installed theme first"

### Requirement: Bookmarklet install widget SHALL be present
The page SHALL show the capture bookmarklet as a draggable link and as copyable code, with the target set to this site's Review Queue URL, plus a short "how to install" note.

#### Scenario: Copy the bookmarklet code
- **WHEN** the reviewer clicks "Copy code"
- **THEN** the `javascript:` bookmarklet code is copied to the clipboard

### Requirement: Retention controls SHALL be available
The page SHALL show the current retention period (days) with a control to change it and a "Purge done items now" action with confirmation.

#### Scenario: Purge now
- **WHEN** the reviewer confirms "Purge done items now"
- **THEN** all items with status done older than the retention period are deleted and the count of deleted items is shown

### Requirement: External links SHALL be safe
Links to ThemeForest SHALL open in a new tab with `rel="noopener noreferrer"`, and thumbnails SHALL be rendered with `referrerpolicy="no-referrer"` and lazy loading.

#### Scenario: Item link
- **WHEN** the reviewer inspects an item title link
- **THEN** it has `target="_blank"` and `rel="noopener noreferrer"`
