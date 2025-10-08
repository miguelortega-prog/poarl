# Arquitectura Híbrida: Go + Laravel para Conversión Excel→CSV

**Fecha**: 2025-10-03
**Objetivo**: Optimizar conversión Excel→CSV de 145s a ~17s (8.5x más rápido)

---

## 🎯 Problema Identificado

El cuello de botella REAL está en **Excel→CSV**, NO en el COPY ni en los cruces SQL.

### Archivos del Run #1:
- **BASCAR**: 168.71 MB (CSV) - Ya optimizado
- **PAGAPL**: 190.91 MB (Excel) - **LENTO** con PHP OpenSpout
- **BAPRPO**: 7 MB (CSV) - Ya optimizado
- **PAGPLA**: 289.01 MB (Excel) - **LENTO** con PHP OpenSpout
- **DATPOL**: 20.47 MB (CSV) - Ya optimizado
- **DETTRA**: 202.87 MB (Excel) - **LENTO** con PHP OpenSpout

**Total Excel a convertir**: 681.91 MB

### Performance Actual vs Esperada:

| Herramienta | Velocidad | PAGAPL 190MB | PAGPLA 289MB | DETTRA 202MB | TOTAL |
|-------------|-----------|--------------|--------------|--------------|-------|
| **PHP OpenSpout (actual)** | ~5 MB/s | 40s | 60s | 45s | **145s** |
| **Go excelize (propuesto)** | ~40 MB/s | 5s | 7s | 5s | **17s** |
| **Mejora** | **8x** | **8x** | **8.5x** | **9x** | **8.5x** |

---

## 🏗️ Arquitectura Elegida: Opción A (Go dentro de PHP container)

```
Docker Container: poarl-php
├── PHP 8.3-FPM + Laravel
├── Go binario: /usr/local/bin/excel_to_csv
│   └── Llamado desde Laravel via Symfony Process
├── PostgreSQL COPY (ya optimizado)
└── Cruces SQL (ConstitucionMoraAportantesProcessor)
```

### Ventajas:
- ✅ No cambia arquitectura Docker actual
- ✅ Una sola imagen
- ✅ Go se ejecuta como subproceso
- ✅ Fácil de mantener

---

## 📂 Estructura de Archivos

```
poarl-backend/
├── app/
│   ├── Jobs/
│   │   └── LoadExcelWithCopyJob.php (MODIFICADO - usar Go)
│   └── Services/
│       └── Recaudo/
│           ├── ExcelToCsvConverter.php (DEPRECADO - mantener por compatibilidad)
│           ├── PostgreSQLCopyImporter.php (YA OPTIMIZADO - no tocar)
│           └── GoExcelConverter.php (NUEVO - wrapper PHP para Go)
│
└── bin/                                (NUEVO)
    ├── excel_to_csv                    (binario Go compilado Linux AMD64)
    └── src/
        └── excel_to_csv.go             (código fuente Go)

poarl-infra/
└── php/
    └── Dockerfile (MODIFICADO - copiar binario Go)
```

---

## 🔄 Flujo de Datos

### ANTES (PHP OpenSpout):
```
LoadExcelWithCopyJob
  ↓
ExcelToCsvConverter::convertAllSheetsToSeparateCSVs()
  ↓ ~40-60 segundos (PHP streaming)
CSVs generados
  ↓
PostgreSQLCopyImporter::importFromFile()
  ↓ ~2-5 segundos (COPY FROM STDIN)
Datos en PostgreSQL
```

### AHORA (Go excelize):
```
LoadExcelWithCopyJob
  ↓
GoExcelConverter::convertAllSheetsToSeparateCSVs()
  ↓
  Process::run(['/usr/local/bin/excel_to_csv', ...])
    ↓ ~5-7 segundos (Go multithreading + optimizado)
  JSON result with sheet info
  ↓
CSVs generados
  ↓
PostgreSQLCopyImporter::importFromFile()
  ↓ ~2-5 segundos (COPY FROM STDIN - ya optimizado)
Datos en PostgreSQL
```

**Total**: ~10-12 segundos (vs ~50-70 segundos antes)

---

## 📊 Tiempos Estimados de Go

### Escritura CSV por Go:

| Archivo | Tamaño | Hojas | Filas Estimadas | Tiempo Go | MB/s |
|---------|--------|-------|-----------------|-----------|------|
| PAGAPL | 190 MB | ~3-5 | ~100,000 | **~5s** | ~38 MB/s |
| PAGPLA | 289 MB | ~3-5 | ~150,000 | **~7s** | ~41 MB/s |
| DETTRA | 202 MB | ~3-5 | ~240,000 | **~5s** | ~40 MB/s |

**Factores que afectan velocidad:**
- Número de hojas (más hojas = más archivos)
- Número de columnas (más columnas = filas más anchas)
- Disco I/O (SSD vs HDD)
- CPU (Go usa múltiples cores eficientemente)

