# Hotel edge node (SPEC §7.5a)

An edge node is the same application running on a small server on the hotel LAN, with its own
PostgreSQL, for **one hotel**. Staff devices talk to the edge; the edge syncs with the cloud every
minute. When the internet line is down, front desk, folios, POS, housekeeping and maintenance keep
working on the edge and catch up on reconnect.

Code: `app/Modules/Edge/**`. Screen: `/edge` (permission `edge.manage`). Tests: `tests/Feature/Edge/**`.

## Setup

1. **Cloud:** a user with `edge.manage` opens *Edge sync* for the hotel and registers a node
   (or `POST /api/v1/edge/nodes {"name": "..."}` with `X-Hotel-Id`). The response shows
   `EDGE_NODE_ID` and `EDGE_SECRET` **once**. The secret is stored encrypted on the cloud.
2. **Edge server:** restore a snapshot of the cloud database (`pg_dump` of the cloud, filtered to
   the hotel if wanted) into the local PostgreSQL, deploy the same release, then set real
   environment variables (not only `.env` if config is cached):

   | Variable | Meaning |
   |---|---|
   | `EDGE_MODE=true` | run as an edge node |
   | `EDGE_HOTEL_ID` | the hotel this node serves |
   | `EDGE_NODE_ID`, `EDGE_SECRET` | from step 1 |
   | `EDGE_CLOUD_URL` | e.g. `https://app.opeshotelsoft.com` |
   | `EDGE_NUMBER_RANGE_START` | default `500000` (see numbering) |
   | `EDGE_BATCH_SIZE` | default `200` changes per request |

3. Run `php artisan edge:init` once on the edge. It moves every bigint id sequence to
   `EDGE_NODE_ID × 10^12` and installs the document-number trigger.
4. The scheduler runs `edge:sync` every minute in edge mode (`routes/console.php`). It is a no-op
   when `EDGE_MODE` is off.

## How changes are captured

`EdgeModule` listens to the global Eloquent `saved` / `deleted` events, so no model or shared trait
was edited. A write is logged in `edge_changes` (table, row id, op, group, hotel, timestamp) when the
model lives in `App\Modules\*` (or is `User`) and carries `group_id`/`hotel_id`, is a `hotels` row, or
is a known child table (`TablePolicy::PARENTS`, e.g. `pos_check_lines` → `pos_checks`).

- The log row is written in the caller's transaction: a rolled-back write leaves no change.
- The log keeps references only. The current row is read when the batch is built, so a batch always
  carries the final state of each row, once, ordered by its **first** change (parents before children).
- On the cloud, capture runs only for groups that have an active edge node.
- Not captured: query-builder bulk writes (`Model::where()->update()`, `DB::table()`), pivot tables
  (`hotel_user`, role tables), platform tables (`domain_events`, `idempotency_keys`,
  `number_sequences`, outboxes). Domain events are **not** replayed: their effects (GL entries, folio
  lines, stock movements) already exist as rows on the origin and travel as rows. This avoids
  double posting.

## Sync protocol

Endpoints (cloud): `POST /api/v1/edge/sync/push` and `POST /api/v1/edge/sync/pull`.

**Signed edge token.** Each request carries `X-Edge-Node`, `X-Edge-Timestamp` and
`X-Edge-Signature = HMAC-SHA256(secret, METHOD \n /path \n timestamp \n sha256(body))`. Requests older
than 5 minutes, with a wrong signature, or from a revoked node get `401`. Use HTTPS.

**Push (edge → cloud).** The edge sends the changes after its acknowledged `push_cursor`:
`{batch_key, from_cursor, to_cursor, changes: [{seq, table, row_id, op, changed_at, group_id, hotel_id, data}]}`.
In one transaction the cloud locks the node row, and:
- if `batch_key` was seen, returns the stored response (`replayed: true`);
- skips every change with `seq <= node.push_cursor` (already applied);
- applies the rest under the conflict rules, each in a savepoint;
- sets `node.push_cursor = to_cursor`, stores the response under the batch key, and returns
  `{ack_cursor, applied, skipped, conflicts}`. The edge stores `ack_cursor`.

