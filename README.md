# dokumendiregistrid.karlerss.com

An application that aggregates documents from Estonian public-sector
document registries into a single searchable archive.

It periodically scrapes the registries, downloads attached files, extracts
text from a wide range of formats, and exposes the result through a web UI
and full-text search.

A live instance is hosted at <https://dokumendiregistrid.karlerss.com/>.

## What it indexes

Fetchers exist for the following registries:

- **ADR** (`adr.rik.ee`, `adr.politsei.ee`, `adr.smit.ee`, etc.) — ministries,
  prosecutor's office, courts, agencies. See `resources/registries.txt` for
  the full list seeded into the database.
- **RMK** (`adr.rmk.ee`) — Riigimetsa Majandamise Keskus.
- **Riigikantselei** (`dhs.riigikantselei.ee`).
- **Riigikogu** (`riigikogu.ee/tegevus/dokumendiregister`).
- **Tallinn** (`dhs.tallinn.ee/atp`).

For each document the application stores metadata (title, type, date,
counterparties, access restriction, etc.), downloads attached files, and
parses them into searchable plain text and HTML.

## Supported file formats

Parsing is dispatched by `FileParser` based on extension:

- PDF — text and HTML via Apache PDFBox (bundled jar at
  `app/Lib/Parser/bin/pdfbox-app-3.0.2.jar`).
- DOCX, RTF, TXT.
- EML and MSG (Outlook) email messages.
- ASiC-E / BDOC containers (`.asice`, `.bdoc`) — Estonian digitally signed
  containers; signatures are extracted into the `signatures` table and the
  contained files are parsed recursively.
- Generic directories.

## Requirements

- PHP 8.3 with `ext-dom`, `ext-libxml`, `ext-simplexml`, `ext-zip`.
- Composer.
- Node.js + npm (for the front-end assets).
- Java (required by the bundled PDFBox jar for PDF parsing).
- Python 3 with the [`extract_msg`](https://pypi.org/project/extract-msg/)
  module (required for parsing Outlook `.msg` files).
- SQLite (default database; FTS5 is used for full-text search).
- Optional: OpenAI API key or a local Ollama instance, for document
  summarisation.

## Installation

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed --class=OrgSeeder
npm run build
```

Then serve the application with `php artisan serve` (or any PHP-FPM /
web-server setup).

## Configuration

Relevant `.env` entries beyond the Laravel defaults:

- `DB_CONNECTION=sqlite` — the application is developed against SQLite and
  relies on its FTS5 virtual table.
- `OPENAI_SECRET` — to enable OpenAI-powered summarisation
  (`app/Lib/LLM/OpenAI.php`).
- `ADMIN_TOKEN` — token used by the login route to grant admin privileges
  in the session (required for destructive actions such as deleting or
  replacing files).

## Ingesting documents

The primary ingestion command is `app:fetch`:

```bash
# Fetch recent documents for an organisation (by id from the organisations table)
php artisan app:fetch <orgId>

# Walk a date range
php artisan app:fetch <orgId> --start=2024-01-01 --end=2024-01-31

# Fetch a single date, going backwards through the registry
php artisan app:fetch <orgId> --date=2024-06-01 --backwards --limit=100

# Skip downloading attached files (metadata only)
php artisan app:fetch <orgId> --no-files
```

Other useful commands:

- `php artisan app:fetch-single <orgId> <docId>` — re-fetch a specific
  document.
- `php artisan app:orgs [--with-names]` — list configured organisations.
- `php artisan fts:reindex-documents [--chunk=1000]` — rebuild the SQLite
  FTS index.
- `php artisan docs:audit` / `php artisan audit:full` — consistency checks
  on stored documents and files.
- `php artisan document:fast-rebuild` — rebuild derived document state.

## Web routes

The public UI is in Estonian; the most relevant routes (see
`routes/web.php`) are:

- `/` — search and recent documents.
- `/dokumendid/{document}` — document detail page.
- `/toimikud/{slug}` — dossier (grouped documents).
- `/arhiiv`, `/arhiiv/{org}`, `/arhiiv/{org}/{year}`,
  `/arhiiv/{org}/{year}/{month}` — archive browsing.
- `/projektist` — about page.
- `/sitemap.xml` and per-organisation sitemaps under `/sitemaps/...`.

Admin-only routes (gated by an `is_admin` session flag set after logging
in with `ADMIN_TOKEN`) cover document/file deletion, file replacement and
re-indexing.

## Tests

```bash
./vendor/bin/phpunit
```

The suite uses an in-memory SQLite database and does not touch the
configured registry endpoints — fetcher tests are driven by fixtures under
`tests/__fixtures/`.

## License

Licensed under the [GNU Affero General Public License v3.0](LICENSE)
(AGPL-3.0). Any modified version — including one offered as a network
service — must be made available under the same license.
