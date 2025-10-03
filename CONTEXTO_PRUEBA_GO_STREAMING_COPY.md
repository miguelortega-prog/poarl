# Contexto: Prueba Completa Excel→CSV (Go Streaming) + PostgreSQL COPY

**Fecha:** 2025-10-03
**Branch:** `feat/implements_job_for_procesing_data_sources`

## 🎯 Objetivo

Probar el flujo completo de procesamiento de datos optimizado:
1. **Conversión Excel→CSV** usando binario Go con streaming XML (sin cargar todo en memoria)
2. **Importación CSV→PostgreSQL** usando comando COPY nativo (10-50x más rápido que chunks)

## ✅ Implementación Completada

### 1. Binario Go con Streaming XML
- **Archivo:** `/home/migleor/poarl/poarl-backend/bin/src/excel_streaming.go`
- **Binario compilado:** `/usr/local/bin/excel_streaming` (en contenedor `poarl-php`)
- **Características:**
  - Parser streaming que lee XML token por token
  - No carga todo el Excel en memoria
  - Procesa archivos de 190MB+ sin problemas
  - Agrega columna `sheet_name` automáticamente

- **Performance probada:**
  - Archivo: 191MB (2.6M filas, 4 hojas)
  - Tiempo: **5 minutos** (~306 segundos)
  - Velocidad: ~8,500 filas/segundo
  - CSVs generados: 290MB total

### 2. PostgreSQL COPY Importer
- **Archivo:** `app/Services/Recaudo/PostgreSQLCopyImporter.php`
- **Método:** Usa `psql` CLI con comando `COPY FROM STDIN`
- **Performance probada:**
  - 3 filas de prueba en **64ms**
  - Estimado: 10-50x más rápido que inserts por chunks

### 3. Servicios y Clases

#### GoExcelConverter.php
```php
\App\Services\Recaudo\GoExcelConverter::convertAllSheetsToSeparateCSVs(
    Filesystem $disk,
    string $excelPath,
    string $outputDir,
    string $delimiter = ';'
): array
```
- Ejecuta binario Go `/usr/local/bin/excel_to_csv`
- Retorna info de hojas procesadas (nombre, rows, size, duration)

#### PostgreSQLCopyImporter.php
```php
\App\Services\Recaudo\PostgreSQLCopyImporter::importFromFile(
    string $tableName,
    string $csvPath,
    array $columns,
    string $delimiter = ';',
    bool $hasHeader = true
): array
```
- Usa `psql` CLI para ejecutar COPY
- Retorna `['rows' => int, 'duration_ms' => int]`

### 4. Migración Sheet Name
- **Archivo:** `database/migrations/2025_10_03_000000_add_sheet_name_to_data_source_tables.php`
- Agrega columna `sheet_name VARCHAR(255)` a todas las tablas de data sources
- Necesario para identificar de qué hoja viene cada registro

## 📋 Preparación para Prueba Completa

### Pre-requisitos en contenedor PHP:
1. ✅ Go 1.21+ instalado en `/usr/local/go/bin/go`
2. ✅ Binario compilado en `/usr/local/bin/excel_streaming`
3. ✅ `postgresql-client` instalado (para comando `psql`)

### Comandos de instalación (ya ejecutados):
```bash
# Go
curl -OL https://go.dev/dl/go1.21.6.linux-amd64.tar.gz
tar -C /usr/local -xzf go1.21.6.linux-amd64.tar.gz

# PostgreSQL client
apt-get update && apt-get install -y postgresql-client

# Compilar binario
cd /var/www/html/bin
export PATH=$PATH:/usr/local/go/bin
go build -o excel_streaming src/excel_streaming.go
cp excel_streaming /usr/local/bin/
```

## 🧪 Pasos para la Prueba

### 1. Limpiar Base de Datos
```bash
docker-compose exec poarl-php php artisan tinker --execute="
\DB::table('data_source_bascar')->truncate();
\DB::table('data_source_pagapl')->truncate();
\DB::table('data_source_baprpo')->truncate();
\DB::table('data_source_pagpla')->truncate();
\DB::table('data_source_datpol')->truncate();
\DB::table('data_source_dettra')->truncate();
echo 'Tablas truncadas';
"
```

### 2. Ejecutar Migración Sheet Name
```bash
docker-compose exec poarl-php php artisan migrate
```

