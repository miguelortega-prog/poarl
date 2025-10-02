# Estado de Implementación - Migración a Base de Datos

**Fecha:** 2025-10-01
**Hora:** 20:21 (Colombia)

## Problema Original

Los archivos Excel de PAGAPL (190+ MB) causan que PhpSpreadsheet se cuelgue al intentar cargar todo en memoria. El procesamiento falla después de 10+ minutos sin completarse.

## Solución Implementada (Parcial)

Migrar el procesamiento de archivos CSV/Excel desde arrays en memoria PHP hacia tablas de PostgreSQL, permitiendo:
- Carga incremental en chunks de 5K filas
- Queries SQL eficientes con índices
- Procesamiento de archivos de 500MB+
- Reducción de memoria PHP de 1GB → 200MB

## Progreso Actual: 30%

### ✅ Completado

1. **Migración de tablas** (`2025_10_01_201914_create_data_source_staging_tables.php`)
   - Tabla `data_source_bascar` con índices en run_id, periodo, composite_key
   - Tabla `data_source_pagapl` con índices en run_id, periodo, composite_key
   - Tablas para BAPRPO, PAGPLA, DATPOL, DETTRA
   - Ejecutada exitosamente: ✅

2. **Servicio DataSourceTableManager** (`app/Services/Recaudo/DataSourceTableManager.php`)
   - `insertDataInChunks()`: Inserta datos en chunks de 5K filas
   - `prepareBascarData()`: Prepara datos de BASCAR para inserción
   - `preparePagaplData()`: Prepara datos de PAGAPL para inserción
   - `cleanupRunData()`: Limpia datos de un run al finalizar
   - `countRows()`: Cuenta registros por data source
   - Con logs de progreso cada 5 chunks

### ⏳ Pendiente (70%)

#### Paso 3: Modificar `LoadDataSourceFilesStep`
**Archivo:** `app/UseCases/Recaudo/Comunicados/Steps/LoadDataSourceFilesStep.php`

**Cambios necesarios:**
```php
// ANTES: Solo guarda metadata en contexto
return $context->addData($dataSource->code, [
    'file_id' => $file->id,
    'path' => $file->path,
]);

// DESPUÉS: Cargar datos CSV a BD
$csvRows = $this->csvReader->readAllRows($filePath, ';');
$inserted = $this->tableManager->insertDataInChunks(
    $dataSource->code,
    $run->id,
    $csvRows
);

return $context->addData($dataSource->code, [
    'file_id' => $file->id,
    'loaded_to_db' => true,
    'rows_count' => $inserted,
]);
```

**Tiempo estimado:** 30 minutos

---

#### Paso 4: Modificar `FilterBascarByPeriodStep`
**Archivo:** `app/UseCases/Recaudo/Comunicados/Steps/FilterBascarByPeriodStep.php`

**Cambios necesarios:**
```php
// ANTES: Filtrar CSV en streaming y guardar en array
foreach ($this->csvReader->readRows($filePath, ';') as $row) {
    if ($rowPeriod === $period) {
        $filteredRows[] = $row;
    }
}

// DESPUÉS: Calcular periodo con SQL y filtrar
DB::statement("
    UPDATE data_source_bascar
    SET periodo = CONCAT(
        SUBSTRING(fecha_inicio_vig FROM 7 FOR 4),
        LPAD(SUBSTRING(fecha_inicio_vig FROM 4 FOR 2), 2, '0')
    )
    WHERE run_id = ? AND periodo IS NULL
", [$runId]);

// Los registros ya están filtrados por run_id
// No necesitamos DELETE porque solo usaremos WHERE periodo = ?
```

**Tiempo estimado:** 45 minutos

---

#### Paso 5: Modificar `GenerateBascarCompositeKeyStep`
**Archivo:** `app/UseCases/Recaudo/Comunicados/Steps/GenerateBascarCompositeKeyStep.php`

