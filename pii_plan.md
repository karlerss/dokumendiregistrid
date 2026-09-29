# Plan: PII detection, legitimate-interest assessment and manual redaction

Goal: for documents where we have a signal that they contain personal data (today:
documents whose source registry has since restricted them under AvTS § 35 lg 1
p 12; later: takedown requests and hand-picked documents), extract *who* appears
in the document and *in what capacity*, run a rules-based legitimate-interest
assessment over that, surface the result as warnings in the admin UI, and let an
admin trigger redaction of the text, the index and the original files.

Prod: 3 cores, 3.8 GB RAM, 23 GB free disk, SQLite, Python 3.10, Java 19,
LibreOffice installed, no PyMuPDF, no Tesseract. Flagged set today: 532
documents, 424 visible, 108 hidden; 509 with files, ~9 MB of extracted text
(~3M tokens).

Decisions taken (2026-09-27):

- **Nothing is hidden, changed or redacted automatically.** Extraction and
  assessment run continuously and write only to their own tables. Every visible
  effect is a button in `/haldus`.
- **Extraction and assessment are continuous.** A worker picks up any flagged
  document that has no extraction for the current prompt version, and any
  extraction that has no assessment for the current rules version. Nothing is
  triggered from the CLI in normal operation.
- **No admin work via CLI.** Artisan commands exist only for the worker
  (systemd) and for rare one-offs (migrations, backfill).
- **Personal code is treated exactly like the name.** Wherever the name is kept,
  the code is kept; wherever the name becomes initials, the code is removed.
- Initials style is `V. S.`, mirroring the registries' own convention.
- `ai_summary` / `ai_title` are cleared on redaction, not regenerated.
- Unredacted text is kept in the redaction record indefinitely (admin-only).
- Cross-document lookups are out of scope.
- The 310 documents hidden by the September migration stay hidden and are not
  processed. Selection is *flagged and visible*. An admin can still start
  extraction for any single document from its page.
- Detection is one structured LLM call per document (JSON schema, strict), not
  NER or regex. Every string the model returns is verified verbatim against the
  document before it is stored; unverified strings are dropped and logged.
- Redaction produces new copies; originals move to a private bucket and the
  redaction is revertible.

## Implementation notes (2026-09-27)

Implemented as planned with §8's decisions, plus these deviations and facts:

- **Everything is queue jobs** (`app/Jobs/ExtractDocumentPii`, `AssessDocumentPii`,
  `RedactDocument`, `RevertRedaction`) on the `database` queue, run by
  `deploy/docregistries-queue.service`. `pii:enqueue` is scheduled every minute
  in `routes/console.php`; prod needs the `schedule:run` crontab line (see the
  unit file). `deploy.sh` restarts the queue service when it is installed.
- **Redaction runs entirely in one job**: text step (transaction, verified,
  rolled back on leftovers) followed by the file step. Public links to files
  with a non-KEEP action are switched off by the text step, so nothing stays
  fetchable while PDFs are being processed.
- **Name matching tolerates Estonian case endings**: multi-word or ≥6-char name
  forms also swallow a trailing run of lowercase letters ("Mari Maasikase"),
  short single-word forms stay strictly bounded ("Tamm" never eats
  "Tammsaare"). Verification after the text step is substring-based, i.e.
  stricter than replacement, so an unlisted inflection of a short form fails
  loudly instead of leaking.
- **HTML**: replaced in place; if a removed string still shows in the
  tag-stripped HTML (PDFBox splits words across tags), the HTML is regenerated
  from the redacted plain text.
- **Overrides re-assess synchronously** in the request (pure PHP), not via the
  worker. The worker still re-assesses everything on a rules-version bump.
- **`app:recheck-health`** also alerts when `pii:enqueue` has not run for 15
  minutes or extractions have been queued for more than 2 hours.
- The OpenAI client (0.8.4) turns non-JSON error bodies into a `TypeError`;
  the extraction row stores it as the error and retries up to 3 times.
- `File::url` returns the redacted copy when one exists and `null` when the
  original is withheld; the document page shows a notice instead of the
  iframe/link. `File::delete()` cleans up private and redacted copies too.
