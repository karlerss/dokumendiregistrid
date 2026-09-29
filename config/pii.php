<?php

/*
 * PII detection, assessment and redaction pipeline. See pii_plan.md.
 *
 * Nothing here changes what visitors see. Extraction and assessment only write
 * to the pii_* tables; redaction runs only when an admin triggers it.
 */
return [

    // Model used for extraction. Any model that supports strict JSON schema output.
    'model' => env('PII_MODEL', env('OPENAI_MODEL', 'gpt-5.4')),

    // Above this many characters of assembled input, the document is split per
    // file into chunks that each repeat the metadata block.
    'max_input_chars' => (int)env('PII_MAX_INPUT_CHARS', 250000),

    // A single file larger than this is truncated (its tail is dropped) and
    // the extraction is marked too_large.
    'max_file_chars' => (int)env('PII_MAX_FILE_CHARS', 400000),

    // Tokens (input + output) the automatic selection may spend per UTC day.
    // Admin-requested extractions are not counted against it. ~100 documents.
    'daily_token_cap' => (int)env('PII_DAILY_TOKEN_CAP', 2000000),

    // Automatic extractions dispatched per pii:enqueue run (once a minute).
    'enqueue_batch' => (int)env('PII_ENQUEUE_BATCH', 5),

    // Pending extraction rows older than this are re-dispatched (lost job).
    'requeue_after_minutes' => (int)env('PII_REQUEUE_AFTER_MINUTES', 30),

    // Python interpreter and PDF redaction script.
    'python' => env('PII_PYTHON', 'python3'),
    'soffice' => env('PII_SOFFICE', 'soffice'),

    // Disk names.
    'public_disk' => 'r2',
    'private_disk' => 'r2_private',
];