### 3. Crear Run de Prueba
- Ir a interfaz web: http://localhost:8080/recaudo/comunicados
- Subir archivos de prueba
- Anotar el `run_id` generado

### 4. Probar Conversión Excel→CSV con Go
```bash
docker-compose exec poarl-php bash -c "
/usr/local/bin/excel_streaming \
  --input storage/app/collection/[RUTA_EXCEL] \
  --output storage/app/collection/temp/test_go \
  --delimiter ';'
"
```

### 5. Probar Importación con COPY
```bash
docker-compose exec poarl-php php artisan tinker --execute="
\$importer = new \App\Services\Recaudo\PostgreSQLCopyImporter();
\$result = \$importer->importFromFile(
    'data_source_pagapl',
    '/var/www/html/storage/app/collection/temp/test_go/sheet1.csv',
    ['run_id', 'identificacion', 'periodo', 'valor', 'composite_key', 'data', 'sheet_name'],
    ';',
    true
);
print_r(\$result);
"
```

## 📊 Data Sources y Mapeo

### PAGAPL (Pagos Aplicados)
**Columnas tabla:** `run_id, identificacion, periodo, valor, composite_key, data, sheet_name`

**Mapeo CSV→Tabla:**
- `Identificacion` → `identificacion`
- `Periodo` → `periodo`
- `Valor` → `valor`
- Todo el row → `data` (JSON)
- Nombre de hoja → `sheet_name`

### DETTRA (Detalle Trabajadores)
**Columnas tabla:** `run_id, num_trabajador, total_trabajadores, composite_key, data, sheet_name`

**Nota:** Requiere procesamiento especial (conteo de trabajadores por poliza)

### CSV/Directos (BASCAR, BAPRPO, DATPOL)
Ya vienen en CSV, solo necesitan COPY directo a tabla.

## 🚀 Jobs Pendientes de Integrar

Los siguientes jobs están creados pero NO integrados aún:

1. `LoadCsvDataSourcesJob.php` - Usa PostgreSQLCopyImporter ✅
2. `LoadPagaplDataSourceJob.php` - Necesita integrar Go + COPY
3. `LoadPagplaDataSourceJob.php` - Necesita integrar Go + COPY
4. `LoadDettraDataSourceJob.php` - Necesita integrar Go + COPY
5. `LoadExcelWithCopyJob.php` - Job genérico Go + COPY
6. `ProcessCollectionDataJob.php` - Orquestador principal

## 🔧 Próximos Pasos (después del commit)

1. **Integrar GoExcelConverter en jobs existentes**
   - Modificar `LoadPagaplSheetByPeriodStep.php` para usar Go en lugar de OpenSpout
   - Modificar otros steps de carga de Excel

2. **Crear pipeline completo:**
   ```
   Subir archivos → Convertir Excel (Go) → Importar CSV (COPY) → Procesar datos
   ```

3. **Optimizar mapeo de columnas:**
   - Crear transformador que mapee CSVs de Go al formato de cada tabla
   - Considerar agregar columnas necesarias a los CSVs desde Go

4. **Métricas y logging:**
   - Agregar telemetría de performance
   - Comparar tiempos: OpenSpout+Chunks vs Go+COPY

## 📝 Notas Importantes

- **Archivos binarios NO se commitean:** Los `.go` sí, los binarios compilados NO
- **El binario debe recompilarse en cada deploy** del contenedor
- **Verificar que Go y psql estén disponibles** en el Dockerfile de producción
- **La columna `sheet_name` es clave** para identificar origen de datos en tablas multi-hoja

## 🐛 Issues Conocidos Resueltos

1. ~~Excelize carga todo en memoria~~ → **Solucionado** con streaming XML
2. ~~pg_put_line no funciona con PDO~~ → **Solucionado** usando `psql` CLI
3. ~~Archivos grandes (190MB+) timeout~~ → **Solucionado** con streaming

## ✅ Checklist Pre-Prueba

- [x] Binario Go compilado en contenedor
- [x] postgresql-client instalado
- [x] PostgreSQLCopyImporter funcional
- [x] Migración sheet_name creada
- [ ] Migración sheet_name ejecutada
- [ ] Base de datos limpia
- [ ] Run de prueba creado
- [ ] Flujo completo probado

---

**¡Esta debería ser la vencida!** 🎉
