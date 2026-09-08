# Plan: continuous re-check of remote document status

Goal: one long-running process that periodically re-checks every document that was
public at ingest against its source registry and records (a) access-restriction
changes, with personal-data restrictions (AvTS § 35 lg 1 p 12) distinguished, and
(b) documents that have disappeared (4xx). The daemon only observes and records;
hiding or deleting anything is a manual admin action from the review queue.
Prod: 2 cores, SQLite. The 2025-07 snapshot had 642k rows: 151k `Avalik`, 491k `AK`.
Only the `Avalik` ones are in scope.

Decisions taken (2026-09-07):

- Nothing is hidden or purged automatically. Changes are recorded and queued for review.
- Documents restricted at ingest (`restriction != 'Avalik'`) are not checked.
- Transient errors (5xx, timeouts, connection failures, bot-check pages) change
  nothing: no status change, no event, only a retry with backoff and a health counter.

## Implementation notes (2026-09-07)

Implemented as planned, with these deviations:

- Hot check state lives in a 1:1 table `document_remote_states` instead of
  columns on `documents`: every `UPDATE` on `documents` fires the FTS trigger that
  deletes and re-inserts the row's full text, which the daemon would have done
  once per second. `documents` only gained `visible` (admin-controlled).
- The daemon writes nothing a visitor can see. Hide / delete files / re-fetch /
  ignore are buttons on `/haldus/kontroll`, each acknowledging the queue row.
- The daily digest is sent by the daemon itself (no scheduler needed).
  `app:recheck-health` (heartbeat-stale and long-paused-host alerts) is a
  separate command meant for hourly cron, since a dead daemon cannot report itself.
- `audit:full` and `docs:audit` were removed; the old `last_*` columns are kept
  for now and can be dropped in a later migration.
- Unit file: `deploy/docregistries-recheck.service`.

## 0. What exists today (and what is wrong with it)

- `documents.last_audit_check_at / last_visibility / last_reason / last_reason_change`
  written by `audit:full` (one-off, ADR-only, last run Jan 2025 over 100k public docs).
- `MainController::show` and the API return 451 when `last_visibility` is `AK` or
  `Unknown`. Search, archive, sitemaps and the API list filter on the ingest-time
  `restriction` column.
- Problems to fix:
  - `audit:full` uses `AdrFetcher` for every org; breaks for RMK, Tallinn,
    Riigikantselei, Riigikogu.
  - Any exception (404, 5xx, timeout) becomes `Unknown` and hides the document.
  - `Http::retry(3, …)` retries 4xx too, so a 404 costs 3 requests and a sleep.
  - No per-host rate limiting; adr.rik.ee hosts ~40 of the 48 orgs.
  - SQLite is in `delete` journal mode; a constantly writing daemon will produce
    `database is locked` errors in the web app.
  - Four ADR hosts are now behind a Cloudflare Turnstile bot check (see §9).

## 1. Data model

Migration 1: normalised check state on `documents` (hot columns, indexed):

| column | type | meaning |
|---|---|---|
| `remote_status` | string, nullable | `public`, `restricted`, `gone`; null = never checked |
| `remote_restriction` | string, nullable | raw value from registry (`AK`, `Avalik`, `Asutusesiseseks kasutamiseks`, `PUBLIC`…) |
| `remote_restriction_basis` | text, nullable | raw basis string(s), joined |
| `remote_restriction_change_basis` | string, nullable | ADR "Juurdepääsupiirangu muutmise alus" |
| `personal_data_restriction` | bool, default false | classifier result on the basis (see §3) |
| `visible` | bool, default true, indexed | set only by admin actions; replaces the `last_visibility` gate |
| `checked_at` | timestamp, nullable | last *successful* check |
| `next_check_at` | timestamp, nullable, indexed | scheduler key |
| `check_error_count` | smallint, default 0 | consecutive transient errors |
| `last_http_status` | smallint, nullable | |

