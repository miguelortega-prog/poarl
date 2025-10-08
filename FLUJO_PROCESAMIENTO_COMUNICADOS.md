# Flujo de Procesamiento de Comunicados de Recaudo

## Secuencia Completa

### 1. Creación del Run
- Se crea un `CollectionNoticeRun` con estado inicial `pending`
- Se suben archivos asociados al run

### 2. ProcessCollectionRunValidation Job
**Ubicación**: `app/Jobs/ProcessCollectionRunValidation.php`

**Responsabilidad**: Valida archivos y coordina la carga de datos

**Flujo**:
- Valida los archivos del run (estructura, columnas, etc.)
- Si la validación es exitosa, crea un **chain secuencial** de jobs:
  1. `LoadCsvDataSourcesJob` - Un job por cada archivo CSV
  2. `LoadExcelWithCopyJob` - Un job por cada archivo Excel
  3. `ProcessCollectionDataJob` - Job final de procesamiento SQL

**Estados**:
- Input: `pending`, `validating`, `validated`
- Output: `validated` (si éxito), `validation_failed` (si falla)

### 3. LoadCsvDataSourcesJob
**Ubicación**: `app/Jobs/LoadCsvDataSourcesJob.php`

**Responsabilidad**: Carga archivos CSV a las tablas de staging en BD

**Data Sources que carga**: BASCAR, BAPRPO, DATPOL

### 4. LoadExcelWithCopyJob
**Ubicación**: `app/Jobs/LoadExcelWithCopyJob.php`

**Responsabilidad**:
- Convierte hojas de Excel a CSV
- Carga los CSV generados a las tablas de staging en BD

**Data Sources que carga**: DETTRA, PAGAPL, PAGPLA

### 5. ProcessCollectionDataJob (PUNTO CRÍTICO)
**Ubicación**: `app/Jobs/ProcessCollectionDataJob.php`

**Responsabilidad**: Ejecuta el procesamiento SQL del comunicado

**Flujo**:
1. Verifica que el run esté en estado `validated` (línea 77)
2. Cambia el estado a `processing` (línea 87-90)
3. Resuelve el procesador desde la configuración (línea 97)
4. Valida que el procesador pueda procesar (`canProcess()`, línea 100)
5. Ejecuta `processor->process($run)` (línea 107)

**Estados**:
- Input: `validated`
- Durante ejecución: `processing`
- Output: `completed` (si éxito), `failed` (si falla)

### 6. BaseCollectionNoticeProcessor
**Ubicación**: `app/Services/Recaudo/Comunicados/BaseCollectionNoticeProcessor.php`

**Responsabilidad**: Clase base que implementa el patrón Pipeline

**Método `process()`** (línea 51):
- Inicia transacción DB
- Ejecuta pipeline de steps (`executePipeline()`, línea 65)
- Marca run como `completed` si éxito
- Marca run como `failed` si error (rollback)

**Método `canProcess()`** (línea 155):
- Verifica que el run esté en estado `validated` (línea 158)
- Verifica que tenga un tipo de procesador asignado

### 7. ConstitucionMoraAportantesProcessor
**Ubicación**: `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php`

**Responsabilidad**: Procesador específico para "Constitución en Mora - Aportantes"

**Steps del pipeline** (línea 112-170):
1. `ValidateDataIntegrityStep` - Valida que los datos se cargaron correctamente
2. `FilterDataByPeriodStep` - Filtra datos por periodo
3. `GenerateBascarCompositeKeyStep` - Genera llaves compuestas en BASCAR
4. `GeneratePagaplCompositeKeyStep` - Genera llaves compuestas en PAGAPL
5. `CrossBascarWithPagaplStep` - Cruza BASCAR con PAGAPL
6. `RemoveCrossedBascarRecordsStep` - Elimina registros cruzados
7. `IdentifyPsiStep` - Identifica PSI
8. `ExcludePsiPersonaJuridicaStep` - Excluye PSI Persona Jurídica
9. `CountDettraWorkersAndUpdateBascarStep` - Cuenta trabajadores
10. `CrearBaseTrabajadoresActivosStep` - Crea base trabajadores activos
11. `AppendBascarSinTrabajadoresStep` - Agrega BASCAR sin trabajadores
12. `AddCityCodeToBascarStep` - Agrega código de ciudad

## Estados del CollectionNoticeRun

```
pending → validating → validated → processing → completed
                    ↓              ↓            ↓
            validation_failed    failed      failed
```

## Cómo Ejecutar Manualmente (Desde Estado `validated`)

Si los datos ya están cargados y solo quieres ejecutar el procesamiento SQL:

```php
// Cambiar estado a validated
$run = \App\Models\CollectionNoticeRun::find(1);
$run->update(['status' => 'validated']);

// Despachar job de procesamiento
\App\Jobs\ProcessCollectionDataJob::dispatch($run->id);
```