Lost response → the edge resends the same batch key → same answer, nothing applied twice.

**Pull (cloud → edge).** `{cursor, limit}` returns changes for this hotel (and group-level rows of
its group) after `cursor`, excluding changes that came from this node:
`{changes, next_cursor, has_more}`. The edge applies a page and stores `next_cursor` in **one local
transaction**, so an interruption resumes exactly from the last committed page.

Order per run: push first, then pull, so local edits reach the cloud before cloud decisions come back.

## Conflict rules (`TablePolicy`)

| Class | Tables (examples) | Rule |
|---|---|---|
| Master data | rate plans, rates, room types, room inventory, menu items, price lists, chart of accounts, tax/charge codes, payment methods, users, stock items, suppliers | **Cloud wins.** Edits made on the edge are rejected (`master_cloud_wins`) and the cloud row is re-sent so the edge reverts on the next pull. Edit master data in the cloud only. |
| Financial | journal entries/lines, folio lines, invoices, POS payments, payment intents/refunds, loyalty transactions, stock movements, supplier invoices/payments, police register | **Append only.** New rows are inserted. An existing row only gains values in empty columns; a stored non-null value is never overwritten; deletes are refused (`financial_append_only`). Last-writer-wins is never used for money. |
| Operational | rooms (HK status), reservations, folios (header), POS checks, HK tasks, work orders, guests, cashier shifts, hotels (business date)... | **Last writer wins** on `changed_at`. The cloud keeps its row when it has a later change from another origin (`lww_kept_newer`); the edge keeps its row when it has a later change not yet pushed. |

Every decision that is not a plain apply is written to `edge_conflicts` (side, table, row, rule,
detail, incoming row) and shown on the sync screen. Rows that fail to apply (e.g. a foreign key)
are logged as `apply_failed` and do not block the batch.

**Tenant isolation.** The cloud rejects (`tenant_rejected`) any pushed row whose incoming or stored
`hotel_id`/`group_id` is not the node's, any `hotels` row other than the node's hotel, child rows whose
parent belongs elsewhere, and unknown/excluded tables. The pull feed is filtered by the node's hotel and
group; the edge also drops rows for another hotel.

## Ids and legal numbering

- **Primary keys:** edge-created rows use ids from `EDGE_NODE_ID × 10^12` upward (set by `edge:init`);
  cloud ids stay below. Rows keep the same id on both sides, so replay is an upsert by id.
- **Document numbers** (`NumberSequence`: invoices, receipts, POS checks, POs...): the edge numbers
  each series from `EDGE_NUMBER_RANGE_START + 1` (default `500001`) per hotel and year, enforced by
  a `BEFORE INSERT` trigger on `number_sequences` so new series and new years are covered. The cloud
  numbers the same series from 1. Each series stays unique and gapless within its range. The cloud
  must stay below 500 000 documents per series per hotel per year (raise the start if not).
- **Availability:** per SPEC, during an outage the edge should sell only an allotment reserved for it
  (room blocks). Room inventory is master data; the allotment itself is set up in the cloud.

## Status screen

`/edge` (FR/EN, `edge.manage`; general manager and group owner have it through `*`):
- on the edge: cursors, pending changes, last sync, last error, number range, *Sync now*;
- on the cloud: nodes of the hotel with last push/pull and lag, register and revoke;
- both: the last 50 conflicts.

API: `GET /api/v1/edge/status`, `POST /api/v1/edge/nodes`, `POST /api/v1/edge/nodes/{id}/revoke`,
`POST /api/v1/edge/sync` (edge only).

## Known limits / next steps

- Bulk query-builder writes are not captured; services that rely on them for edge-relevant data
  should switch to model saves, or a reconcile job should diff by `updated_at`.
- Initial bootstrap is a database snapshot; there is no API snapshot endpoint yet.
- One edge node per hotel (unique `hotel_id`).
- Users and roles created in the cloud reach the edge as `users` rows; role pivots need a new snapshot.