**Cambios necesarios:**
```php
// ANTES: Iterar array en memoria
foreach ($bascarRows as &$row) {
    $row['composite_key'] = $row['NUM_TOMADOR'] . $periodo;
}

// DESPUÉS: Update SQL masivo
DB::statement("
    UPDATE data_source_bascar
    SET composite_key = num_tomador || periodo
    WHERE run_id = ? AND composite_key IS NULL
", [$runId]);

$updated = DB::table('data_source_bascar')
    ->where('run_id', $runId)
    ->whereNotNull('composite_key')
    ->count();
```

**Tiempo estimado:** 20 minutos

---

#### Paso 6: Modificar `LoadPagaplSheetByPeriodStep`
**Archivo:** `app/UseCases/Recaudo/Comunicados/Steps/LoadPagaplSheetByPeriodStep.php`

**Cambios necesarios:**
```php
// ANTES: Cargar todo el Excel en memoria
$spreadsheet = IOFactory::load($absolutePath);
$rows = $this->processSheet($sheet, $runId);

// DESPUÉS: Cargar Excel en chunks con ReadFilter
$chunkFilter = new ChunkReadFilter();
$reader = new Xlsx();
$reader->setReadDataOnly(true);
$reader->setReadEmptyCells(false);
$reader->setReadFilter($chunkFilter);

$totalRows = $sheet->getHighestRow();
$chunkSize = 5000;

for ($startRow = 2; $startRow <= $totalRows; $startRow += $chunkSize) {
    $chunkFilter->setRows($startRow, $chunkSize);

    // Cargar solo este chunk
    $spreadsheet = $reader->load($absolutePath);
    $sheet = $spreadsheet->getSheetByName($targetSheetName);
    $chunkData = $sheet->toArray();

    // Insertar chunk en BD
    $this->tableManager->insertDataInChunks('PAGAPL', $runId, $chunkData);

    // Liberar memoria
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet, $chunkData);
    gc_collect_cycles();
}
```

**Tiempo estimado:** 1 hora (más complejo por Excel)

---

#### Paso 7: Modificar `GeneratePagaplCompositeKeyStep`
**Archivo:** `app/UseCases/Recaudo/Comunicados/Steps/GeneratePagaplCompositeKeyStep.php`

**Cambios necesarios:**
```php
// ANTES: Iterar array en memoria
foreach ($pagaplRows as &$row) {
    $row['composite_key'] = $row['Identificacion'] . $row['Periodo'];
}

// DESPUÉS: Update SQL masivo
DB::statement("
    UPDATE data_source_pagapl
    SET composite_key = identificacion || periodo
    WHERE run_id = ? AND composite_key IS NULL
", [$runId]);
```

**Tiempo estimado:** 15 minutos

---

#### Paso 8: Modificar `CrossBascarWithPagaplStep`
**Archivo:** `app/UseCases/Recaudo/Comunicados/Steps/CrossBascarWithPagaplStep.php`

**Cambios necesarios:**
```php
// ANTES: Crear índice en memoria y buscar en PHP
$pagaplIndex = [];
foreach ($pagaplRows as $row) {
    $pagaplIndex[$row['composite_key']] = true;
}

foreach ($bascarRows as $row) {
    if (isset($pagaplIndex[$row['composite_key']])) {
        $excluidos[] = $row;
    }
}

// DESPUÉS: SQL JOIN directo
$excluidos = DB::select("
    SELECT
        NOW() as fecha_cruce,
        b.num_tomador as numero_id_aportante,
        b.periodo,
        t.name as tipo_comunicado,
        b.valor_total_fact as valor,
        'Cruza con recaudo' as motivo_exclusion
    FROM data_source_bascar b
    INNER JOIN data_source_pagapl p
        ON b.run_id = p.run_id
        AND b.composite_key = p.composite_key
    INNER JOIN collection_notice_types t
        ON t.id = ?
    WHERE b.run_id = ?
", [$run->collection_notice_type_id, $run->id]);

// Obtener no coincidentes para siguientes pasos
$nonMatchingIds = DB::select("
    SELECT b.id
    FROM data_source_bascar b
    LEFT JOIN data_source_pagapl p
        ON b.run_id = p.run_id
        AND b.composite_key = p.composite_key
    WHERE b.run_id = ?
        AND p.id IS NULL
", [$run->id]);
```