- **No database snapshot before redaction.** The first version made a
  `VACUUM INTO` copy once a day; with a 27 GB SQLite file that filled the
  disk on 2026-09-28/29 and was removed. The `before` JSON on the redaction
  row is the undo record.
- A failed or partial file step can be re-run from the detail page
  (**Proovi faile uuesti**); files already moved are skipped.
- Not done: Tesseract/OCR (scanned PDFs are `needs_ocr` and withheld on
  redaction); cross-document lookups (dropped by decision).

**Prod prerequisites before first use**: R2 API token with access to
`adr-docs-private` (the current token is scoped to `adr-docs` only), valid
`OPENAI_SECRET`, `OPENAI_MODEL`, `pip3 install pymupdf`, the queue unit, the
crontab line.

## 0. What exists today

- `document_remote_states.personal_data_restriction` (from the re-check daemon)
  is the selection signal. No document has ever been hidden by hand
  (`document_status_changes` has zero `hidden` actions); the 310 hidden
  documents were seeded by the `2026_09_07` migration from the January 2025
  `audit:full` column.
- `App\Lib\LLM\AiProvider::getJson(system, user, schema)` with `OpenAI`
  (model hard-coded to `gpt-4o`, strict JSON schema) and `Ollama` implementations.
  `OPENAI_SECRET` is set in prod.
- Files: `files.contents` (plain text), `files.html`, `files.name`,
  `files.location` on R2. **The R2 bucket is fully public** via
  `https://adr-docs.karlerss.com/<key>`; `File::getUrlAttribute` builds that URL.
  PDFs are iframed from it, DOCX/XLSX go through Microsoft's Office viewer.
- `signatures.name / pno` per ASiC-E file; rendered on the document page.
- Full text index: `documents.file_contents` + `fts_documents` (FTS5, triggers on
  `documents` UPDATE). `Document::ftsIndexSingle()` rebuilds one document.
  Meilisearch is configured in `.env` but not used by the app code.
- Admin: `/haldus/kontroll` (re-check queue: hide / delete files / re-fetch /
  ignore), `/haldus/eemaldamistaotlused` (takedowns), admin-only file replace
  and delete on the document page.
- `QUEUE_CONNECTION=database` but no worker service exists; the re-check daemon
  is a systemd service (`deploy/docregistries-recheck.service`).
- Backups: `.sqlite` copies in the repo dir; no automated snapshot before batch jobs.

## 1. Prerequisites and infrastructure

| item | why | action |
|---|---|---|
| LLM model + config | `gpt-4o` is hard-coded; extraction needs a current model, large context, strict schema | `OPENAI_MODEL` env, default to a current model; `PII_MAX_INPUT_CHARS` for chunking |
| PII worker service | continuous extraction + assessment + queued redaction steps, independent of the re-check daemon so LLM latency never slows checks | `deploy/docregistries-pii.service`, runs `pii:worker` in a loop (sleep 60 s when idle); heartbeat like the re-check daemon |
| PyMuPDF | true PDF redaction (removes glyphs and image regions, not an overlay) | `pip3 install pymupdf` on prod; `app/Lib/Pii/bin/redact_pdf.py` |
| LibreOffice | DOCX/RTF/XLSX → PDF so they can be redacted with the same script | already installed (`/usr/bin/soffice`) |
| Tesseract (optional, later) | scanned PDFs with no text layer cannot be redacted by string search | not installed; such PDFs get `NEEDS_OCR` and their original is withheld instead |
| Private storage | withheld originals must stop being publicly fetchable; the whole bucket is public | second R2 bucket `adr-docs-private` + `r2_private` disk (no public domain). Redacted copies go to the public bucket under a new random key |
| Cost guard | one call per document; must not run away when the selection grows | per-document token accounting; `PII_DAILY_TOKEN_CAP` env; worker pauses extraction when the cap is hit and the admin page shows it |
| Prompt/rule versioning | results must be reproducible; version bumps must re-run automatically | `prompt_version`, `schema_version`, `rules_version` columns; the worker re-extracts / re-assesses whatever is behind the current version |
| Worker health | a dead worker must be noticed | heartbeat row; `app:recheck-health` (already hourly) gains a check for the PII worker |

## 2. Data model

