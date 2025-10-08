<?php

declare(strict_types=1);

namespace App\DTOs\Recaudo\Comunicados;

use App\Models\CollectionNoticeRun;

/**
 * DTO que encapsula el contexto de procesamiento de un comunicado.
 *
 * Permite pasar datos entre los diferentes pasos del pipeline
 * de forma inmutable y tipada.
 */
final readonly class ProcessingContextDto
{
    /**
     * @param CollectionNoticeRun $run Run siendo procesado
     * @param array<string, mixed> $data Datos procesados (por data source)
     * @param array<string, mixed> $metadata Metadatos adicionales
     * @param array<int, string> $errors Errores acumulados durante procesamiento
     * @param array<string, mixed> $results Resultados de cada paso
     */
    public function __construct(
        public CollectionNoticeRun $run,
        public array $data = [],
        public array $metadata = [],
        public array $errors = [],
        public array $results = [],
    ) {
    }

    /**
     * Crea una nueva instancia con datos actualizados.
     *
     * @param array<string, mixed> $data
     *
     * @return self
     */
    public function withData(array $data): self
    {
        return new self(
            run: $this->run,
            data: $data,
            metadata: $this->metadata,
            errors: $this->errors,
            results: $this->results,
        );
    }

    /**
     * Crea una nueva instancia agregando datos adicionales.
     *
     * @param string $key
     * @param mixed $value
     *
     * @return self
     */
    public function addData(string $key, mixed $value): self
    {
        $newData = $this->data;
        $newData[$key] = $value;

        return new self(
            run: $this->run,
            data: $newData,
            metadata: $this->metadata,
            errors: $this->errors,
            results: $this->results,
        );
    }

    /**
     * Crea una nueva instancia con un error agregado.
     *
     * @param string $error
     *
     * @return self
     */
    public function addError(string $error): self
    {
        $newErrors = $this->errors;
        $newErrors[] = $error;

        return new self(
            run: $this->run,
            data: $this->data,
            metadata: $this->metadata,
            errors: $newErrors,
            results: $this->results,
        );
    }

    /**
     * Crea una nueva instancia con un resultado de paso agregado.
     *
     * @param string $stepName
     * @param mixed $result
     *
     * @return self
     */
    public function addStepResult(string $stepName, mixed $result): self
    {
        $newResults = $this->results;
        $newResults[$stepName] = $result;

        return new self(
            run: $this->run,
            data: $this->data,
            metadata: $this->metadata,
            errors: $this->errors,
            results: $newResults,
        );
    }

    /**
     * Crea una nueva instancia con metadatos actualizados.
     *
     * @param string $key
     * @param mixed $value
     *
     * @return self
     */
    public function addMetadata(string $key, mixed $value): self
    {
        $newMetadata = $this->metadata;
        $newMetadata[$key] = $value;

        return new self(
            run: $this->run,
            data: $this->data,
            metadata: $newMetadata,
            errors: $this->errors,
            results: $this->results,
        );
    }

    /**
     * Verifica si hay errores en el contexto.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Obtiene un valor de los datos procesados.
     *
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    public function getData(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Obtiene un valor de metadata.
     *
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
