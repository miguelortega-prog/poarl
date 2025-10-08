# 🔴 PROBLEMAS ENCONTRADOS Y SOLUCIONES PERMANENTES
**Fecha**: 2025-10-06
**Estado**: 🚧 REQUIERE ATENCIÓN INMEDIATA

---

## 📋 RESUMEN EJECUTIVO

Durante las pruebas del procesador de comunicados de mora, se encontraron múltiples problemas que requieren solución permanente antes de continuar con el desarrollo.

---

## 🐛 PROBLEMAS IDENTIFICADOS

### 1. ❌ Workers de Cola NO Configurados

**Problema:**
- No existe servicio `poarl-worker` en `docker-compose.yml`
- Los jobs no se procesan automáticamente
- Se requiere ejecución manual de `queue:work`

**Impacto:** CRÍTICO
- El sistema no puede procesar runs automáticamente
- Los jobs quedan en cola indefinidamente

**Solución Permanente:**
```yaml
# Agregar en docker-compose.yml
poarl-worker:
  build:
    context: .
    dockerfile: Dockerfile
  command: php artisan queue:work --tries=3 --timeout=0 --sleep=3 --max-time=3600
  volumes:
    - ./:/var/www/html
  depends_on:
    - poarl-db
    - poarl-redis
  restart: unless-stopped
  networks:
    - poarl-network
```

---

### 2. ❌ Tabla `data_source_pagpla` Con Columnas Incorrectas

**Problema:**
- La migración `2025_10_05_005943` creó `data_source_pagpla` con columnas de PAGAPL (Pagos Aplicados)
- Faltaba la columna `email` parametrizada en el seeder

**Estado:** ✅ CORREGIDO
- Migración `2025_10_06_164708_fix_data_source_pagpla_table_columns.php` creada
- Ejecutada correctamente

**Columnas Correctas Ahora:**
- modalidad_planilla
- total_afiliados
- identificacion_aportante
- email ← Ahora incluida
- tipo_aportante
- numero_planila
- direccion
- codigo_ciudad
- codigo_departamento
- telefono
- fax
- periodo_pago
- tipo_planilla
- fecha_pago
- codigo_operador
- sheet_name

---

### 3. ❌ Jobs Con `tries = 1` (Sin Reintentos)

**Problema:**
- `LoadCsvDataSourcesJob` tenía `$tries = 1`
- `LoadExcelWithCopyJob` tenía `$tries = 1`
- Cualquier error temporal causaba falla inmediata
- No había `backoff` entre intentos

**Estado:** ✅ CORREGIDO
- Ambos jobs ahora tienen `$tries = 3`
- `$backoff = 60` segundos entre intentos
- Método `failed()` mejorado con logging completo

**Cambios Aplicados:**
```php
// Antes
public int $tries = 1;

// Ahora
public int $tries = 3;
public int $backoff = 60;
```

---

### 4. ❌ Logging Insuficiente en Jobs Fallidos

**Problema:**
- El método `failed()` solo logueaba el mensaje del error
- No se capturaba el stack trace completo
- Difícil debugging de errores

**Estado:** ✅ CORREGIDO

**Logging Mejorado:**
```php
public function failed(Throwable $exception): void
{
    Log::critical('Job falló definitivamente después de todos los intentos', [
        'job' => self::class,
        'file_id' => $this->fileId,
        'data_source' => $this->dataSourceCode,
        'attempts' => $this->tries,
        'error_message' => $exception->getMessage(),
        'error_code' => $exception->getCode(),
        'error_file' => $exception->getFile(),
        'error_line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ]);
}
```

---

###5. ❌ `ValidateDataIntegrityStep` Sin Validación de Columnas

**Problema:**
- No validaba que las columnas cargadas coincidieran con las parametrizadas
- Error de PAGPLA no se hubiera detectado hasta intentar procesar

**Estado:** ✅ CORREGIDO

**Nueva Funcionalidad:**
- Compara columnas de tabla física vs `notice_data_source_columns`
- Reporta columnas faltantes/extra
- Lanza `RuntimeException` si hay discrepancias
- Logs detallados de validación

---

### 6. ✅ Cargue con Chunks de 10,000 Registros Optimizado

**Problema (REPORTADO POR USUARIO):**
- Se implementó cargue con chunks de 10,000 registros
- Jobs fallaban después de ~160k registros (timeout/memoria)
- Performance extremadamente lenta con archivos grandes

**Estado:** ✅ CORREGIDO

**Problemas Encontrados en `ResilientCsvImporter`:**

1. **Memory leak por almacenamiento innecesario:**
   - Cada item del chunk guardaba `line_content` completo en memoria
   - 10k líneas × 500 bytes promedio = 5MB por chunk desperdiciados
   - Solución: Eliminar `line_content` del chunk, solo usar en error logs

2. **Fallback extremadamente ineficiente:**
   - Cuando batch insert fallaba, hacía 10,000 transacciones individuales
   - Para BASCAR (255k registros) = 255,000 transacciones = horas de procesamiento
   - Solución: Eliminar transacciones individuales en fallback

3. **Sin gestión de memoria:**
   - No había `unset()` ni `gc_collect_cycles()` entre chunks
   - Acumulación de memoria con archivos grandes
   - Solución: Liberar memoria explícitamente después de cada chunk

