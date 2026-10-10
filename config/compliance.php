<?php

return [
    // Bearer token for the /mcp endpoint; the same secret as the /login form.
    'admin_token' => env('ADMIN_TOKEN'),

    // Hosts the public site is reachable under. URLs quoted in letters use
    // the canonical one even when APP_URL differs (local, staging).
    'site_hosts' => array_filter([
        parse_url((string)env('APP_URL'), PHP_URL_HOST),
        'dokumendiregistrid.karlerss.com',
    ]),

    // What a "remove" decision leaves on the document row besides the
    // identifiers the fetchers need to avoid re-ingesting it (registry id,
    // source URL, reference, date, organisation). When false, title, "to"
    // and "responsible" are blanked as well. Undecided;
    // keeping is the default because blanking cannot be undone.
    'tombstone_keep_text' => (bool)env('COMPLIANCE_TOMBSTONE_KEEP_TEXT', true),

    // Placeholder title when tombstone_keep_text is false (title is NOT NULL).
    'tombstone_title' => '[eemaldatud]',
];