Backfill: `AK` -> `restricted`, `Avalik` -> `public`, `Unknown` -> null (re-checked
first); `checked_at` from `last_audit_check_at`; `visible = false` only for the rows
currently hidden by the `AK` gate (309 in the snapshot), so nothing that is visible
today disappears and nothing hidden today reappears without review. The `Unknown`
rows (376) were hidden by the behaviour we are removing; they become visible and go
to the front of the queue. Drop the old four columns in a follow-up migration.

Migration 2: `document_status_changes` (append-only, written only on transitions):

`id, document_id, occurred_at, from_status, to_status, from_restriction,
to_restriction, basis, personal_data (bool), http_status, acknowledged_at,
action (null | hidden | files_deleted | refetched | ignored), note`

This is the admin review queue.

Migration 3: SQLite settings. Set `journal_mode=WAL`, `busy_timeout=5000`,
`synchronous=NORMAL` on the sqlite connection in `config/database.php` (Laravel 11
supports these keys). WAL is a one-time file-level switch and must happen before the
daemon runs in prod.

## 2. Fetcher-level probe

Add to `BaseFetcher`:

```php
abstract public function checkRemote(Document $document): RemoteCheck;
```

`RemoteCheck` is a small value object: `outcome` (public|restricted|gone|error),
`httpStatus`, `restriction`, `bases[]`, `changeBasis`, `errorKind`
(http5xx|timeout|connection|bot_check|unparseable).

Rules common to all fetchers, in a `checkHttp()` helper:

- Timeout 15 s, connect timeout 5 s, redirects **not** followed.
- Retry only on connection errors and 5xx (`retry(2, 2000, fn($e) => !is4xx)`),
  never on 4xx.
- 4xx -> `gone` with the code.
- 3xx off the document path (the Turnstile redirect on politsei/smit/rescue/sisemin)
  -> `error` / `bot_check`.
- 5xx, timeout, DNS, TLS -> `error`.
- 200 whose body does not parse as a document page (Turnstile HTML, maintenance
  page, login page) -> `error` / `unparseable`. Never `gone`.

Per fetcher (each reuses the parser already used by `store()`):

| fetcher | gone when | restriction / basis source |
|---|---|---|
| ADR (`delta-adr`) | HTTP 404 ("Lehekülge ei leitud") | `table.form`: Juurdepääsupiirang, Juurdepääsupiirangu alus, Juurdepääsupiirangu muutmise alus (via `getPageData` + `getDocPropsFromData`) |
| RMK | 200 with `data === false` | `doc_access` / `failid[].file_access` as in `store()`; basis `doc_restrict_desc` |
| Tallinn | 200 with empty `#document_container` (`parseHtml` returns null) | `Juurdepääsupiirang`, `Juurdepääsupiirangu alus` |
| Riigikantselei | HTTP 404 (Notes "Entry not found") or `parseDocumentXml` null | `docaccesstype`, `accessrestrictionreason` (keep `withoutVerifying`) |
| Riigikogu | 200 with empty meta table (site returns 200 for unknown UUIDs) | `_restriction`, `_restriction_bases` from `parseDocumentPage` |

Normalisation: `Avalik`/`PUBLIC` -> `public`; anything else non-empty -> `restricted`.

## 3. Personal-data classifier

`App\Lib\Restriction\BasisClassifier::isPersonalData(string $basis): bool`, pure
function. Real basis strings from the prod snapshot it must handle:

- `AvTS § 35 lg 1 p 12` (216k rows), `§ 35 lg 1 p 12 teave`, `p 12`, bare `12`
  (60k rows, produced by the comma split in `getDocPropsFromData`).
- `AvTS § 35 lg 1 p 12,19`, `AvTS § 35 lg 1 p 12, AvTS § 35 lg 1 p 13`,
  `AvTS § 35 lg 1 p 1, AvTS § 35 lg 1 p 12`.
- Ranges: `AvTS § 35 lg 1 p 11-15` (includes 12).
- Descriptive text without a number: `mis sisaldab isikuandmeid`,
  `mis sisaldab eriliiki isikuandmeid`, `eraelu puutumatust kahjustav teave`,
  `Eraelu puutumatust kahjustav teave; piirangu kehtivus: …`.