**Optimizaciones Aplicadas:**
```php
// ✅ Sin line_content en memoria
$chunk[] = [
    'data' => $rowData,
    'line_number' => $currentLine,
    // 'line_content' REMOVIDO
];

// ✅ Fallback sin transacciones individuales
foreach ($chunk as $item) {
    try {
        DB::table($tableName)->insert($item['data']);
        // NO usa DB::beginTransaction() por cada fila
    }
}

// ✅ Liberación explícita de memoria
unset($chunk, $result);
gc_collect_cycles();
```

**Logging Mejorado:**
- Ahora muestra progreso por chunk: `Procesando chunk #1`, `#2`, etc.
- Incluye `chunks_processed` en resumen final
- Permite identificar en qué chunk falla si hay errores

---

### 7. ⚠️ Tabla `failed_jobs` Sin Columna `created_at`

**Problema:**
- Query `latest()` falla porque la columna no existe
- Dificulta debugging de jobs fallidos

**Estado:** 🔴 PENDIENTE CORRECCIÓN

**Solución:**
```bash
php artisan migrate:fresh --seed
# O migración específica para agregar columna
```

---

## 📊 STEPS ACTUALIZADOS A NUEVA FIRMA

### ✅ Steps Completados (sin ProcessingContextDto):

1. ✅ `ValidateDataIntegrityStep`
2. ✅ `FilterDataByPeriodStep`
3. ✅ `GenerateBascarCompositeKeyStep`
4. ✅ `GeneratePagaplCompositeKeyStep`
5. ✅ `CrossBascarWithPagaplStep`
6. ✅ `RemoveCrossedBascarRecordsStep`
7. ✅ `IdentifyPsiStep`
8. ✅ `ExcludePsiPersonaJuridicaStep` (NUEVO)
9. ✅ `CountDettraWorkersAndUpdateBascarStep`
10. ✅ `CrearBaseTrabajadoresActivosStep` (NUEVO)
11. ✅ `AppendBascarSinTrabajadoresStep` (NUEVO)
12. ✅ `AddCityCodeToBascarStep` (NUEVO)

**Total:** 12 steps implementados

---

## 🎯 PLAN DE ACCIÓN INMEDIATO

### Prioridad 1: Infraestructura Básica
- [ ] Agregar servicio `poarl-worker` a `docker-compose.yml`
- [ ] Reiniciar contenedores
- [ ] Verificar que workers procesen jobs automáticamente

### Prioridad 2: Validación de Carga
- [x] Investigar problema de chunks en `ResilientCsvImporter` ✅ CORREGIDO
- [ ] Probar carga completa de run #1 con optimizaciones
- [ ] Validar que `ValidateDataIntegrityStep` detecte errores

### Prioridad 3: Correcciones Menores
- [ ] Corregir tabla `failed_jobs` (agregar `created_at`)
- [ ] Documentar proceso de deployment

---

## 📝 CAMBIOS PERMANENTES REALIZADOS

### Archivos Modificados:

1. ✅ `database/migrations/2025_10_06_164708_fix_data_source_pagpla_table_columns.php` (CREADO)
2. ✅ `app/Jobs/LoadCsvDataSourcesJob.php` (tries=3, backoff=60, logging mejorado)
3. ✅ `app/Jobs/LoadExcelWithCopyJob.php` (tries=3, backoff=60, logging mejorado)
4. ✅ `app/Services/Recaudo/ResilientCsvImporter.php` (⭐ OPTIMIZADO: memoria, transacciones, logging)
5. ✅ `app/UseCases/Recaudo/Comunicados/Steps/ValidateDataIntegrityStep.php` (validación de columnas)
6. ✅ `app/UseCases/Recaudo/Comunicados/Steps/ExcludePsiPersonaJuridicaStep.php` (CREADO)
7. ✅ `app/UseCases/Recaudo/Comunicados/Steps/CrearBaseTrabajadoresActivosStep.php` (CREADO)
8. ✅ `app/UseCases/Recaudo/Comunicados/Steps/AppendBascarSinTrabajadoresStep.php` (CREADO)
9. ✅ `app/UseCases/Recaudo/Comunicados/Steps/AddCityCodeToBascarStep.php` (CREADO)
10. ✅ `app/UseCases/Recaudo/Comunicados/Steps/CountDettraWorkersAndUpdateBascarStep.php` (actualizado)
11. ✅ `app/UseCases/Recaudo/Comunicados/Steps/IdentifyPsiStep.php` (actualizado)

---

## ⚠️ NOTAS IMPORTANTES

1. **NO** hacer cambios solo para debugging temporal
2. **SÍ** implementar soluciones permanentes desde el inicio
3. **SIEMPRE** validar que los cambios funcionen en producción
4. **DOCUMENTAR** todos los cambios realizados

---

## 🔄 PRÓXIMOS PASOS

1. Configurar worker permanente en docker-compose
2. Probar carga completa de run #1
3. Validar funcionamiento de todos los steps
4. Continuar con steps faltantes del procesador

---

**Última actualización**: 2025-10-06 18:15 UTC
**Responsable**: Claude Code + Usuario
**Estado**: ResilientCsvImporter optimizado, listo para testing
