<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\ValueObjects\Uploads\UploadedFileMetadata;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;

/**
 * Servicio de validación de archivos cargados con enfoque OWASP.
 *
 * Implementa:
 * - Validación de contenido (magic bytes)
 * - Validación de tamaño
 * - Validación de MIME type vs extensión
 * - Prevención de archivos maliciosos
 */
final readonly class UploadedFileValidator
{
    /**
     * Magic bytes para tipos de archivo permitidos.
     *
     * @var array<string, list<string>>
     */
    private const MAGIC_BYTES = [
        'csv' => [
            // CSV no tiene magic bytes específicos, validar contenido
        ],
        'txt' => [
            // TXT tampoco tiene magic bytes específicos
        ],
        'xls' => [
            'd0cf11e0a1b11ae1', // Microsoft Office (OLE2)
        ],
        'xlsx' => [
            '504b0304', // ZIP (XLSX es un ZIP)
            '504b0506',
            '504b0708',
        ],
    ];

    /**
     * Mapeo de MIME types a extensiones permitidas.
     *
     * @var array<string, list<string>>
     */
    private const MIME_TO_EXTENSIONS = [
        'text/csv' => ['csv', 'txt'],
        'text/plain' => ['txt', 'csv'],
        'application/csv' => ['csv'],
        'text/x-csv' => ['csv'],
        'application/vnd.ms-excel' => ['xls', 'csv'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
    ];

    public function __construct(
        private Filesystem $disk,
        private int $maxFileSize = 536870912, // 512 MB
    ) {
    }

    /**
     * Valida un archivo cargado.
     *
     * @throws InvalidArgumentException si la metadata es inválida
     * @throws RuntimeException si la validación del archivo falla
     */
    public function validate(UploadedFileMetadata $metadata, ?string $requiredExtension = null): void
    {
        $this->validateFileExists($metadata->path);
        $this->validateFileSize($metadata);
        $this->validateMimeAndExtensionMatch($metadata);

        if ($requiredExtension !== null) {
            $this->validateRequiredExtension($metadata, $requiredExtension);
        }

        $this->validateFileContent($metadata);
    }

    /**
     * Valida que el archivo existe en el disco.
     *
     * @throws RuntimeException
     */
    private function validateFileExists(string $path): void
    {
        if (!$this->disk->exists($path)) {
            throw new RuntimeException('El archivo no existe en el almacenamiento temporal.');
        }
    }

    /**
     * Valida el tamaño del archivo contra el tamaño real en disco.
     *
     * @throws RuntimeException
     */
    private function validateFileSize(UploadedFileMetadata $metadata): void
    {
        $actualSize = $this->disk->size($metadata->path);

        if ($actualSize === false || $actualSize === null) {
            throw new RuntimeException('No fue posible determinar el tamaño del archivo.');
        }

        $actualSize = (int) $actualSize;

        // OWASP: Validar que el tamaño reportado coincida con el real (con tolerancia del 1%)
        $tolerance = (int) ceil($metadata->size * 0.01);
        $lowerBound = $metadata->size - $tolerance;
        $upperBound = $metadata->size + $tolerance;

        if ($actualSize < $lowerBound || $actualSize > $upperBound) {
            throw new RuntimeException(sprintf(
                'El tamaño del archivo no coincide. Reportado: %d bytes, Real: %d bytes.',
                $metadata->size,
                $actualSize
            ));
        }

        if ($actualSize > $this->maxFileSize) {
            throw new RuntimeException('El archivo excede el tamaño máximo permitido.');
        }

        if ($actualSize === 0) {
            throw new RuntimeException('El archivo está vacío.');
        }
    }

    /**
     * Valida que el MIME type coincida con la extensión.
     *
     * @throws RuntimeException
     */
    private function validateMimeAndExtensionMatch(UploadedFileMetadata $metadata): void
    {
        if ($metadata->mime === null || $metadata->extension === null) {
            // Si no hay MIME o extensión, validar solo con magic bytes
            return;
        }

        $allowedExtensions = self::MIME_TO_EXTENSIONS[$metadata->mime] ?? [];

        if ($allowedExtensions === []) {
            // MIME no reconocido pero ya pasó validación en el VO
            return;
        }

        if (!in_array($metadata->extension, $allowedExtensions, true)) {
            throw new RuntimeException(sprintf(
                'La extensión "%s" no corresponde con el tipo MIME "%s".',
                $metadata->extension,
                $metadata->mime
            ));
        }
    }

    /**
     * Valida que la extensión del archivo sea la requerida.
     *
     * @param non-empty-string $requiredExtension
     *
     * @throws RuntimeException
     */
    private function validateRequiredExtension(
        UploadedFileMetadata $metadata,
        string $requiredExtension
    ): void {
        $required = strtolower(trim($requiredExtension));

        // Permitir flexibilidad: si requiere csv, permitir también txt
        $allowedForRequirement = match ($required) {
            'csv' => ['csv', 'txt', 'xls', 'xlsx'],
            'xls' => ['xls'],
            'xlsx' => ['xlsx', 'xls'],
            'txt' => ['txt', 'csv'],
            default => [$required],
        };

        if ($metadata->extension === null) {
            throw new RuntimeException(sprintf(
                'El archivo debe tener extensión %s.',
                $required
            ));
        }

        if (!in_array($metadata->extension, $allowedForRequirement, true)) {
            throw new RuntimeException(sprintf(
                'El archivo debe tener extensión %s. Extensión recibida: %s.',
                $required,
                $metadata->extension
            ));
        }
    }

    /**
     * Valida el contenido del archivo usando magic bytes.
     *
     * @throws RuntimeException
     */
    private function validateFileContent(UploadedFileMetadata $metadata): void
    {
        if ($metadata->extension === null) {
            return;
        }

        $expectedMagicBytes = self::MAGIC_BYTES[$metadata->extension] ?? [];

        // CSV y TXT no tienen magic bytes, validar que sea texto legible
        if (in_array($metadata->extension, ['csv', 'txt'], true)) {
            $this->validateTextFileContent($metadata->path);

            return;
        }

        if ($expectedMagicBytes === []) {
            // No hay magic bytes definidos para esta extensión
            return;
        }

        $this->validateMagicBytes($metadata->path, $expectedMagicBytes, $metadata->extension);
    }

    /**
     * Valida que un archivo de texto sea legible y no contenga código malicioso.
     *
     * @throws RuntimeException
     */
    private function validateTextFileContent(string $path): void
    {
        // Leer los primeros 8KB del archivo
        $content = $this->disk->read($path);

        if ($content === false || $content === null) {
            throw new RuntimeException('No fue posible leer el contenido del archivo.');
        }

        $sample = mb_substr($content, 0, 8192);

        // OWASP: Detectar contenido binario en archivos que deberían ser texto
        $binaryChars = preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample);

        if ($binaryChars) {
            throw new RuntimeException(
                'El archivo contiene caracteres binarios y no puede ser procesado como texto.'
            );
        }

        // OWASP: Detectar scripts embebidos (PHP, JavaScript, VBScript)
        $dangerousPatterns = [
            '/<\?php/i',
            '/<script/i',
            '/<\?=/i',
            '/eval\s*\(/i',
            '/exec\s*\(/i',
            '/system\s*\(/i',
            '/passthru\s*\(/i',
            '/shell_exec\s*\(/i',
            '/base64_decode\s*\(/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sample)) {
                throw new RuntimeException(
                    'El archivo contiene código potencialmente peligroso y no puede ser procesado.'
                );
            }
        }
    }

    /**
     * Valida los magic bytes de un archivo.
     *
     * @param list<string> $expectedMagicBytes
     *
     * @throws RuntimeException
     */
    private function validateMagicBytes(string $path, array $expectedMagicBytes, string $extension): void
    {
        $absolutePath = $this->disk->path($path);

        if (!is_file($absolutePath)) {
            throw new RuntimeException('El archivo no es accesible para validación de contenido.');
        }

        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('No fue posible abrir el archivo para validación.');
        }

        try {
            // Leer los primeros 8 bytes
            $header = fread($handle, 8);

            if ($header === false || $header === '') {
                throw new RuntimeException('No fue posible leer la cabecera del archivo.');
            }

            $headerHex = bin2hex($header);

            // Verificar si coincide con alguno de los magic bytes esperados
            $isValid = false;

            foreach ($expectedMagicBytes as $magicByte) {
                if (str_starts_with($headerHex, $magicByte)) {
                    $isValid = true;
                    break;
                }
            }

            if (!$isValid) {
                throw new RuntimeException(sprintf(
                    'El contenido del archivo no corresponde con un archivo %s válido.',
                    strtoupper($extension)
                ));
            }
        } finally {
            fclose($handle);
        }
    }
}