All new tables are separate from `documents` so writes do not fire the FTS
trigger (same reason `document_remote_states` exists).

**`pii_extractions`** — one row per document per (prompt_version, model).

| column | meaning |
|---|---|
| `document_id`, `model`, `prompt_version`, `schema_version` | identity of the run |
| `status` | `pending` / `done` / `failed` / `needs_ocr` / `too_large` |
| `requested_by` | `worker` / admin user id (button on document page) |
| `input_chars`, `input_tokens`, `output_tokens`, `cost_usd` | accounting |
| `matter` | enum: PUBLIC_CONSULTATION, PERMIT_OR_LICENCE, CONTRACT, HR, SUPERVISION_OR_ENFORCEMENT, DISPUTE_OR_CHALLENGE, INFORMATION_REQUEST, PROPERTY_NOTIFICATION, SYSTEM_ACCESS, COURT_CORRESPONDENCE, OTHER |
| `summary` | one sentence, no names |
| `flags` | json list, document-level, see below |
| `public_interest`, `public_interest_reason` | HIGH / MEDIUM / LOW + free text |
| `legal_entities` | json: `[{name, registry_code, kind}]` (explicitly *not* PII) |
| `raw_response` | json, for debugging and re-assessment without a new call |
| `error`, `attempts` | last failure, retry count (max 3) |

**Document-level `flags`** (replaces the single `narrative_private_life` bool).
Each flag is either a *re-identification* flag (the person stays recognisable
after names and identifiers are removed) or a *sensitivity* flag (what is said
about them). The assessment treats them differently: sensitivity alone is fine
once the text is anonymised; sensitivity plus re-identification is not.

| flag | kind | meaning |
|---|---|---|
| `IDENTIFIABLE_BY_CONTEXT` | re-id | unique role, small community, specific property or event makes the person recognisable without a name |
| `HOME_LOCATION_DETAIL` | re-id | residence, plot, village named precisely |
| `PROPERTY_OWNERSHIP` | re-id | cadastral units or addresses tied to a private owner |
| `RESTRICTION_STAMP_PRESENT` | source | the source's own "ASUTUSESISESEKS KASUTAMISEKS" stamp is in the text (holder and basis stored in `raw_response`) |
| `FAMILY_CIRCUMSTANCES` | sensitivity | children, relatives, household situation |
| `HEALTH` | sensitivity (Art 9) | |
| `POLITICAL_OR_RELIGIOUS_OPINION` | sensitivity (Art 9) | e.g. a citizen's position in a public consultation |
| `ETHNICITY_OR_LANGUAGE` | sensitivity (Art 9) | |
| `CRIMINAL_MATTER` | sensitivity (Art 10) | |
| `SOCIAL_BENEFITS` | sensitivity | |
| `FINANCIAL_CIRCUMSTANCES` | sensitivity | debts, income, fees to an individual |
| `EMPLOYMENT_RECORD` | sensitivity | service records, appointments, disciplinary |
| `CONFLICT_OR_DISPUTE` | sensitivity | complaint against a named person, neighbour dispute |
| `MINOR_INVOLVED` | sensitivity | |
| `VULNERABLE_PERSON` | sensitivity | guardianship, disability, care |
| `SCANNED_OR_IMAGE_CONTENT` | technical | text may be incomplete; images may contain names, signatures, photos |
| `MULTIPLE_PRIVATE_PERSONS` | technical | three or more private-person subjects |

**`pii_subjects`** — one row per natural person per extraction.

