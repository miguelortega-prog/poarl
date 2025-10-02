# Contexto de Sesión - Procesamiento Run #3

**Fecha**: 2025-10-02
**Hora de pausa**: ~10:45
**Estado**: Procesamiento en curso con configuración correcta

## 🎯 Objetivo Principal

Implementar y optimizar el procesamiento de archivos grandes (CSV y Excel) para collection notice runs, específicamente el run #3 con ~878 MB de datos.

## ✅ Problemas Resueltos

### 1. Error de Formato Numérico Colombiano
- **Problema**: PostgreSQL rechazaba números con formato colombiano "1.296.926"
- **Solución**: Normalización en `DataSourceTableManager.php:prepareBascarData()`
  - Elimina puntos (separador de miles)
  - Convierte comas a puntos (decimales)
- **Archivo**: `/home/migleor/poarl/poarl-backend/app/Services/Recaudo/DataSourceTableManager.php`

### 2. Memory Exhausted con Archivos Excel
- **Problema Original**: PhpSpreadsheet agotaba memoria (1GB) al procesar Excel de 190-289 MB
- **Causa Raíz**: `ProcessCollectionNoticeRunData.php` tenía hardcoded `ini_set('memory_limit', '1024M')`
- **Solución Implementada**:
  1. **PHP global** (`/usr/local/etc/php/conf.d/uploads.ini`): 512M → 4096M
  2. **Job específico** (`ProcessCollectionNoticeRunData.php:66`): 1024M → 4096M

### 3. Optimización de Carga CSV
- **Problema**: Carga de archivos grandes consumía mucha memoria
- **Solución**: Insertado incremental en chunks de 5000 filas en `LoadDataSourceFilesStep.php`
- **Resultado**: 540,173 filas cargadas exitosamente sin problemas de memoria

## 📁 Archivos Modificados

### 1. `/home/migleor/poarl/poarl-backend/app/Services/Recaudo/DataSourceTableManager.php`
**Líneas 106-143**: Método `prepareBascarData()`
```php
// Normalizar valor_total_fact (formato colombiano)
$valorTotalFact = str_replace('.', '', $valorTotalFact);  // Eliminar puntos
$valorTotalFact = str_replace(',', '.', $valorTotalFact); // Coma a punto decimal
```

### 2. `/home/migleor/poarl/poarl-backend/app/Jobs/ProcessCollectionNoticeRunData.php`
**Línea 66**: Memory limit aumentado
```php
// ANTES: ini_set('memory_limit', '1024M');
// AHORA: ini_set('memory_limit', '4096M');
```

### 3. Docker Container: `/usr/local/etc/php/conf.d/uploads.ini`
**Cambio temporal en contenedor** (se perderá al reiniciar contenedor):
```ini
memory_limit = 4096M  # Era 512M
```

⚠️ **IMPORTANTE**: El cambio en `uploads.ini` está solo en el contenedor activo. Si reinicias el contenedor Docker, deberás aplicarlo nuevamente con:
```bash
docker-compose exec -T poarl-php bash -c "echo '; Configuración para uploads grandes
file_uploads = On
memory_limit = 4096M
upload_max_filesize = 1024M
post_max_size = 512M
max_execution_time = 600
max_input_time = 600' > /usr/local/etc/php/conf.d/uploads.ini"

docker-compose restart poarl-php
```

## 🔄 Estado Actual del Run #3

### Información del Run
- **ID**: 3
- **Tipo**: CONSTITUCIÓN EN MORA - APORTANTES
- **Periodo**: 202508
- **Estado Actual**: processing (en ejecución)
- **Inicio**: ~10:38 (hora local)

### Archivos del Run (6 totales, ~878 MB)
1. **BASCAR** (168 MB CSV) - Base de cartera
2. **PAGAPL** (190 MB Excel) - Pagos aplicados → **PUNTO CRÍTICO**
3. **BAPRPO** (7 MB CSV) - Base productos
4. **PAGPLA** (289 MB Excel) - Pagos planilla (histórico completo)
5. **DATPOL** (20 MB CSV) - Datos pólizas
6. **DETTRA** (202 MB Excel) - Detalle trabajadores (histórico completo)

