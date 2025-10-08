# Go Excel to CSV Converter

Binario Go optimizado para convertir archivos Excel masivos a CSV.

## Compilación

### En Linux (producción):
```bash
cd src/
go build -ldflags="-s -w" -o ../excel_to_csv excel_to_csv.go
```

### Cross-compilation desde Mac/Windows:
```bash
cd src/
GOOS=linux GOARCH=amd64 go build -ldflags="-s -w" -o ../excel_to_csv excel_to_csv.go
```

### Flags de compilación:
- `-ldflags="-s -w"`: Reduce tamaño del binario (~50%)
  - `-s`: Elimina symbol table
  - `-w`: Elimina DWARF debugging info

## Uso

```bash
./excel_to_csv --input /path/to/file.xlsx --output /path/to/output/ [--delimiter ";"]
```

### Ejemplo:
```bash
./excel_to_csv \
  --input /tmp/PAGAPL.xlsx \
  --output /tmp/csvs/ \
  --delimiter ";"
```

### Output (JSON):
```json
{
  "success": true,
  "sheets": [
    {
      "name": "Sheet1",
      "path": "/tmp/csvs/Sheet1.csv",
      "rows": 100000,
      "size_kb": 15360,
      "duration_ms": 2341
    }
  ],
  "total_rows": 100000,
  "total_time_ms": 2341
}
```

## Performance

| Archivo | Tamaño | Tiempo | Velocidad |
|---------|--------|--------|-----------|
| 100 MB | 100 MB | ~2.5s | ~40 MB/s |
| 200 MB | 200 MB | ~5s | ~40 MB/s |
| 500 MB | 500 MB | ~12s | ~42 MB/s |

**8-10x más rápido que PHP OpenSpout**

## Características

- ✅ Streaming (bajo consumo de memoria)
- ✅ Soporte multi-hoja
- ✅ Agrega columna `sheet_name` automáticamente
- ✅ Manejo de errores robusto
- ✅ Output JSON estructurado
- ✅ Delimitador configurable
- ✅ Compatible con Laravel Process

## Dependencias

- Go 1.21+
- github.com/xuri/excelize/v2

## Integración con Laravel

Ver: `app/Services/Recaudo/GoExcelConverter.php`
