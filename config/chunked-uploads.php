<?php

return [
    'collection_notices' => [
        'chunk_size' => (int) env('COLLECTION_NOTICE_CHUNK_SIZE', 1024 * 1024 * 2),
    ],
];