**Velocidad conservadora**: 35-45 MB/s
**Velocidad optimista**: 50-70 MB/s (en SSD rápido)

### Breakdown por archivo (estimación conservadora 40 MB/s):

```
PAGAPL (190 MB):
  - Leer Excel: ~3s
  - Escribir CSVs: ~2s
  - TOTAL: ~5s

PAGPLA (289 MB):
  - Leer Excel: ~4s
  - Escribir CSVs: ~3s
  - TOTAL: ~7s

DETTRA (202 MB):
  - Leer Excel: ~3s
  - Escribir CSVs: ~2s
  - TOTAL: ~5s

TOTAL CONVERSIÓN: ~17s
```

---

## 🚀 Mejora Total End-to-End

| Fase | Antes (PHP) | Ahora (Go) | Mejora |
|------|-------------|------------|--------|
| **Excel→CSV** | 145s | 17s | **8.5x** |
| **CSV→PostgreSQL (COPY)** | 5s | 5s | 1x (ya optimizado) |
| **SQL Processing** | ~30s | ~30s | 1x (sin cambios) |
| **TOTAL** | **~180s (3 min)** | **~52s** | **3.5x** |

---

## 🔧 Componentes a Crear/Modificar

### 1. **bin/src/excel_to_csv.go** (NUEVO)
- Binario Go standalone
- Recibe: `--input file.xlsx --output /tmp/dir --delimiter ;`
- Retorna: JSON con info de hojas procesadas
- Tamaño compilado: ~10-12 MB

### 2. **bin/excel_to_csv** (NUEVO - binario compilado)
- Linux AMD64
- Statically linked (sin dependencias runtime)

### 3. **app/Services/Recaudo/GoExcelConverter.php** (NUEVO)
- Wrapper PHP para llamar binario Go
- Usa `Symfony\Component\Process\Process`
- Compatible con interfaz de `ExcelToCsvConverter`

### 4. **app/Jobs/LoadExcelWithCopyJob.php** (MODIFICAR)
- Línea ~58: Cambiar `ExcelToCsvConverter` → `GoExcelConverter`
- Todo lo demás igual (COPY ya optimizado)

### 5. **poarl-infra/php/Dockerfile** (MODIFICAR)
- Agregar: `COPY bin/excel_to_csv /usr/local/bin/excel_to_csv`
- Agregar: `RUN chmod +x /usr/local/bin/excel_to_csv`

### 6. **app/Providers/CollectionNoticeServiceProvider.php** (MODIFICAR)
- Registrar `GoExcelConverter` en el container

---

## 📝 Pasos de Implementación

1. ✅ Crear script Go (`bin/src/excel_to_csv.go`)
2. ✅ Compilar binario Go → `bin/excel_to_csv`
3. ✅ Crear `GoExcelConverter.php`
4. ✅ Modificar `LoadExcelWithCopyJob.php`
5. ✅ Modificar Dockerfile
6. ✅ Registrar servicio en ServiceProvider
7. ✅ Rebuild containers
8. ✅ Probar con Run #1

---

## ⚠️ Consideraciones

### Ventajas:
- ✅ 8.5x más rápido en conversión Excel→CSV
- ✅ Bajo consumo de memoria (~30MB vs ~200MB PHP)
- ✅ No requiere cambios en arquitectura Docker
- ✅ Binario standalone (no dependencias runtime)
- ✅ Fácil rollback (solo cambiar inyección de dependencia)

### Riesgos/Limitaciones:
- ⚠️ Nuevo lenguaje en stack (Go)
- ⚠️ Binario debe recompilarse para otros OS (ARM, Mac, etc)
- ⚠️ Debugging más complejo (proceso externo)
- ⚠️ Límite de memoria del container (debe poder escribir CSVs grandes)

### Plan de Contingencia:
- Mantener `ExcelToCsvConverter.php` como fallback
- Feature flag para alternar entre Go/PHP
- Logs detallados en ambos converters

---

## 🧪 Validación de Éxito

### Criterios:
1. ✅ Conversión Excel→CSV < 20 segundos (vs ~145s)
2. ✅ CSVs tienen columna `sheet_name`
3. ✅ Mismo número de filas que PHP converter
4. ✅ COPY funciona sin errores
5. ✅ Run completo < 60 segundos total

### Métricas a Monitorear:
- `go_time_ms` en logs
- `rows_per_second` de conversión
- Uso de CPU/memoria durante conversión
- Tamaño de CSVs generados

---

## 📌 Estado Actual

- ✅ Problema identificado: Excel→CSV es el cuello de botella
- ✅ Solución elegida: Go excelize (8.5x más rápido)
- ✅ Arquitectura definida: Go dentro de PHP container
- ✅ Performance estimada: 17s conversión + 5s COPY = 22s total
- 🔄 En implementación...

---

**Siguiente paso**: Crear archivos y probar con Run #1
