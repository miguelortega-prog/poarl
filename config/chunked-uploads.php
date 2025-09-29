<?php

return [
    'collection_notices' => [
        'chunk_size' => (int) env('COLLECTION_NOTICE_CHUNK_SIZE', 1024 * 1024 * 2),
        'max_file_size' => (int) env('COLLECTION_NOTICE_MAX_FILE_SIZE', 512 * 1024 * 1024),
        'cleanup_ttl_minutes' => (int) env('COLLECTION_NOTICE_UPLOAD_TTL', 60),
    ],
];
