<?php

declare(strict_types=1);

namespace App\ValueObjects\Uploads;

use InvalidArgumentException;

/**
 * Value Object que representa el payload de un evento de carga por chunks.
 *
 * Inmutable y con validación estricta de tipos (OWASP Input Validation).
 */
final readonly class ChunkUploadEventPayload
{
    /**
     * @param positive-int $dataSourceId
     * @param non-empty-string $status
     * @param non-empty-string|null $message
     * @param non-empty-string|null $filePath
     */
    private function __construct(
        public int $dataSourceId,
        public string $status,
        public ?string $message = null,
        public ?string $filePath = null,
    ) {
    }

    /**
     * Factory method con validación estricta.
     *
     * @param mixed $payload
     *
     * @throws InvalidArgumentException si el payload es inválido
     */
    public static function fromMixed(mixed $payload): self
    {
        if ($payload === null) {
            throw new InvalidArgumentException('El payload no puede ser null.');
        }

        if (is_numeric($payload)) {
            $dataSourceId = self::validateDataSourceId($payload);

            return new self(
                dataSourceId: $dataSourceId,
                status: 'unknown',
            );
        }

        if (!is_array($payload)) {
            throw new InvalidArgumentException('El payload debe ser un array o número.');
        }

        $dataSourceId = self::extractAndValidateDataSourceId($payload);
        $status = self::extractStatus($payload);
        $message = self::extractMessage($payload);
        $filePath = self::extractFilePath($payload);

        return new self(
            dataSourceId: $dataSourceId,
            status: $status,
            message: $message,
            filePath: $filePath,
        );
    }

    /**
     * @return positive-int
     */
    private static function extractAndValidateDataSourceId(array $payload): int
    {
        if (!isset($payload['dataSourceId'])) {
            throw new InvalidArgumentException('El payload debe contener dataSourceId.');
        }

        return self::validateDataSourceId($payload['dataSourceId']);
    }

    /**
     * @return positive-int
     */
    private static function validateDataSourceId(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('dataSourceId debe ser numérico.');
        }

        $id = (int) $value;

        if ($id <= 0) {
            throw new InvalidArgumentException('dataSourceId debe ser mayor a 0.');
        }

        // OWASP: Validación de rango para prevenir integer overflow
        if ($id > PHP_INT_MAX - 1) {
            throw new InvalidArgumentException('dataSourceId fuera de rango permitido.');
        }

        return $id;
    }

    /**
     * @return non-empty-string
     */
    private static function extractStatus(array $payload): string
    {
        $status = $payload['status'] ?? 'unknown';

        if (!is_string($status) || trim($status) === '') {
            return 'unknown';
        }

        // OWASP: Sanitización de entrada
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $status);

        if ($sanitized === '' || $sanitized === null) {
            return 'unknown';
        }

        // Validación contra whitelist de estados conocidos
        $allowedStatuses = [
            'uploading',
            'uploaded',
            'failed',
            'cancelled',
            'cleared',
            'completed',
            'error',
            'idle',
            'unknown',
        ];

        $normalized = strtolower($sanitized);

        if (!in_array($normalized, $allowedStatuses, true)) {
            return 'unknown';
        }

        return $normalized;
    }

    /**
     * @return non-empty-string|null
     */
    private static function extractMessage(array $payload): ?string
    {
        if (!isset($payload['message'])) {
            return null;
        }

        $message = $payload['message'];

        if (!is_string($message)) {
            return null;
        }

        // OWASP: Limitar longitud para prevenir DoS
        $trimmed = mb_substr(trim($message), 0, 1000);

        if ($trimmed === '') {
            return null;
        }

        // OWASP: Sanitización básica (sin HTML/JS)
        return htmlspecialchars($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return non-empty-string|null
     */
    private static function extractFilePath(array $payload): ?string
    {
        if (!isset($payload['filePath']) && !isset($payload['file_path'])) {
            return null;
        }

        $path = $payload['filePath'] ?? $payload['file_path'] ?? null;

        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        $trimmed = trim($path);

        // OWASP: Validación contra Path Traversal
        if (str_contains($trimmed, '..') || str_contains($trimmed, "\0")) {
            throw new InvalidArgumentException('Ruta de archivo inválida detectada.');
        }

        // Validar que inicie con prefijos permitidos
        $allowedPrefixes = ['completed/', 'pending/', 'temp/'];
        $hasValidPrefix = false;

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                $hasValidPrefix = true;
                break;
            }
        }

        if (!$hasValidPrefix) {
            throw new InvalidArgumentException('Ruta de archivo con prefijo no permitido.');
        }

        return $trimmed;
    }

    /**
     * Verifica si el evento representa un estado de carga.
     */
    public function isUploading(): bool
    {
        return $this->status === 'uploading';
    }

    /**
     * Verifica si el evento representa una carga completada.
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['uploaded', 'completed'], true);
    }

    /**
     * Verifica si el evento representa un error.
     */
    public function isError(): bool
    {
        return in_array($this->status, ['failed', 'error'], true);
    }

    /**
     * Verifica si el evento representa una cancelación.
     */
    public function isCancelled(): bool
    {
        return in_array($this->status, ['cancelled', 'cleared'], true);
    }
}