| column | meaning |
|---|---|
| `extraction_id`, `document_id` | |
| `name`, `personal_code` | as returned; code checksum-validated |
| `context` | enum, see §4 |
| `role` | AUTHOR / ADDRESSEE / SIGNATORY / SUBJECT_OF_DECISION / REPRESENTATIVE / MENTIONED |
| `organisation` | the body or company the person acts for, if any |
| `surface_forms` | json: verified exact strings (nominative, inflected, initials, email local parts, file-name fragments), each with where it was found (`body`, `html`, `title`, `to`, `responsible`, `filename`, `signature`) |
| `unverified_forms` | json: what the model returned that was not found (kept for prompt tuning) |
| `identifiers` | json: `[{type, value, nature: WORK/PRIVATE/UNKNOWN, verified}]`, types EMAIL, PHONE, POSTAL_ADDRESS, PROPERTY_CADASTRAL, IBAN, BIRTH_DATE, VEHICLE, CASE_NUMBER, OTHER |
| `subject_flags` | json: per-person subset of the flags above that apply to this person specifically (HEALTH, MINOR_INVOLVED, …) |
| `evidence`, `confidence` | short quote; HIGH / MEDIUM / LOW |
| `context_override`, `override_note`, `overridden_by`, `overridden_at` | admin correction; assessment uses override when set |
| `linked_metadata_initials` | e.g. `V.S` from `documents.to` |

**`pii_assessments`** — one row per document per rules version (history kept).

| column | meaning |
|---|---|
| `document_id`, `extraction_id`, `rules_version` | |
| `band` | `INFO` / `WARN` / `HIGH` |
| `recommendation` | `NONE` / `REDACT` / `REVIEW_WITHHOLD` — advisory only |
| `subject_actions` | json per subject: `{name: KEEP/INITIALS, personal_code: KEEP/REMOVE, private_contacts: REMOVE, work_contacts: KEEP/REMOVE, property_ids: KEEP/REMOVE}` |
| `fired_rules` | json list of rule ids with inputs, the Art 6(1)(f) record |
| `reviewed_at`, `reviewed_by`, `review_action` | set by the admin: `acknowledged` / `redacted` / `hidden` / `ignored` |
| `computed_at` | |

**`pii_redactions`** — one row per applied redaction.

| column | meaning |
|---|---|
| `document_id`, `assessment_id`, `applied_by`, `applied_at` | |
| `plan` | json: every replacement `{surface, replacement, targets[]}` and per-file action (`REDACT_PDF` / `CONVERT_AND_REDACT` / `WITHHOLD_ORIGINAL` / `KEEP`) |
| `before` | json: prior values of every changed field (`documents.title/to/responsible/ai_title/ai_summary`, per-file `name/contents/html/location`, per-signature `name/pno`) — admin-only, kept indefinitely, enables revert |
| `text_status` | `applied` / `failed` (synchronous step) |
| `files_status` | `pending` / `applied` / `partially_applied` / `failed` (worker step) |
| `log` | json: per-step outcome, PyMuPDF hit counts per form per file |
| `reverted_at`, `reverted_by` | |

**Column additions**

- `files`: `redacted_location`, `original_private_location`, `redacted_at`,
  `original_withheld` (bool).
- `documents`: `redacted_at` (nullable). Public pages show a short notice when set.

## 3. Worker (`pii:worker`, systemd)

One loop, in priority order, each pass bounded:

1. **Redaction file steps** (`pii_redactions.files_status = pending`): PDF
   redaction, LibreOffice conversion, uploads, withholds. Highest priority
   because an admin is waiting for it.
2. **Assessment** for every `done` extraction lacking an assessment at the
   current `rules_version`, and for every subject override saved since the last
   assessment. Pure PHP, cheap; also how a rules bump re-assesses everything.
3. **Extraction** for every document that is flagged, visible, and has no
   extraction at the current `prompt_version` (plus admin-requested rows from
   the document page). Up to N per pass, subject to the daily token cap.

Idle: sleep 60 s. Heartbeat row updated per pass with counts. Failures are
stored on the row and retried up to 3 times; the admin page lists them.

Selection query for step 3, so it is explicit:
`document_remote_states.personal_data_restriction = 1 and documents.visible = 1
and no pii_extractions row with (prompt_version = current, status in (done, needs_ocr, too_large))`.

### Extraction details

Input assembly per document, in this order:

1. Metadata block: organisation, type, function, series, title, `to`,
   `responsible`, registration date, dossier.
2. File list: every `files.name` (names carry initials and full names).
3. Signatures: name + personal code + time per ASiC-E file.
4. Each file's `contents` (plain text; `html` is not sent). Files with < 50
   characters of text are listed by name only. Duplicate file bodies (same
   hash) are sent once and referenced.