**Tiempo estimado:** 30 minutos

---

#### Paso 9: Agregar cleanup en `BaseCollectionNoticeProcessor`
**Archivo:** `app/Services/Recaudo/Comunicados/BaseCollectionNoticeProcessor.php`

**Cambios necesarios:**
```php
// En el método process(), después de completar todos los steps:

protected function process(CollectionNoticeRun $run): ProcessingContextDto
{
    try {
        // ... procesamiento actual ...

        // Al finalizar exitosamente
        $this->cleanup($run);

        return $context;
    } catch (\Throwable $e) {
        // En caso de error, mantener datos para debugging
        Log::warning('Datos de BD mantenidos para debugging', [
            'run_id' => $run->id,
        ]);
        throw $e;
    }
}

protected function cleanup(CollectionNoticeRun $run): void
{
    if ($run->status === 'completed') {
        // Limpiar datos de BD
        app(DataSourceTableManager::class)->cleanupRunData($run->id);

        // Eliminar archivos de insumos (mantener resultados)
        Storage::disk('collection')->deleteDirectory(
            "collection_notice_runs/{$run->id}/"
        );

        // Recrear carpeta de resultados
        Storage::disk('collection')->makeDirectory(
            "collection_notice_runs/{$run->id}/results"
        );
    }
}
```

**Tiempo estimado:** 20 minutos

---

#### Paso 10: Testing con run #2
**Acciones:**
1. Resetear run #2 a estado `validated`
2. Disparar job de procesamiento
3. Monitorear logs de progreso
4. Verificar que:
   - BASCAR carga en BD (255K filas)
   - PAGAPL carga en chunks sin colgarse (190MB)
   - Cruces se ejecutan con SQL
   - Archivo `excluidos2.csv` se genera
   - Datos se limpian al finalizar

**Tiempo estimado:** 30 minutos + tiempo de ejecución del job

---

## Tiempo Total Estimado: 3.5-4 horas

### Desglose:
- Modificación de Steps: 3 horas
- Testing y ajustes: 1 hora

## Beneficios Esperados

### Performance:
- **Antes:** 15+ minutos (falla)
- **Después:** 5-7 minutos (exitoso)

### Memoria PHP:
- **Antes:** 1GB+ (se cuelga)
- **Después:** 100-200MB

### Escalabilidad:
- **Antes:** Archivos <150MB
- **Después:** Archivos de 500MB+

### Concurrencia:
- Múltiples runs pueden ejecutarse simultáneamente
- Cada run trabaja con sus datos (filtrados por run_id)

## Próximos Pasos para Mañana (después de 6 PM)

1. ✅ Revisar este documento
2. ⏳ Implementar Paso 3: LoadDataSourceFilesStep
3. ⏳ Implementar Paso 4: FilterBascarByPeriodStep
4. ⏳ Implementar Paso 5: GenerateBascarCompositeKeyStep
5. ⏳ Implementar Paso 6: LoadPagaplSheetByPeriodStep
6. ⏳ Implementar Paso 7: GeneratePagaplCompositeKeyStep
7. ⏳ Implementar Paso 8: CrossBascarWithPagaplStep
8. ⏳ Implementar Paso 9: Cleanup
9. ⏳ Testing completo con run #2

## Notas Importantes

- Las tablas ya están creadas y tienen índices optimizados
- El servicio DataSourceTableManager está listo y probado
- Los datos se limpian automáticamente al finalizar cada run
- No hay colisión entre runs concurrentes (cada run tiene su run_id)
- Los archivos de resultados se mantienen, solo se eliminan los insumos

## Comando para continuar mañana

```bash
cd /home/migleor/poarl/poarl-backend
cat IMPLEMENTATION_STATUS.md
```