## Notas Importantes

1. **NO ejecutar** `LoadCsvDataSourcesJob` ni `LoadExcelWithCopyJob` si los datos ya están cargados
2. El job `ProcessCollectionDataJob` **requiere** que el run esté en estado `validated`
3. Todos los steps del procesador son operaciones SQL puras (no cargan archivos)
4. El procesamiento completo se ejecuta dentro de una transacción DB

---

## Problema Actual: Validación de Columnas (2025-10-06)

### Contexto del Problema

El `ValidateDataIntegrityStep` está fallando porque hay inconsistencias entre:
1. Los nombres de columnas parametrizados en `notice_data_source_columns`
2. Los nombres reales de columnas en las tablas físicas de staging

### Normalización de Nombres de Columnas

**Cómo se crean las tablas** (`LoadExcelWithCopyJob`):
- Lee columnas directamente de `information_schema.columns` (línea 239)
- Las columnas en las tablas físicas siguen esta normalización:
  - `"Fec. Reca"` → `fec_reca` (puntos convertidos a underscores)
  - `"T.Doc"` → `t_doc` (puntos convertidos a underscores)
  - `"Aportes"` → `aportes` (solo minúsculas)

**Cómo estaba validando** (`ValidateDataIntegrityStep` - ANTES del fix):
- Tomaba columnas de `notice_data_source_columns`
- Aplicaba: `strtolower()` + `str_replace(' ', '_')`
- Resultado:
  - `"Fec. Reca"` → `fec._reca` ❌ (deja el punto)
  - `"T.Doc"` → `t.doc` ❌ (deja el punto)

**Fix aplicado** (líneas 163-172):
```php
$normalized = strtolower($col);
$normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
$normalized = trim($normalized, '_');
```

### Errores Específicos Encontrados

#### 1. PAGAPL - Columnas con puntos en metadatos
**Metadatos**: `Fec. Reca`, `T.Doc`
**Tabla real**: `fec_reca`, `t_doc`
**Estado**: ✅ RESUELTO con la normalización mejorada

#### 2. DETTRA - Columna inexistente en tabla
**Metadato**: `ACTIVIDAD_EMPRESA` (índice 2 en `notice_data_source_columns`)
**Tabla real**: NO EXISTE (ver migración `2025_10_05_005943`)
**Estado**: ⚠️ PENDIENTE DE CONFIRMAR CON USUARIO

**Columnas reales en `data_source_dettra`** (39 columnas):
```
acti_ries, cpos_ries, key, cod_ries, num_poli, nit, tipo_doc, tipo_cotizante,
fecha_ini_cobert, estado, riesgo, sexo, fech_nacim, desc_ries, dire_ries,
clas_ries, acti_desc, cod_dpto_trabajador, cod_ciudad_trabajador, dpto_trabajador,
ciudad_trabajador, bean, nro_documto, cpos_benef, nom_benef, estado_empresa,
salario, rango_salario, edad, rango_edad, cod_dpto_empresa, cod_ciudad_empresa,
dpto_empresa, ciudad_empresa, ciiu, grupo_actual, grupo_actual_cod,
sector_fasecolda, col_empty
```

**Columnas parametrizadas para DETTRA** (39 columnas, con `ACTIVIDAD_EMPRESA` en posición 2):
```sql
SELECT column_name FROM notice_data_source_columns
WHERE notice_data_source_id = (SELECT id FROM notice_data_sources WHERE code = 'DETTRA')
ORDER BY id;
```

### Opciones de Solución

#### Opción 1: Eliminar del metadato
Si `ACTIVIDAD_EMPRESA` no existe en los archivos fuente DETTRA:
```sql
DELETE FROM notice_data_source_columns
WHERE notice_data_source_id = (SELECT id FROM notice_data_sources WHERE code = 'DETTRA')
  AND column_name = 'ACTIVIDAD_EMPRESA';
```

#### Opción 2: Agregar a la tabla
Si `ACTIVIDAD_EMPRESA` debe existir pero se olvidó crear:
```php
// Crear migración para agregar columna
Schema::table('data_source_dettra', function (Blueprint $table) {
    $table->text('actividad_empresa')->nullable()->after('acti_ries');
});
```

#### Opción 3: Validador flexible
Hacer que `ValidateDataIntegrityStep` solo reporte warnings por columnas faltantes, sin fallar el procesamiento.

### Estado Actual
- ✅ Fix de normalización aplicado en `ValidateDataIntegrityStep.php` (líneas 163-172)
- ⏳ Esperando confirmación del usuario sobre `ACTIVIDAD_EMPRESA` en DETTRA

### Archivos Modificados
- `app/UseCases/Recaudo/Comunicados/Steps/ValidateDataIntegrityStep.php`
- `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php` (se restauró `FilterDataByPeriodStep`)