Sizing: above `PII_MAX_INPUT_CHARS`, split per file into chunks that each repeat
the metadata block, call once per chunk, merge subjects by personal code, then
by normalised name. Documents whose PDFs all have empty text but non-trivial
size are marked `needs_ocr` and not sent.

Prompt (system): the context and role definitions from §4 with Estonian
public-sector conventions spelled out (officials' work contact is public;
registry codes and company IBANs belong to legal entities; regulated
professionals such as notaries and bailiffs act officially; students on practice
agreements may be minors); the flag definitions with the re-identification vs
sensitivity distinction; the instruction to copy surface forms *exactly*,
including inflected forms and forms inside file names; the instruction to put
companies and public bodies under `legal_entities`, never under `subjects`.
Stored in `resources/prompts/pii_extract_v1.md` and versioned.

Post-processing (all in PHP):

- Grounding: every surface form and identifier value is searched (case-sensitive,
  whitespace-normalised) across body texts, `html` stripped of tags, title, `to`,
  `responsible`, file names and signatures. Found ones are stored with their
  locations; missing ones go to `unverified_forms`.
- Personal codes: checksum validation; a regex sweep of all text for valid codes
  the model did not attribute produces a subject with `context = UNKNOWN`,
  `name = null`, so nothing with a code slips through unseen.
- Merge subjects across chunks and across repeated files.

## 4. Assessment rules (`App\Lib\Pii\Rules`)

Pure PHP class with `VERSION`, unit-tested against fixtures. Input: extraction +
subjects (overrides applied). Output: an assessment row. Never touches
`documents`.

**Contexts**

| context | meaning |
|---|---|
| `PUBLIC_OFFICIAL_WORK_MATTER` | official or employee of a public body acting in that function |
| `PUBLIC_OFFICIAL_HR_MATTER` | official, but the document is about them as an employee (service record, appointment, disciplinary, leave) |
| `REPRESENTING_LEGAL_ENTITY` | employee, board member or contact person acting for a company, NGO or municipality |
| `REGULATED_PROFESSIONAL` | notary, bailiff, sworn advocate, auditor, court expert acting officially |
| `NATURAL_PERSON_CONTRACT_PARTY` | individual contracting with a public body (committee member, expert, lessee) |
| `LICENCE_OR_CERTIFICATE_HOLDER` | individual granted or refused a professional right by decision |
| `PRIVATE_PERSON_APPLICANT` | private person who initiated: opinion, complaint, request, application |
| `PRIVATE_PERSON_SUBJECT` | private person the decision or notice is about without initiating it (property owner notified, supervision subject, counterparty in a challenge) |
| `PRIVATE_PERSON_THIRD_PARTY` | mentioned incidentally: neighbour, family member, witness |
| `STUDENT_OR_INTERN` | practice agreements, school correspondence; possible minor |
| `PUBLIC_FIGURE` | politician or otherwise public person acting publicly |
| `UNKNOWN` | model could not tell, or unattributed personal code |

**Per-subject actions**

| context | name and personal code | private contact / address / property | work contact |
|---|---|---|---|
| official, work matter | keep | remove | keep |
| representing legal entity, regulated professional | keep | remove | keep |
| natural person contract party, licence holder | keep | remove | keep |
| public figure | keep | remove | keep |
| student or intern | initials, code removed | remove | remove |
| private applicant / subject / third party | initials, code removed | remove | n/a |
| official, HR matter | initials, code removed | remove | remove |
| unknown | initials, code removed | remove | remove |

Property/cadastral identifiers tied to a private person are removed with the
name; tied to a legal entity they stay.

**Bands and recommendation**

- `INFO`: only keep-contexts present, no sensitivity flags → `NONE`.
- `WARN`: at least one initials-context subject → `REDACT`. Sensitivity flags
  alone do not raise this: once names, codes and identifiers are gone, the
  narrative is anonymous data.
- `HIGH` → `REVIEW_WITHHOLD` when an initials-context subject is present and
  any of:
  - a re-identification flag (`IDENTIFIABLE_BY_CONTEXT`, `HOME_LOCATION_DETAIL`,
    `PROPERTY_OWNERSHIP`) together with a sensitivity flag — redaction would
    leave a recognisable person with sensitive content;
  - `MINOR_INVOLVED` or `VULNERABLE_PERSON`;
  - `PUBLIC_OFFICIAL_HR_MATTER`;
  - `SCANNED_OR_IMAGE_CONTENT` — text redaction cannot be trusted to be complete.
