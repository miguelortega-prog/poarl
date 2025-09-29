<?php

namespace App\DTOs\Recaudo\Comunicados;

final class DataSourceUploadDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $code,
        public readonly ?string $extension,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $id = isset($attributes['id']) ? (int) $attributes['id'] : 0;
        $name = isset($attributes['name']) ? (string) $attributes['name'] : '';
        $code = isset($attributes['code']) ? (string) $attributes['code'] : '';
        $extension = isset($attributes['extension']) && $attributes['extension'] !== null
            ? (string) $attributes['extension']
            : null;

        return new self($id, $name, $code, $extension);
    }
}
