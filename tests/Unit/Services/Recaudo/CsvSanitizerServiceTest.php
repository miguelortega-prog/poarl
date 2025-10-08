<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recaudo;

use App\Services\Recaudo\CsvSanitizerService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CsvSanitizerServiceTest extends TestCase
{
    private CsvSanitizerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CsvSanitizerService();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testSanitizeBascarProducesStableColumnCount(): void
    {
        $originalPath = $this->createTemporaryCsv([
            'NUM_TOMADOR;FECHA_INICIO_VIG;VALOR_TOTAL_FACT;DESCRIPCION;OBS',
            '0001;2024-01-01;1.234,56;"Valor; con ; separador";"Linea\\con\\backslash"',
        ]);

        $result = $this->service->sanitize($originalPath, 99, 'BASCAR');

        self::assertTrue($result->temporary);
        self::assertFileExists($result->path);

        $lines = file($result->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($lines);
        self::assertCount(2, $lines);

        $headerColumns = str_getcsv($lines[0], ';');
        self::assertCount(10, $headerColumns);

        $dataColumns = str_getcsv($lines[1], ';', '"');
        self::assertCount(10, $dataColumns);

        $jsonPayload = json_decode($dataColumns[6], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Valor; con ; separador', $jsonPayload['DESCRIPCION']);
        self::assertSame('Linea con backslash', $jsonPayload['OBS']);

        $this->deleteFiles($originalPath, $result->path);
    }

    public function testSanitizeGenericDataSourceWrapsEntireRowAsJson(): void
    {
        $originalPath = $this->createTemporaryCsv([
            'CAMPO1;CAMPO2',
            '"texto;con;delimitador";otro',
        ]);

        $result = $this->service->sanitize($originalPath, 10, 'BAPRPO');

        $lines = file($result->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($lines);
        self::assertCount(2, $lines);

        self::assertSame('run_id;data;sheet_name', $lines[0]);

        $dataColumns = str_getcsv($lines[1], ';', '"');
        self::assertSame('10', $dataColumns[0]);
        $jsonPayload = json_decode($dataColumns[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('texto;con;delimitador', $jsonPayload['CAMPO1']);
        self::assertSame('otro', $jsonPayload['CAMPO2']);

        $this->deleteFiles($originalPath, $result->path);
    }

    public function testThrowsExceptionWhenRequiredBascarColumnMissing(): void
    {
        $originalPath = $this->createTemporaryCsv([
            'NUM_TOMADOR;FECHA_INICIO_VIG',
            '0001;2024-01-01',
        ]);

        $this->expectException(RuntimeException::class);

        try {
            $this->service->sanitize($originalPath, 5, 'BASCAR');
        } finally {
            $this->deleteFiles($originalPath);
        }
    }

    private function createTemporaryCsv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_');
        if ($path === false) {
            throw new RuntimeException('No se pudo crear archivo temporal de prueba');
        }

        file_put_contents($path, implode(PHP_EOL, $lines));

        return $path;
    }

    private function deleteFiles(string ...$paths): void
    {
        foreach ($paths as $path) {
            if ($path !== '' && file_exists($path)) {
                unlink($path);
            }
        }
    }
}
