<?php

namespace App\Http\Controllers;

use App\Services\Uploads\ChunkedUploadManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CollectionNoticeChunkUploadController
{
    public function __construct(private readonly ChunkedUploadManager $uploads)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $maxChunkSize = (int) config('chunked-uploads.collection_notices.chunk_size');
        $maxChunkSizeKb = $maxChunkSize > 0 ? (int) ceil($maxChunkSize / 1024) : null;

        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{10,191}$/'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'total_chunks' => ['required', 'integer', 'min:1'],
            'chunk' => array_filter([
                'required',
                'file',
                $maxChunkSizeKb ? 'max:' . $maxChunkSizeKb : null,
            ]),
            'original_name' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'integer', 'min:1'],
            'mime' => ['nullable', 'string', 'max:191'],
            'extension' => ['nullable', 'string', 'max:30'],
        ]);

        $metadata = [
            'original_name' => $validated['original_name'] ?? null,
            'size' => isset($validated['size']) ? (int) $validated['size'] : null,
            'mime' => $validated['mime'] ?? null,
            'extension' => $validated['extension'] ?? null,
        ];

        try {
            $result = $this->uploads->appendChunk(
                $validated['upload_id'],
                (int) $validated['chunk_index'],
                (int) $validated['total_chunks'],
                $validated['chunk'],
                $metadata,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'chunk' => [$exception->getMessage()],
            ]);
        }

        return response()->json($result);
    }
}