- Must be false for: `p 1`, `p 2`, `p 17`, `p 1, 5 (1)`, `lg 2 p 2`,
  `HkMS § 89 lg 1`, `LS § 184 lg 3 p 1-4` (different act).

Approach: split on `,`/`;`; per fragment detect the act (`AvTS` or none -> AvTS,
any other act -> ignore), detect `lg 1` (or unspecified), extract `p N` / `p N-M` /
bare integer, check 12 in the set; plus keywords `isikuandme`, `eraelu`. Unit-test
with ~40 strings pulled from `restriction_bases` in the backup. The classifier is
also applied to the existing `restriction_bases` rows once, so a public document that
later returns a p 12 basis is compared against a consistent flag.

## 4. The daemon

`php artisan app:recheck-daemon` plus `--once`, `--limit=N`, `--document=ID`,
`--org=ID`, `--dry-run` for manual runs. Single process, sequential requests; the
work is I/O-bound so CPU is irrelevant on 2 cores.

Loop:

1. Fetch a batch (200 ids) of `restriction = 'Avalik'` documents ordered by
   `next_check_at IS NOT NULL, next_check_at` (nulls first), joined with org host.
   Group by host and round-robin across hosts so time spent waiting on one host's
   rate limit is used on another.
2. Per-host token bucket, default 1 request / 750 ms, in `config/recheck.php`.
   Global cap 3 req/s.
3. Per-host circuit breaker: 5 consecutive `error` results, or a 429/503 with
   `Retry-After`, pauses that host for 15 min (then 30, 60). Hosts whose errors are
   all `bot_check` are paused for 24 h and shown as "blocked" on the health card.
4. Apply the result in one short transaction per document:
   - `error`: nothing on the document changes except `check_error_count++`,
     `last_http_status`, and `next_check_at = now + 1h, 6h, 24h, 72h` (capped);
   - success: write `remote_*`, `personal_data_restriction`, `checked_at`,
     `check_error_count = 0`, `next_check_at` per the policy below;
   - if `remote_status` or `remote_restriction` changed, or the personal-data flag
     flipped, insert a `document_status_changes` row.
5. Heartbeat per batch: `cache()->put('recheck.heartbeat', now())` plus counters
   (checks, changes, errors per hour; queue depth; paused hosts).
6. `pcntl_signal(SIGTERM/SIGINT)`: finish the current document, exit 0.
7. `gc_collect_cycles()` per batch, `DB::disconnect()` every N batches; systemd
   `RuntimeMaxSec=86400` as a backstop restart.

Scheduling policy (`next_check_at` after a successful check), public-at-ingest only:

| bucket | interval |
|---|---|
| never checked / `Unknown` backfill | now |
| has an open or accepted takedown request | 7 d |
| registered < 180 d ago | 14 d |
| older | 45 d |
| remotely restricted or gone (already in the queue) | 90 d, to notice reversals |

Throughput: 150k public docs at 1 req/s is ~42 h per full pass; the mixed 14/45-day
policy averages ~0.1 req/s, so the per-host limit can stay polite.

## 5. What a change produces

Every transition produces a queue row and nothing else on the public site.