- Modifiers, recorded as fired rules, moving `WARN`→`HIGH` only:
  `RESTRICTION_STAMP_PRESENT`; `MULTIPLE_PRIVATE_PERSONS`.

The admin decides on `HIGH`; the existing "Peida" button is the tool for
withholding. Nothing in this section changes visibility.

**Grouping** (admin overview only): counts per organisation × series × band,
per context, per matter, per flag.

## 5. Admin UI

New page **`/haldus/isikuandmed`**:

- Header: totals by band and review state, worker heartbeat, extraction backlog
  (pending / failed / needs_ocr / too_large), tokens and cost today and total,
  daily cap state, prompt and rules versions in use.
- Filters: band, reviewed / unreviewed, organisation, series, context, matter,
  flag, redacted / not, has overrides.
- Table row per document: title, org, series, band badge, subject summary
  ("2 eraisikut, 3 ametnikku"), flags, redacted-at, review state.
- Buttons per row: **Vaata** (detail), **Peida / Näita** (existing behaviour,
  reused), **Läbivaadatud** (acknowledge without action).
- Group view: org × series table with counts and a link to the filtered list.
- Failures list: extraction and file-step failures with the error and a
  **Proovi uuesti** button (sets the row back to `pending`).

Detail page **`/haldus/isikuandmed/{document}`**:

- Extraction facts: matter, summary, public interest + reason, flags.
- Subjects table: name, code, context (select to override, with note), role,
  organisation, identifiers with WORK/PRIVATE, subject flags, evidence quote,
  confidence, verified surface forms and where they were found, unverified forms.
  Saving an override marks the document for re-assessment; the worker does it
  within a minute and the page shows "hindamine ootel" until then.
- Assessment: band, recommendation, fired rules in plain language.
- **Redaction preview**: the full plan before anything is applied — every
  replacement with its target fields and files, per-file action (redact PDF /
  convert + redact / withhold original / keep), and a rendered before/after of
  the text. One **Rakenda redigeerimine** button with confirm.
- After apply: text step result immediately; file step progress per file
  (pending / done / hits per form / failed) as the worker gets to it; then a
  **Taasta** (revert) button.
- **Uuenda ekstraktsiooni**: queues a fresh extraction with the current prompt
  version (`requested_by` = admin).

Document page (admin block): band, subject summary, link to the detail page,
and **Kontrolli isikuandmeid** for documents not in the selection (queues an
extraction). This is the only way an unflagged or hidden document enters the
pipeline.

Warnings elsewhere:

- `/haldus/kontroll` rows get the band badge and a link to the detail page when
  an assessment exists.
- Takedown detail page shows the assessment for the requested document, or a
  **Kontrolli isikuandmeid** button if none exists, so a takedown can be
  answered with "redacted" instead of "removed".
- Re-check daily digest gets one line: new HIGH-band documents awaiting review,
  extraction failures, cap hit.

## 6. Redaction execution (`App\Lib\Pii\Redactor`)

Triggered only by the detail-page button. Two steps: text (synchronous, in the
request) and files (worker).

Plan building (from assessment + overrides):

- Per initials-subject: replacement `V. S.` derived from the name, applied to
  every surface form including inflected ones. Two subjects with the same
  initials get `V. S. (1)`, `V. S. (2)`. Personal code → `[isikukood eemaldatud]`.
- Private identifiers → `[eemaldatud]`. Work identifiers of keep-contexts untouched.
- Longest surface form first, so "Jaan Tamm" is replaced before "Tamm".

Text step, one transaction, `before` snapshot written first:

1. `files.contents`, `files.name` for every file of the document.
2. `files.html`: replace on the HTML string; re-run grounding on the stripped
   HTML — if any form survives (split across tags by PDFBox), regenerate `html`
   from the redacted `contents` as paragraphs instead.
