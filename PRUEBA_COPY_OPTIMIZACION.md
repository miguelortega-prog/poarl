# Prueba de Optimización: Excel → CSV → PostgreSQL COPY

**Fecha**: 2025-10-03
**Objetivo**: Validar optimización de LoadExcelWithCopyJob con COPY FROM STDIN

---

## 🎯 Cambios Implementados

### 1. **PostgreSQLCopyImporter.php** (líneas 36-147)
- ❌ ANTES: `pgsqlCopyFromArray()` - carga todo en memoria
- ✅ AHORA: `pg_put_line()` + `pg_end_copy()` - streaming línea por línea

### 2. **LoadExcelWithCopyJob.php** (líneas 105-153)
- ❌ ANTES: Chunks de 5000 filas con `insertDataInChunks()`
- ✅ AHORA: `PostgreSQLCopyImporter::importFromFile()` con COPY FROM STDIN

---

## 📊 Mejora Esperada

| Archivo | Filas | Antes (chunks) | Ahora (COPY) | Mejora |
|---------|-------|----------------|--------------|--------|
| PAGAPL  | 100K  | ~50 seg        | ~2 seg       | 25x    |
| DETTRA  | 240K  | ~4 min         | ~10 seg      | 24x    |

**rows_per_second esperado**: 30,000 - 50,000

---

## 🔍 Flujo de Jobs

```
ProcessCollectionRunValidation (validation queue)
  ↓
Batch Paralelo (collection-notices queue)
  ├─ LoadCsvDataSourcesJob (BASCAR, BAPRPO, DATPOL)
  ├─ LoadExcelWithCopyJob (PAGAPL) ← OPTIMIZADO
  └─ LoadExcelWithCopyJob (DETTRA) ← OPTIMIZADO
  ↓
ProcessCollectionDataJob (processing queue)
  └─ ConstitucionMoraAportantesProcessor (7 pasos SQL)
```

---

## 🧪 Estado Inicial del Sistema

- **Users**: 0
- **Collection Notice Types**: 10
- **Data Sources**: 12
- **Runs**: 0
- **Tablas vacías**: BASCAR (0), PAGAPL (0), DETTRA (0)

✅ Sistema limpio, listo para prueba

---

## 📝 Logs Clave a Monitorear

### LoadExcelWithCopyJob
```
✅ "Iniciando carga optimizada Excel → PostgreSQL COPY"
✅ "Excel dividido en hojas" → {total_sheets, sheets}
✅ "Importando hoja con PostgreSQL COPY" → {sheet_name, expected_rows}
✅ "Hoja importada exitosamente con COPY" → {rows_imported, duration_ms, rows_per_second}
✅ "Carga OPTIMIZADA completada" → {total_rows, method: "COPY FROM STDIN"}
```

### PostgreSQLCopyImporter
```
✅ "Iniciando importación COPY FROM STDIN" → {table, file_size_mb}
✅ "Progreso COPY" (cada 50K filas) → {rows_sent}
✅ "Importación COPY completada" → {rows_imported, rows_per_second, mb_per_second}
```

### Batch
```
✅ "Todos los archivos cargados (OPTIMIZADO), iniciando procesamiento SQL"
```

---

## ⚠️ Posibles Errores

1. **pg_put_line() falla**: Verificar extensión PostgreSQL
2. **Formato CSV**: Delimitador (;), encoding (UTF-8)
3. **Columnas no coinciden**: CSV headers vs tabla
4. **Timeout**: Aumentar si archivo >500MB

---

## ✅ Criterios de Éxito

1. ✅ `rows_per_second` > 30,000
2. ✅ Batch completa sin errores
3. ✅ Filas en BD = filas en archivo
4. ✅ ProcessCollectionDataJob ejecuta 7 pasos SQL
5. ✅ Tiempo total < 30 segundos (vs 5+ minutos antes)

---

## 🚀 Inicio de Prueba

**Comando logs en background**: ID `21fdc1`

Listo para crear Run desde UI y monitorear progreso.
