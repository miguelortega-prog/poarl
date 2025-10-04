# Inventario de Jobs y Steps - Sistema de Importación

**Fecha:** 2025-10-03
**Estado:** Migración de Chunks a Go Streaming + PostgreSQL COPY

---

## 📋 STEPS ACTUALES

### ✅ STEPS NUEVOS (Go + COPY) - USAR ESTOS

1. **ConvertExcelToCSVStep.php**
   - **Propósito:** Convierte Excel (DETTRA, PAGAPL, PAGPLA) a CSV usando Go streaming
   - **Método:** Ejecuta binario `/usr/local/bin/excel_streaming`
   - **Performance:** ~8,500 filas/segundo
   - **Estado:** ✅ IMPLEMENTADO Y FUNCIONAL

2. **LoadExcelCSVsStep.php**
   - **Propósito:** Carga CSVs generados por Go usando PostgreSQL COPY
   - **Método:** PostgreSQLCopyImporter + transformación CSV→JSON
   - **Características:**
     - Transforma DETTRA y PAGPLA (39/15 cols → JSON)
     - PAGAPL directo (tiene columnas específicas)
   - **Estado:** ✅ IMPLEMENTADO, EN PRUEBA

### ❌ STEPS OBSOLETOS (Chunks) - REEMPLAZAR

3. **LoadDataSourceFilesStep.php** ❌ OBSOLETO
   - **Ubicación:** `app/UseCases/Recaudo/Comunicados/Steps/LoadDataSourceFilesStep.php`
   - **Problema:** Usa chunks de 5000 filas (líneas 93-136)
   - **Data sources afectados:** BASCAR, BAPRPO, DATPOL (CSVs directos)
   - **Código problemático:**
     ```php
     // Línea 93-136
     $chunkSize = 5000;
     foreach ($this->csvReader->readRows($file->path, $delimiter) as $row) {
         $chunk[] = $row;
         if (count($chunk) >= $chunkSize) {
             $inserted = $this->tableManager->insertDataInChunks(
                 $dataSourceCode,
                 $run->id,
                 $chunk
             );
         }
     }
     ```
   - **Solución:** Reemplazar con PostgreSQLCopyImporter
   - **Prioridad:** 🔴 CRÍTICA - Está causando que las pruebas fallen

### ✅ STEPS DE TRANSFORMACIÓN SQL (OK, no tocar)

4. **ValidateDataIntegrityStep.php** ✅
5. **FilterBascarByPeriodStep.php** ✅
6. **GenerateBascarCompositeKeyStep.php** ✅
7. **GeneratePagaplCompositeKeyStep.php** ✅
8. **CrossBascarWithPagaplStep.php** ✅
9. **RemoveCrossedBascarRecordsStep.php** ✅
10. **CountDettraWorkersAndUpdateBascarStep.php** ✅

---

## 📁 PROCESADORES

### ConstitucionMoraAportantesProcessor.php

**Pipeline actual (con problema):**
```php
return [
    $this->loadDataSourceFilesStep,      // ❌ USA CHUNKS
    $this->convertExcelToCSVStep,        // ✅ Go streaming
    $this->loadExcelCSVsStep,            // ✅ COPY + transform
    $this->validateDataStep,
    $this->generateBascarKeysStep,
    $this->generatePagaplKeysStep,
    $this->crossBascarPagaplStep,
    $this->removeCrossedBascarStep,
    $this->countDettraWorkersStep,
];
```

**Pipeline corregido (debe ser):**
```php
return [
    $this->loadCsvDataSourcesStep,       // ✅ COPY directo (nuevo)
    $this->convertExcelToCSVStep,        // ✅ Go streaming
    $this->loadExcelCSVsStep,            // ✅ COPY + transform
    $this->validateDataStep,
    $this->generateBascarKeysStep,
    $this->generatePagaplKeysStep,
    $this->crossBascarPagaplStep,
    $this->removeCrossedBascarStep,
    $this->countDettraWorkersStep,
];
```

---

## 🔧 ACCIONES NECESARIAS

### ACCIÓN 1: Crear LoadCsvDataSourcesStep.php (NUEVO)

**Archivo:** `app/UseCases/Recaudo/Comunicados/Steps/LoadCsvDataSourcesStep.php`

**Propósito:**
- Cargar CSVs directos (BASCAR, BAPRPO, DATPOL) con PostgreSQL COPY
- Reemplazar totalmente a `LoadDataSourceFilesStep`

**Características:**
- Usar PostgreSQLCopyImporter
- Data sources: BASCAR, BAPRPO, DATPOL (solo CSV, no Excel)
- Sin transformación (COPY directo)

**Implementación:**
```php
final readonly class LoadCsvDataSourcesStep implements ProcessingStepInterface
{
    private const CSV_DATA_SOURCES = ['BASCAR', 'BAPRPO', 'DATPOL'];

    public function __construct(
        private FilesystemFactory $filesystem,
        private PostgreSQLCopyImporter $copyImporter
    ) {}

    public function execute(ProcessingContextDto $context): ProcessingContextDto
    {
        foreach ($run->files as $file) {
            $dataSourceCode = $file->dataSource->code;
            $extension = strtolower($file->ext ?? '');

            // Solo procesar CSVs directos
            if (!in_array($dataSourceCode, self::CSV_DATA_SOURCES, true)) {
                continue;
            }

            if ($extension !== 'csv') {
                continue;
            }

            // Importar con PostgreSQL COPY
            $result = $this->copyImporter->importFromFile(
                $tableName,
                $disk->path($file->path),
                $columns,
                ';',
                true
            );
        }
    }
}
```