3. `documents.title`, `to`, `responsible`; `ai_title` and `ai_summary` cleared.
4. `signatures.name`/`pno` for initials-subjects.
5. `Document::ftsIndexSingle()`; `touch()` so sitemaps carry a new lastmod.
6. Verification: grounding over all surfaces must return zero hits for every
   removed form; otherwise the transaction rolls back and the page shows the
   leftovers.
7. Public file links are switched off immediately for files with a planned
   action other than `KEEP` (`original_withheld = 1`), so nothing stays
   fetchable while the worker catches up.

File step (worker), per file according to the plan:

| type | action |
|---|---|
| PDF with text layer | `redact_pdf.py`: `search_for` each surface form on each page, `add_redact_annot`, `apply_redactions`; upload to public bucket under a new key → `redacted_location`; original copied to private bucket, deleted from public |
| PDF without text layer | withheld; `needs_ocr` noted |
| DOCX / RTF / ODT / XLSX | `soffice --headless --convert-to pdf`, then as PDF; the DOCX itself is withheld |
| EML / MSG | withheld (the page renders from `html`/`contents`) |
| ASiC-E / BDOC container | withheld (signed, cannot be altered); inner files handled individually |
| Images | withheld |
| files of keep-only documents | untouched |

Withhold = copy to `adr-docs-private` under the same key, delete from the
public bucket, set `original_private_location`. The R2 delete is the one
non-transactional step; it runs last and its outcome is logged.

Web changes: `File::getUrlAttribute` returns `redacted_location` when set and
`null` when withheld; the document page shows "Originaalfail on eemaldatud,
tekst on redigeeritud" in place of the link/iframe; the API exposes the same.

Revert (button): restore every `before` value, move originals back, delete
redacted copies, clear `redacted_*`, re-index.

Known limit: PyMuPDF redaction is string-based; a name written differently in
the PDF than in PDFBox's extraction (ligatures, hyphenation at line end) is a
miss. The log reports hit counts per form per file so the admin sees zeros.

## 7. Rollout

1. Migrations, models, worker skeleton with extraction in `--dry-run` mode
   (builds prompts, records sizes and `needs_ocr`, no calls). Deploy the service.
2. Enable calls with a low daily cap; let the worker process the first ~20
   flagged visible documents; review subjects, contexts and flags in the admin
   list; adjust the prompt; bump version — the worker re-extracts on its own.
3. Raise the cap; let it finish the 424. Review the context distribution and the
   unverified-forms rate.
4. Rules + tests; bump `rules_version`; the worker assesses everything. Review
   band distribution and the group view.
5. Detail page with overrides.
6. Redactor text step; apply to 5 documents from the detail page, verify on the
   page and in search, revert one.
7. Private bucket, PyMuPDF, LibreOffice conversion; worker file step; apply to
   the same 5; open the PDFs.
8. Work through the backlog by group in the admin UI.
9. Steady state: the worker keeps up with the re-check daemon; the digest
   reports what needs review.

Order of implementation: §2 → §3 (worker + extraction) → §4 → §5 (list, group,
detail without redaction) → §6 text step → §6 file step → §5 preview/apply/revert.

## 8. Decisions from review (2026-09-27)

1. Daily token cap defaults to 2M tokens (~200 documents/day; raised from 1M on 2026-09-29).
2. The cap stops only the automatic selection; admin-requested extractions
   always run.
3. `V. S. (1)` / `V. S. (2)` for shared initials.
4. `SCANNED_OR_IMAGE_CONTENT` never affects the band on its own; it is shown as
   a flag only.
5. Takedown verification never triggers extraction. It stays a button.
6. **Third parties never have a direct effect on visibility.** Not the source
   registry, not the model output, not a takedown requester. Only an admin
   action changes `documents.visible` or replaces public content.
7. The whole pipeline runs as Laravel queue jobs on the existing `database`
   queue (`ExtractDocumentPii`, `AssessDocumentPii`, `RedactDocument`,
   `RevertRedaction`), executed by one `queue:work` systemd service. A
   per-minute scheduler entry (`pii:enqueue`) dispatches extraction and
   assessment jobs for whatever is behind the current versions, subject to the
   cap. Redaction is dispatched by the admin button and runs text and file
   steps in one job, with per-step status on the detail page. This replaces
   the bespoke `pii:worker` loop from §3.