### Progreso al Pausar
**Último estado conocido (~10:40)**:
- ✅ Paso 1: Cargar archivos CSV → **COMPLETADO** (172s, 540,173 filas)
- ✅ Paso 2: Validar integridad → **COMPLETADO** (50ms)
- ✅ Paso 3: Filtrar BASCAR por periodo → **COMPLETADO** (23s)
- ⏳ Paso 4: Cargar Excel PAGAPL → **EN PROGRESO**

**El procesamiento estaba cargando el archivo Excel PAGAPL (190 MB) cuando pausaste.**

## 🔍 Cómo Verificar el Estado al Retomar

### 1. Verificar si el Procesamiento Completó
```bash
docker-compose exec -T poarl-php php artisan tinker --execute="
\$run = \App\Models\CollectionNoticeRun::find(3);
echo '=== RUN #3 ===' . PHP_EOL;
echo 'Estado: ' . \$run->status . PHP_EOL;
echo 'Duración: ' . (\$run->duration_ms ?? 'N/A') . 'ms' . PHP_EOL;

if (\$run->errors) {
    echo PHP_EOL . '=== ERRORES ===' . PHP_EOL;
    print_r(\$run->errors);
}

if (\$run->results) {
    echo PHP_EOL . '=== PASOS COMPLETADOS ===' . PHP_EOL;
    foreach (\$run->results as \$step => \$result) {
        echo '✅ ' . \$step . PHP_EOL;
    }
}
"
```

### 2. Verificar Datos Cargados en BD
```bash
docker-compose exec -T poarl-php php artisan tinker --execute="
echo '=== DATOS CARGADOS EN BD ===' . PHP_EOL;
echo 'BASCAR: ' . DB::table('data_source_bascar')->where('run_id', 3)->count() . ' registros' . PHP_EOL;
echo 'PAGAPL: ' . DB::table('data_source_pagapl')->where('run_id', 3)->count() . ' registros' . PHP_EOL;
echo 'BAPRPO: ' . DB::table('data_source_baprpo')->where('run_id', 3)->count() . ' registros' . PHP_EOL;
echo 'PAGPLA: ' . DB::table('data_source_pagpla')->where('run_id', 3)->count() . ' registros' . PHP_EOL;
echo 'DATPOL: ' . DB::table('data_source_datpol')->where('run_id', 3)->count() . ' registros' . PHP_EOL;
echo 'DETTRA: ' . DB::table('data_source_dettra')->where('run_id', 3)->count() . ' registros' . PHP_EOL;
"
```

### 3. Ver Últimos Logs
```bash
tail -n 100 storage/logs/laravel.log | grep -E "(run_id.*3|ERROR|completado|Paso completado)"
```

## 📋 Posibles Escenarios al Retomar

### Escenario 1: ✅ Procesamiento Completó Exitosamente
**Indicadores**:
- Estado: `completed` o `validated`
- `duration_ms` tiene valor
- Todos los pasos en `results`
- Sin errores

**Siguiente paso**: Analizar resultados y verificar datos procesados

### Escenario 2: ❌ Procesamiento Falló
**Indicadores**:
- Estado: `failed` o `processing`
- Campo `errors` tiene contenido
- Logs muestran "ERROR" o "memory exhausted"

**Siguiente paso**:
1. Verificar si fue por memoria (improbable con 4GB)
2. Revisar error específico en `$run->errors`
3. Resetear run y reintentar

### Escenario 3: ⏸️ Procesamiento Interrumpido
**Indicadores**:
- Estado: `processing`
- Sin `duration_ms`
- Algunos pasos completados, otros no

**Siguiente paso**:
1. Resetear run a `validated`
2. Limpiar tablas de datos
3. Re-ejecutar procesamiento

## 🔧 Comandos de Recuperación

### Si Necesitas Resetear el Run #3
```bash
docker-compose exec -T poarl-php php artisan tinker --execute="
\$run = \App\Models\CollectionNoticeRun::find(3);
\$run->update([
    'status' => 'validated',
    'errors' => null,
    'results' => null,
    'duration_ms' => null,
]);

// Limpiar datos
DB::table('data_source_bascar')->where('run_id', 3)->delete();
DB::table('data_source_pagapl')->where('run_id', 3)->delete();
DB::table('data_source_baprpo')->where('run_id', 3)->delete();
DB::table('data_source_pagpla')->where('run_id', 3)->delete();
DB::table('data_source_datpol')->where('run_id', 3)->delete();
DB::table('data_source_dettra')->where('run_id', 3)->delete();

echo '✅ Run #3 reseteado' . PHP_EOL;
"
```