### ACCIÓN 2: Actualizar ConstitucionMoraAportantesProcessor

**Archivo:** `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php`

**Cambio:**
```php
// ANTES
private readonly LoadDataSourceFilesStep $loadDataSourceFilesStep,

// DESPUÉS
private readonly LoadCsvDataSourcesStep $loadCsvDataSourcesStep,
```

### ACCIÓN 3: Eliminar o marcar como deprecated

**Archivos a eliminar/deprecar:**
- `app/UseCases/Recaudo/Comunicados/Steps/LoadDataSourceFilesStep.php`

---

## 📊 COMPARATIVA: ANTES vs DESPUÉS

### Data Sources y Su Método de Carga

| Data Source | Tipo | ANTES (Chunks) | DESPUÉS (Go + COPY) |
|-------------|------|----------------|---------------------|
| **BASCAR** | CSV | LoadDataSourceFilesStep ❌ | LoadCsvDataSourcesStep ✅ |
| **BAPRPO** | CSV | LoadDataSourceFilesStep ❌ | LoadCsvDataSourcesStep ✅ |
| **DATPOL** | CSV | LoadDataSourceFilesStep ❌ | LoadCsvDataSourcesStep ✅ |
| **DETTRA** | Excel | LoadDettraAllSheetsStep ❌ ELIMINADO | ConvertExcelToCSVStep + LoadExcelCSVsStep ✅ |
| **PAGAPL** | Excel | LoadPagaplSheetByPeriodStep ❌ ELIMINADO | ConvertExcelToCSVStep + LoadExcelCSVsStep ✅ |
| **PAGPLA** | Excel | (chunks) ❌ | ConvertExcelToCSVStep + LoadExcelCSVsStep ✅ |

### Performance Esperada

| Operación | ANTES (Chunks) | DESPUÉS (COPY) | Mejora |
|-----------|----------------|----------------|--------|
| CSV 50K filas (BASCAR) | ~30-60s | ~1-3s | 10-30x |
| Excel 2.6M filas (DETTRA) | timeout/fallos | ~5min | ∞ (antes no funcionaba) |
| Pipeline completo | ~2-3 horas | ~15-25 min | 6-10x |

---

## 🎯 MAPEO DE COLUMNAS POR DATA SOURCE

### CSVs Directos (BASCAR, BAPRPO, DATPOL)

**BASCAR:**
- Tabla: `data_source_bascar`
- Columnas: Múltiples columnas específicas (56 parametrizadas)
- COPY: Directo, columna por columna

**BAPRPO:**
- Tabla: `data_source_baprpo`
- Columnas: Múltiples columnas específicas
- COPY: Directo, columna por columna

**DATPOL:**
- Tabla: `data_source_datpol`
- Columnas: Múltiples columnas específicas
- COPY: Directo, columna por columna

### Excels (DETTRA, PAGAPL, PAGPLA)

**DETTRA:**
- Tabla: `data_source_dettra` → `id, run_id, data (jsonb), created_at, sheet_name`
- CSV Go: 39 columnas separadas
- Transformación: 39 cols → JSON en campo `data`
- COPY: CSV transformado (3 columnas)

**PAGAPL:**
- Tabla: `data_source_pagapl` → `id, run_id, identificacion, periodo, valor, composite_key, data (jsonb), created_at, sheet_name`
- CSV Go: 16 columnas separadas
- Transformación: Extraer 3 columnas + resto en JSON
- COPY: CSV con columnas específicas (7 columnas)

**PAGPLA:**
- Tabla: `data_source_pagpla` → `id, run_id, data (jsonb), created_at, sheet_name`
- CSV Go: 15 columnas separadas
- Transformación: 15 cols → JSON en campo `data`
- COPY: CSV transformado (3 columnas)

---

## 🚨 PROBLEMA ACTUAL

**Síntoma:**
```
Logs muestran: "Cargando CSV a base de datos en chunks"
```

**Causa:**
`LoadDataSourceFilesStep` está ejecutándose y usando chunks para BASCAR, BAPRPO, DATPOL

**Solución:**
1. Crear `LoadCsvDataSourcesStep` con PostgreSQL COPY
2. Reemplazar en `ConstitucionMoraAportantesProcessor`
3. Eliminar `LoadDataSourceFilesStep`

---

## ✅ CHECKLIST DE MIGRACIÓN

- [ ] Crear LoadCsvDataSourcesStep.php
- [ ] Obtener mapeo de columnas para BASCAR, BAPRPO, DATPOL
- [ ] Implementar COPY directo en LoadCsvDataSourcesStep
- [ ] Actualizar ConstitucionMoraAportantesProcessor
- [ ] Probar pipeline completo
- [ ] Eliminar LoadDataSourceFilesStep
- [ ] Actualizar documentación

---

**Estado actual:** 🔴 BLOQUEADO - Necesitamos implementar LoadCsvDataSourcesStep antes de continuar pruebas
