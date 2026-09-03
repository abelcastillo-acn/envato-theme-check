## Purpose

Expose the review queue over the WordPress REST API with a plugin-issued import token so a browser extension (v2) can import and manage items directly without cookies, nonces or Envato credentials.

## ADDED Requirements

### Requirement: Queue endpoints SHALL exist under a plugin namespace
The plugin SHALL register, under `envato-theme-check/v1`: `POST /queue` (import a payload), `GET /queue` (list, with `status`, `search`, `per_page`, `page`), `PATCH /queue/{item_id}` (update `status` and/or `theme_slug`), `DELETE /queue/{item_id}`, `DELETE /queue?status=done&older_than_days=N` (purge), and `GET /queue/ping` (connectivity test).

#### Scenario: Import through REST
- **WHEN** a valid `etc-queue/1` payload with one new item is POSTed to `/queue`
- **THEN** the response is `201` with `{ "imported": 1, "updated": 0, "skipped": [], "items": [ { "item_id": "…", "post_id": …, "status": "pending", "created": true } ] }`

#### Scenario: List pending items
- **WHEN** `GET /queue?status=pending&per_page=50` is requested
- **THEN** the response lists pending items with `X-WP-Total` and `X-WP-TotalPages` headers

### Requirement: Requests SHALL be authenticated with a plugin-issued import token
The endpoints SHALL accept an `X-ETC-Token` header. The token SHALL be issued per user from the Review Queue page, shown once, stored only as a SHA-256 hash, revocable and regenerable, and SHALL expire after a configurable number of days (default 90). A valid token SHALL authenticate the request as the issuing user; that user SHALL still need the manage-options capability.

#### Scenario: Valid token
- **WHEN** a request carries a token whose hash matches the issuing user's stored hash and the user can manage options
- **THEN** the request is authorised

#### Scenario: Revoked token
- **WHEN** the user has revoked the token and a request still carries it
- **THEN** the response is `401`

#### Scenario: Cookie session without token
- **WHEN** a logged-in browser session calls the endpoints without `X-ETC-Token`
- **THEN** the response is `401` (cookie authentication is not accepted for these routes)

### Requirement: Token scope SHALL be limited to the queue namespace
A request authenticated by an import token SHALL be rejected with `403` when it targets any REST route outside `envato-theme-check/v1/`.

#### Scenario: Token used against core routes
- **WHEN** a request with a valid import token targets `/wp/v2/users`
- **THEN** the response is `403`

### Requirement: Payload validation SHALL match the admin importer
REST imports SHALL apply the same schema, `item_id`, host allowlist, length and size validation as the admin import, skipping invalid items with a reason.

#### Scenario: One invalid item in a batch
- **WHEN** a payload of 5 items contains one with an invalid `item_id`
- **THEN** 4 items are imported and `skipped` lists the invalid one with reason "item_id invalid"

### Requirement: Errors SHALL use the standard REST error shape
Errors SHALL be returned as `{ "code", "message", "data": { "status", … } }` with codes such as `etc_invalid_payload`, `etc_token_invalid`, `etc_token_scope`, `etc_not_found`.

#### Scenario: Unknown item
- **WHEN** `PATCH /queue/999` is requested for a non-existent item
- **THEN** the response is `404` with code `etc_not_found`

### Requirement: Cross-origin access SHALL be restricted
If browser-context cross-origin calls are enabled, the allowed origin SHALL be limited to `https://themeforest.net` and the `X-ETC-Token` header SHALL be listed in the allowed CORS headers; pre-flight `OPTIONS` requests SHALL succeed for the queue routes.

#### Scenario: Pre-flight from ThemeForest
- **WHEN** an `OPTIONS` request with `Origin: https://themeforest.net` targets `/queue`
- **THEN** the response allows that origin and the `X-ETC-Token` header

#### Scenario: Other origin
- **WHEN** a request carries `Origin: https://example.com`
- **THEN** no `Access-Control-Allow-Origin` header for that origin is returned

### Requirement: Local-only access SHALL be the default
By default the endpoints SHALL reject requests whose client address is not loopback or a private network address; this restriction SHALL be switchable off through a filter.

#### Scenario: Request from a public address
- **WHEN** a request arrives from `203.0.113.10` with a valid token
- **THEN** the response is `403`