### Re-ejecutar Procesamiento
```bash
docker-compose exec poarl-php php artisan tinker --execute="
echo '=== PROCESANDO RUN #3 ===' . PHP_EOL;
echo 'Hora inicio: ' . now()->format('H:i:s') . PHP_EOL . PHP_EOL;

\App\Jobs\ProcessCollectionNoticeRunData::dispatchSync(3);

\$run = \App\Models\CollectionNoticeRun::find(3);
echo PHP_EOL . 'Estado final: ' . \$run->status . PHP_EOL;
echo 'Duración: ' . \$run->duration_ms . 'ms' . PHP_EOL;
" &
```

## 🐛 Debugging

### Verificar Memory Limit Activo
```bash
docker-compose exec -T poarl-php php -i | grep memory_limit
# Debe mostrar: memory_limit => 4096M => 4096M
```

### Monitorear Logs en Tiempo Real
```bash
tail -f storage/logs/laravel.log | grep --line-buffered -E "(run_id.*3|Progreso|ERROR|Paso completado)"
```

### Ver Procesos en Background
```bash
docker-compose exec poarl-php ps aux | grep php
```

## 📝 Notas Importantes

### 1. Archivos Excel - Estrategia de Conversión
- **PAGAPL**: Filtrar por periodo (hoja "202508")
- **PAGPLA**: Histórico completo (todas las hojas o primera hoja completa)
- **DETTRA**: Histórico completo (todas las hojas o primera hoja completa)

### 2. Jobs en Cola
El sistema usa queue `collection-notices`:
```bash
# Ver jobs en cola
docker-compose exec -T poarl-php php artisan queue:listen collection-notices --tries=1 --timeout=600
```

### 3. Timeouts
- `max_execution_time`: 600s (10 min)
- Job timeout: 900s (15 min) en `ConvertExcelToCsvJob`
- Queue worker timeout: 600s

## 🎬 Flujo Completo del Procesamiento

```
1. Cargar archivos de insumos (CSV directo, Excel como metadata)
   ├─ BASCAR.csv → BD (255K filas)
   ├─ BAPRPO.csv → BD (216K filas)
   └─ DATPOL.csv → BD (68K filas)

2. Validar integridad de datos
   └─ Verificar que todos los data sources estén presentes

3. Filtrar BASCAR por periodo
   └─ SQL: calcular periodo desde fecha_inicio_vig

4. Cargar PAGAPL Excel (⚠️ PUNTO CRÍTICO - 190MB)
   └─ PhpSpreadsheet con 4GB memoria

5. [Pendiente] Procesar PAGPLA y DETTRA Excel

6. [Pendiente] Ejecutar validaciones y cruces

7. [Pendiente] Generar comunicados
```

## 🔮 Próximos Pasos Después de Completar Run #3

1. **Analizar tiempos de procesamiento**
   - Verificar si 4GB es suficiente para PAGPLA (289MB) y DETTRA (202MB)
   - Evaluar si necesita más optimización

2. **Considerar alternativas a PhpSpreadsheet**
   - Biblioteca `spout` (mejor streaming)
   - Conversión previa de Excel a CSV fuera del proceso

3. **Implementar conversión multi-hoja**
   - Completar TODO en `ConvertExcelToCsvJob.php:137`
   - Cada hoja → CSV separado → tabla BD

4. **Optimizar steps posteriores**
   - LoadPagaplSheetByPeriodStep
   - Validaciones SQL
   - Generación de comunicados

## 📞 Contacto y Soporte

Si tienes dudas al retomar:
1. Verifica primero el estado del run con los comandos de verificación
2. Revisa los logs para entender qué pasó
3. Si falló, identifica el error específico antes de reintentar

**¡Buena suerte al retomar!** 🚀

---
**Creado**: 2025-10-02 10:45
**Por**: Claude Code
**Sesión**: Optimización de procesamiento de archivos grandes
