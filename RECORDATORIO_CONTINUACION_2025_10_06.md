# 🔴 RECORDATORIO: Continuar aquí mañana
**Fecha**: 2025-10-06
**Estado**: 🚧 TRABAJO EN PROGRESO - NO COMPLETADO

---

## 📍 Punto de Parada

Estábamos trabajando en los **steps del procesador de comunicados de mora**.

### ✅ Steps Completados y Actualizados:

1. ✅ **ValidateDataIntegrityStep** - Validar que los data sources estén cargados
2. ✅ **FilterDataByPeriodStep** - Filtrar datos por periodo (DETTRA, PAGAPL)
3. ✅ **GenerateBascarCompositeKeyStep** - Generar composite_key en BASCAR
4. ✅ **GeneratePagaplCompositeKeyStep** - Generar composite_key en PAGAPL
5. ✅ **CrossBascarWithPagaplStep** - Cruzar BASCAR con PAGAPL (sin tabla temporal)
6. ✅ **RemoveCrossedBascarRecordsStep** - Eliminar de BASCAR los que cruzaron
7. 🚧 **IdentifyPsiStep** - Identificar PSI (INCOMPLETO - ver TODO abajo)
8. ⏸️  **CountDettraWorkersAndUpdateBascarStep** - NO ACTUALIZADO (pendiente)

**Cambios aplicados a todos los steps actualizados**:
- Nueva firma: `execute(CollectionNoticeRun $run): void`
- Eliminado `ProcessingContextDto` y `shouldExecute()`
- 100% SQL - no carga datos en memoria
- Validación de idempotencia en creación de columnas
- Filtrado estricto por `run_id` en todas las queries

---

## 🔴 PROBLEMA BLOQUEANTE: IdentifyPsiStep

**Archivo**: `app/UseCases/Recaudo/Comunicados/Steps/IdentifyPsiStep.php`

### ❌ Problema

Los nombres de columnas usados en el cruce **NO CORRESPONDEN** con las columnas reales:

```php
// Código actual (PUEDE ESTAR INCORRECTO):
UPDATE data_source_bascar b
SET psi = baprpo.pol_independiente
FROM data_source_baprpo baprpo
WHERE b.nit = baprpo.nit  // ← ¿'nit' existe en BASCAR?
  AND baprpo.pol_independiente IS NOT NULL  // ← ¿'pol_independiente' o 'POL_INDEPENDIENTE'?
```

### ⚠️  Columnas a Validar con Cliente

**BASCAR (data_source_bascar)**:
- ❓ ¿Existe columna `nit`?
- ❓ ¿O es `NIT` (mayúsculas)?
- ❓ ¿O es `num_tomador`?
- ❓ ¿O es `numero_identificacion`?

**BAPRPO (data_source_baprpo)**:
- ❓ ¿Existe columna `nit` (minúsculas)?
- ❓ ¿O es `NIT` (mayúsculas)?
- ❓ ¿Existe columna `pol_independiente`?
- ❓ ¿O es `POL_INDEPENDIENTE` (mayúsculas)?

### 📋 Acción Requerida MAÑANA

1. **Verificar estructura real de las tablas**:
   ```sql
   -- Ver columnas de BASCAR
   SELECT column_name, data_type
   FROM information_schema.columns
   WHERE table_name = 'data_source_bascar'
   ORDER BY ordinal_position;

   -- Ver columnas de BAPRPO
   SELECT column_name, data_type
   FROM information_schema.columns
   WHERE table_name = 'data_source_baprpo'
   ORDER BY ordinal_position;
   ```

2. **Actualizar `IdentifyPsiStep.php`** con los nombres correctos

3. **Crear índices en las columnas correctas**

---

## ⏸️  Step Pendiente de Actualizar

### CountDettraWorkersAndUpdateBascarStep

**Archivo**: `app/UseCases/Recaudo/Comunicados/Steps/CountDettraWorkersAndUpdateBascarStep.php`

**Estado**: Aún usa `ProcessingContextDto` (versión antigua)

**Pendiente**:
- Actualizar a nueva firma `execute(CollectionNoticeRun $run): void`
- Eliminar `ProcessingContextDto` y `shouldExecute()`
- Verificar nombres de columnas:
  - ✅ `data_source_dettra.nro_documento` (confirmar)
  - ✅ `data_source_dettra.nit` (confirmar)
  - ✅ `data_source_bascar.num_tomador` (confirmar)
  - ❓ `data_source_bascar.cantidad_trabajadores` (¿existe?)
  - ❓ `data_source_bascar.observacion_trabajadores` (¿existe?)

---

## 📊 Inventario de Columnas Nuevas Creadas

### Columnas que se crean dinámicamente en los steps:

1. **`data_source_dettra.periodo`**
   - Creada en: `FilterDataByPeriodStep`
   - Tipo: `VARCHAR(6)`
   - Propósito: Periodo extraído de FECHA_INICIO_VIG (YYYYMM)

2. **`data_source_bascar.composite_key`**
   - Creada en: `GenerateBascarCompositeKeyStep`
   - Tipo: `VARCHAR(255)`
   - Índice: `idx_data_source_bascar_composite_key`
   - Propósito: `TRIM(num_tomador) || periodo`

3. **`data_source_pagapl.composite_key`**
   - Creada en: `GeneratePagaplCompositeKeyStep`
   - Tipo: `VARCHAR(255)`
   - Índice: `idx_data_source_pagapl_composite_key`
   - Propósito: `TRIM(identificacion) || periodo`

