<?php

declare(strict_types=1);

namespace App\ValueObjects\Uploads;

use InvalidArgumentException;

/**
 * Value Object inmutable que representa metadata de un archivo cargado.
 *
 * Implementa validaciones OWASP para prevenir:
 * - Path Traversal
 * - File Upload vulnerabilities
 * - Content-Type mismatch
 */
final readonly class UploadedFileMetadata
{
    private const MAX_FILENAME_LENGTH = 255;
    private const MAX_FILE_SIZE = 536870912; // 512 MB
    private const MIN_FILE_SIZE = 1;

    /**
     * @param non-empty-string $path
     * @param non-empty-string $originalName
     * @param positive-int $size
     * @param non-empty-string|null $mime
     * @param non-empty-string|null $extension
     */
    private function __construct(
        public string $path,
        public string $originalName,
        public int $size,
        public ?string $mime = null,
        public ?string $extension = null,
    ) {
    }

    /**
     * Factory method con validación exhaustiva.
     *
     * @param array<string, mixed>|null $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null || $data === []) {
            throw new InvalidArgumentException('Los datos del archivo no pueden estar vacíos.');
        }

        $path = self::validatePath($data);
        $originalName = self::validateOriginalName($data);
        $size = self::validateSize($data);
        $mime = self::validateMime($data);
        $extension = self::validateExtension($data);

        return new self(
            path: $path,
            originalName: $originalName,
            size: $size,
            mime: $mime,
            extension: $extension,
        );
    }

    /**
     * Valida y sanitiza la ruta del archivo.
     *
     * @param array<string, mixed> $data
     *
     * @return non-empty-string
     *
     * @throws InvalidArgumentException
     */
    private static function validatePath(array $data): string
    {
        if (!isset($data['path'])) {
            throw new InvalidArgumentException('La ruta del archivo es requerida.');
        }

        if (!is_string($data['path']) || trim($data['path']) === '') {
            throw new InvalidArgumentException('La ruta del archivo debe ser una cadena no vacía.');
        }

        $path = trim($data['path']);

        // OWASP: Prevenir Path Traversal
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Ruta de archivo inválida: contiene secuencias prohibidas.');
        }

        // OWASP: Validar contra caracteres peligrosos
        if (preg_match('/[<>:"|?*]/', $path)) {
            throw new InvalidArgumentException('Ruta de archivo contiene caracteres no permitidos.');
        }

        // Validar prefijos permitidos
        if (!str_starts_with($path, 'completed/') && !str_starts_with($path, 'pending/')) {
            throw new InvalidArgumentException('La ruta debe iniciar con un prefijo válido (completed/ o pending/).');
        }

        return $path;
    }

    /**
     * Valida y sanitiza el nombre original del archivo.
     *
     * @param array<string, mixed> $data
     *
     * @return non-empty-string
     *
     * @throws InvalidArgumentException
     */
    private static function validateOriginalName(array $data): string
    {
        if (!isset($data['original_name'])) {
            throw new InvalidArgumentException('El nombre original del archivo es requerido.');
        }

        if (!is_string($data['original_name']) || trim($data['original_name']) === '') {
            throw new InvalidArgumentException('El nombre del archivo debe ser una cadena no vacía.');
        }

        $name = trim($data['original_name']);

        // OWASP: Limitar longitud
        if (mb_strlen($name) > self::MAX_FILENAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El nombre del archivo excede la longitud máxima de %d caracteres.',
                self::MAX_FILENAME_LENGTH
            ));
        }

        // OWASP: Prevenir nombres peligrosos
        if (str_contains($name, "\0") || str_contains($name, '..')) {
            throw new InvalidArgumentException('Nombre de archivo inválido: contiene caracteres prohibidos.');
        }

        // Validar caracteres permitidos (permitir espacios, guiones, puntos, letras y números)
        if (!preg_match('/^[\w\s.\-()áéíóúñÁÉÍÓÚÑ]+\.[a-zA-Z0-9]{1,10}$/u', $name)) {
            throw new InvalidArgumentException('El nombre del archivo contiene caracteres no permitidos.');
        }

        return $name;
    }

    /**
     * Valida el tamaño del archivo.
     *
     * @param array<string, mixed> $data
     *
     * @return positive-int
     *
     * @throws InvalidArgumentException
     */
    private static function validateSize(array $data): int
    {
        if (!isset($data['size'])) {
            throw new InvalidArgumentException('El tamaño del archivo es requerido.');
        }

        if (!is_numeric($data['size'])) {
            throw new InvalidArgumentException('El tamaño del archivo debe ser numérico.');
        }

        $size = (int) $data['size'];

        if ($size < self::MIN_FILE_SIZE) {
            throw new InvalidArgumentException('El archivo está vacío o es demasiado pequeño.');
        }

        if ($size > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException(sprintf(
                'El archivo excede el tamaño máximo permitido de %d bytes.',
                self::MAX_FILE_SIZE
            ));
        }

        return $size;
    }

    /**
     * Valida el tipo MIME del archivo.
     *
     * @param array<string, mixed> $data
     *
     * @return non-empty-string|null
     */
    private static function validateMime(array $data): ?string
    {
        if (!isset($data['mime']) || $data['mime'] === null || $data['mime'] === '') {
            return null;
        }

        if (!is_string($data['mime'])) {
            return null;
        }

        $mime = strtolower(trim($data['mime']));

        if ($mime === '') {
            return null;
        }

        // OWASP: Validar formato MIME type
        if (!preg_match('/^[a-z0-9]+\/[a-z0-9\-+.]+$/', $mime)) {
            return null;
        }

        // Whitelist de MIME types permitidos
        $allowedMimes = [
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/csv',
            'text/x-csv',
        ];

        if (!in_array($mime, $allowedMimes, true)) {
            throw new InvalidArgumentException(sprintf(
                'Tipo MIME no permitido: %s',
                htmlspecialchars($mime, ENT_QUOTES, 'UTF-8')
            ));
        }

        return $mime;
    }

    /**
     * Valida la extensión del archivo.
     *
     * @param array<string, mixed> $data
     *
     * @return non-empty-string|null
     */
    private static function validateExtension(array $data): ?string
    {
        if (!isset($data['extension']) || $data['extension'] === null || $data['extension'] === '') {
            return null;
        }

        if (!is_string($data['extension'])) {
            return null;
        }

        $extension = strtolower(trim($data['extension']));

        if ($extension === '') {
            return null;
        }

        // OWASP: Validar contra extensiones peligrosas
        $dangerousExtensions = [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps',
            'exe', 'bat', 'cmd', 'com', 'pif', 'scr',
            'js', 'vbs', 'wsf', 'sh', 'bash',
        ];

        if (in_array($extension, $dangerousExtensions, true)) {
            throw new InvalidArgumentException('Extensión de archivo no permitida por razones de seguridad.');
        }

        // Whitelist de extensiones permitidas
        $allowedExtensions = ['csv', 'txt', 'xls', 'xlsx'];

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException(sprintf(
                'Extensión no permitida: %s. Solo se permiten: %s',
                htmlspecialchars($extension, ENT_QUOTES, 'UTF-8'),
                implode(', ', $allowedExtensions)
            ));
        }

        return $extension;
    }

    /**
     * Convierte el Value Object a un array asociativo.
     *
     * @return array{path: string, original_name: string, size: int, mime: string|null, extension: string|null}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'original_name' => $this->originalName,
            'size' => $this->size,
            'mime' => $this->mime,
            'extension' => $this->extension,
        ];
    }

    /**
     * Verifica si el archivo es de tipo CSV/texto.
     */
    public function isCsvOrText(): bool
    {
        return in_array($this->extension, ['csv', 'txt'], true);
    }

    /**
     * Verifica si el archivo es de tipo Excel.
     */
    public function isExcel(): bool
    {
        return in_array($this->extension, ['xls', 'xlsx'], true);
    }
}