| transition | queue row | admin actions offered |
|---|---|---|
| public -> restricted, personal-data basis | `personal_data = true`, sorted first | hide, delete files (keeps the metadata row as a tombstone so `store()`'s `where url` dedupe prevents re-ingestion), ignore |
| public -> restricted, other basis | normal | hide, delete files, ignore |
| public -> gone (4xx) | normal | hide, delete files, ignore |
| restricted -> public | normal | re-fetch files (`Document::reindex()`), un-hide, ignore |
| restricted -> restricted, basis changed | low | acknowledge |
| transient error | none | (health card only) |

`visible` replaces the `last_visibility` check in `MainController::show` and
`DocumentApiController::show`. Whether `visible = false` should also remove the
document from search, archive, sitemaps and the API list (today it does not) is a
separate small change; recommended yes, since a hidden document listed by title is
inconsistent. `restriction` stays the ingest-time value for display.

## 6. Admin UI and notifications

- `/haldus/kontroll` (gated by `is_admin` like takedowns): health card (heartbeat
  age, checks/errors per hour, queue depth per bucket, paused/blocked hosts) and the
  unacknowledged `document_status_changes` list, personal-data rows first, filters by
  type/org, row and bulk actions from §5.
- Daily digest mail to `ADMIN_EMAIL` (reuse the `TakedownVerifiedAdminMail` pattern)
  when there are new rows, with a personal-data count in the subject. One-off mail if
  the heartbeat is older than 30 min or a host has been blocked for more than a day.
- Document page, admins only: `remote_status`, `checked_at`, basis, last event.

## 7. Deployment

- systemd unit `docregistries-recheck.service`: `ExecStart=php artisan
  app:recheck-daemon`, `Restart=always`, `RestartSec=30`, `Nice=10`,
  `MemoryMax=512M`, `RuntimeMaxSec=86400`, `KillSignal=SIGTERM`,
  `TimeoutStopSec=60`. Independent of however `app:fetch` is run today.
- Switch prod DB to WAL before first start.
- First pass drains ~150k never-checked public rows, about 2 days at 1 req/s.

## 8. Tests

- `BasisClassifierTest`: ~40 real strings, positive and negative.
- `RemoteCheckTest` per fetcher with `Http::fake`: public page, restricted page,
  404, 500, timeout, Turnstile 302 and Turnstile 200 body -> expected `RemoteCheck`.
  Fixtures to record: ADR AK page (`adr.rik.ee/som/dokument/18976496`, basis
  `AvTS § 35 lg 1 p 12`), ADR 404 body, RMK `{"data":false}`, Tallinn empty
  container, Riigikantselei Notes 404, Riigikogu unknown-UUID page, the
  adr.politsei.ee Turnstile page.
- `RecheckSchedulerTest`: `next_check_at` policy, error backoff, host circuit
  breaker, transition -> queue row, no document change on error.
- `RecheckDaemonTest`: `--once --limit` over faked HTTP; SIGTERM exits cleanly.
- Local dry run: `app:fetch` a few hundred docs for 3–4 orgs into `db2.sqlite`, then
  `app:recheck-daemon --once --dry-run` against the live registries.

## 9. Cloudflare and the Turnstile-protected hosts

Observed on 2026-09-07 with the project's User-Agent:

- `adr.rik.ee` (40 orgs) and `www.riigikogu.ee` are served through Cloudflare
  (`server: cloudflare`). Pages load normally; every ADR page carries Cloudflare's
  passive JS-detection beacon (`/cdn-cgi/challenge-platform/…`), which is harmless to
  a plain HTTP client. The risk is only that Cloudflare could start challenging the
  daemon's traffic; the rate limit and the "unparseable 200 is an error, not gone"
  rule are the defence.
- `adr.politsei.ee`, `adr.smit.ee`, `adr.rescue.ee`, `adr.siseministeerium.ee`
  (PPA, SMIT, Päästeamet, Siseministeerium; ~50k public documents in the snapshot)
  now redirect **every** document page to `/?returnUrl=…`, which is a Cloudflare
  Turnstile page ("Palun oodake, toimub robotkontroll…", ADR v1.4.20.1). The search
  endpoint returns 200 but its body is the same Turnstile page. A browser User-Agent
  or a cookie from the landing page does not change this. These registries were
  still fetchable on 2025-07-23 per the backup, so `app:fetch` for them is presumably
  broken as well.

Consequence: the daemon cannot verify documents on those four hosts, and we will not
work around a bot check. They are classified `bot_check`, paused, and shown as
blocked on the health card; their documents keep their current state. Options
outside this plan: ask the registry operators for an allow-listed access path, or
check whether they publish the data elsewhere.

## 10. Order of work

1. Migrations (§1) + WAL config; backfill; classifier + tests (§3).
2. `RemoteCheck` + `checkRemote()` on all five fetchers + fixture tests (§2, §8).
3. Scheduler/policy + daemon command with `--once`/`--dry-run` (§4); local dry run.
4. Queue rows, `visible` flag and the 451 gate (§5).
5. Admin page + digest mails (§6).
6. systemd unit, prod WAL switch, remove `audit:full` / `docs:audit` and the old columns (§7).