4. **`data_source_bascar.psi`**
   - Creada en: `IdentifyPsiStep`
   - Tipo: `VARCHAR(10)`
   - Índice: `idx_data_source_bascar_psi`
   - Propósito: Póliza de Seguro Independiente (desde BAPRPO)

5. **Índices adicionales**:
   - `idx_data_source_bascar_nit` (creado en `IdentifyPsiStep`)
   - `idx_data_source_baprpo_nit` (creado en `IdentifyPsiStep`)

### ⚠️  Problema de Idempotencia

**Situación actual**:
- Las columnas se crean dinámicamente en los steps de procesamiento
- SI la columna ya existe de un run anterior, los jobs de carga NO la poblarán
- Esto puede causar inconsistencias

**Solución pendiente** (acordado con el usuario):
1. **Terminar todos los steps primero**
2. **Luego inventariar todas las columnas necesarias**
3. **Crear migraciones permanentes** para que las columnas existan desde el inicio
4. **Ajustar jobs de carga** (LoadCsvDataSourcesJob, LoadExcelWithCopyJob) para poblar estas columnas

---

## 🎯 Plan para Mañana

### Orden de ejecución:

1. **PRIMERO**: Validar nombres de columnas con cliente
   - Consultar estructura de `data_source_bascar`
   - Consultar estructura de `data_source_baprpo`
   - Consultar estructura de `data_source_dettra`

2. **SEGUNDO**: Actualizar `IdentifyPsiStep` con nombres correctos

3. **TERCERO**: Actualizar `CountDettraWorkersAndUpdateBascarStep`
   - Nueva firma
   - Validar nombres de columnas
   - Crear columnas si no existen (idempotencia)

4. **CUARTO**: Inventario completo de columnas
   - Listar todas las columnas nuevas necesarias
   - Decidir cuáles van en migraciones permanentes

5. **QUINTO**: Crear migraciones
   - Una migración por tabla
   - Agregar todas las columnas necesarias
   - Crear todos los índices necesarios

6. **SEXTO**: Ajustar jobs de carga
   - `LoadCsvDataSourcesJob`: Verificar que no falle con columnas nuevas
   - `LoadExcelWithCopyJob`: Verificar que no falle con columnas nuevas

7. **SÉPTIMO**: Pruebas
   - Ejecutar pipeline completo con run 2
   - Verificar que todos los steps funcionen

---

## 📝 Notas Importantes

### Sobre el Processor

**Archivo**: `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php`

**Steps actuales** (en orden):
```php
1. $this->validateDataStep              // ✅ Actualizado
2. $this->filterDataByPeriodStep        // ✅ Actualizado
3. $this->generateBascarKeysStep        // ✅ Actualizado
4. $this->generatePagaplKeysStep        // ✅ Actualizado
5. $this->crossBascarPagaplStep         // ✅ Actualizado (sin tabla temporal)
6. $this->removeCrossedBascarStep       // ✅ Actualizado
7. $this->identifyPsiStep               // 🚧 INCOMPLETO (nombres de columnas)
8. $this->countDettraWorkersStep        // ⏸️  NO ACTUALIZADO
```

### Archivos Modificados Hoy

1. ✅ `app/UseCases/Recaudo/Comunicados/Steps/FilterDataByPeriodStep.php` (creado)
2. ✅ `app/UseCases/Recaudo/Comunicados/Steps/GenerateBascarCompositeKeyStep.php` (actualizado)
3. ✅ `app/UseCases/Recaudo/Comunicados/Steps/GeneratePagaplCompositeKeyStep.php` (actualizado)
4. ✅ `app/UseCases/Recaudo/Comunicados/Steps/CrossBascarWithPagaplStep.php` (actualizado)
5. ✅ `app/UseCases/Recaudo/Comunicados/Steps/RemoveCrossedBascarRecordsStep.php` (actualizado)
6. 🚧 `app/UseCases/Recaudo/Comunicados/Steps/IdentifyPsiStep.php` (creado - INCOMPLETO)
7. ✅ `app/UseCases/Recaudo/Comunicados/Processors/ConstitucionMoraAportantesProcessor.php` (actualizado)

### Archivos de Documentación

1. ✅ `EXCEL_SHEET_NAMES_FIX_FINAL_2025_10_06.md` - Fix de nombres de hojas Excel
2. ✅ `RECORDATORIO_CONTINUACION_2025_10_06.md` - Este archivo

---

## 🔍 Comandos Útiles para Mañana

```bash
# Ver estructura de BASCAR
docker-compose exec poarl-php php artisan tinker --execute="
\$columns = DB::select('SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position', ['data_source_bascar']);
foreach (\$columns as \$col) {
    echo \$col->column_name . ' (' . \$col->data_type . ')' . PHP_EOL;
}
"

# Ver estructura de BAPRPO
docker-compose exec poarl-php php artisan tinker --execute="
\$columns = DB::select('SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position', ['data_source_baprpo']);
foreach (\$columns as \$col) {
    echo \$col->column_name . ' (' . \$col->data_type . ')' . PHP_EOL;
}
"

# Ver estructura de DETTRA
docker-compose exec poarl-php php artisan tinker --execute="
\$columns = DB::select('SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position', ['data_source_dettra']);
foreach (\$columns as \$col) {
    echo \$col->column_name . ' (' . \$col->data_type . ')' . PHP_EOL;
}
"
```

---

**🔴 RECORDATORIO CLAVE**: El step `IdentifyPsiStep` tiene nombres de columnas INCORRECTOS. DEBE validarse con el cliente antes de continuar.
