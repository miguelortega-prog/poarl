# Fix: Nombres Originales de Hojas Excel en sheet_name
**Fecha**: 2025-10-06
**Estado**: ✅ Implementado - Esperando pruebas

---

## 🎯 Problema Original

La columna `sheet_name` en las tablas Excel (PAGAPL, PAGPLA, DETTRA) estaba recibiendo nombres genéricos (`sheet1`, `sheet2`) en lugar de los nombres originales de las hojas del Excel (`2021`, `2022-2023`, `Continuación`, etc.).

### Causa Raíz

1. **Binario Go** (`excel_streaming.go`): Extraía nombres de archivos XML (`sheet1.xml` → `sheet1`) en lugar de leer los nombres reales desde `workbook.xml`
2. **Job PHP** (`LoadExcelWithCopyJob`): No pasaba el nombre de hoja al normalizador
3. **Normalizador** (`normalizeCSV()`): No agregaba valor de `sheet_name`

---

## 🔧 Solución Implementada

### 1. Binario Go - Leer Nombres Reales

**Archivo**: `bin/src/excel_streaming.go`

#### Cambios:

**A. Struct para Workbook** (línea 39):
```go
type Workbook struct {
	XMLName xml.Name `xml:"workbook"`
	Sheets  struct {
		Sheet []struct {
			Name    string `xml:"name,attr"`
			SheetID string `xml:"sheetId,attr"`
			RID     string `xml:"http://schemas.openxmlformats.org/officeDocument/2006/relationships id,attr"`
		} `xml:"sheet"`
	} `xml:"sheets"`
}
```

**B. Función para Cargar Nombres** (línea 226):
```go
func loadSheetNames(zipReader *zip.Reader) (map[string]string, error) {
	for _, file := range zipReader.File {
		if file.Name == "xl/workbook.xml" {
			rc, err := file.Open()
			if err != nil {
				return nil, err
			}
			defer rc.Close()

			var workbook Workbook
			decoder := xml.NewDecoder(rc)
			if err := decoder.Decode(&workbook); err != nil {
				return nil, err
			}

			// Crear mapa de sheet ID → nombre
			sheetMap := make(map[string]string)
			for _, sheet := range workbook.Sheets.Sheet {
				sheetMap[sheet.SheetID] = sheet.Name
			}

			return sheetMap, nil
		}
	}

	return make(map[string]string), nil
}
```

**C. Main() - Uso del Mapeo** (línea 107-132):
```go
// Cargar nombres de hojas desde workbook.xml
sheetNames, err := loadSheetNames(&zipReader.Reader)
if err != nil {
	result.Success = false
	result.Error = fmt.Sprintf("Error loading sheet names: %v", err)
	outputJSON(result)
	os.Exit(1)
}

// ...

// Procesar cada hoja
for _, file := range zipReader.File {
	if !strings.HasPrefix(file.Name, "xl/worksheets/sheet") || !strings.HasSuffix(file.Name, ".xml") {
		continue
	}

	// Extraer sheet ID del nombre del archivo (sheet1.xml → 1)
	sheetFilename := strings.TrimSuffix(strings.TrimPrefix(file.Name, "xl/worksheets/"), ".xml")
	sheetID := strings.TrimPrefix(sheetFilename, "sheet")

	// Obtener nombre real de la hoja desde workbook.xml
	sheetName := sheetNames[sheetID]
	if sheetName == "" {
		sheetName = sheetFilename // Fallback al nombre del archivo si no se encuentra
	}

	// Usar sheetName en lugar de sheetFilename
	csvPath := filepath.Join(*output, sheetName+".csv")
	// ...
}
```

**Compilación**:
```bash
docker run --rm -v /home/migleor/poarl/poarl-backend/bin:/app golang:1.22 \
  sh -c 'cd /app/src && go build -o /app/excel_streaming excel_streaming.go && chmod +x /app/excel_streaming'

# Copiar a contenedores
docker cp /home/migleor/poarl/poarl-backend/bin/excel_streaming poarl-php:/usr/local/bin/excel_streaming
docker cp /home/migleor/poarl/poarl-backend/bin/excel_streaming poarl-horizon:/usr/local/bin/excel_streaming
```

---

### 2. Job PHP - Pasar Nombre de Hoja

**Archivo**: `app/Jobs/LoadExcelWithCopyJob.php`

#### Cambios:

**A. Llamada a normalizeCSV()** (línea 141-155):
```php
foreach ($conversionResult['sheets'] as $sheetName => $sheetInfo) {
    $csvPath = $disk->path($sheetInfo['path']);
    $csvPaths[] = $csvPath;

    // ✅ NUEVO: Asegurar que $sheetName sea string (puede venir como int si es numérico)
    $sheetName = (string) $sheetName;

    Log::info('');
    Log::info('📄 Procesando hoja: ' . $sheetName);
    // ...

    // ✅ NUEVO: Pasar $sheetName al normalizador
    $normalizedCsv = $this->normalizeCSV($csvPath, $columns, ';', $sheetName);
    $csvPaths[] = $normalizedCsv;
    // ...
}
```

**B. Firma de normalizeCSV()** (línea 240-256):
```php
/**
 * Normaliza un CSV para que tenga todas las columnas esperadas.
 * Agrega columnas faltantes con valores vacíos.
 * ✅ NUEVO: Si existe columna 'sheet_name', la llena con el nombre de la hoja.
 *
 * @param string $csvPath Ruta al CSV original
 * @param array $expectedColumns Lista de columnas esperadas (sin run_id)
 * @param string $delimiter Delimitador del CSV
 * @param string|null $sheetName ✅ NUEVO: Nombre de la hoja (para columna sheet_name)
 * @return string Ruta al CSV normalizado
 */
private function normalizeCSV(
    string $csvPath,
    array $expectedColumns,
    string $delimiter = ';',
    ?string $sheetName = null  // ✅ NUEVO parámetro
): string {
```

**C. Lógica de Normalización** (línea 291-306):
```php
foreach ($expectedColumns as $col) {
    // ✅ NUEVO: Si la columna es 'sheet_name' y tenemos el nombre de la hoja, usarlo
    if (strtolower($col) === 'sheet_name' && $sheetName !== null) {
        $normalizedRow[] = $sheetName;
        continue;
    }

    $sourceIndex = $columnMapping[$col];
    if ($sourceIndex !== null && isset($data[$sourceIndex])) {
        // Escapar comillas dobles para PostgreSQL COPY
        $value = str_replace('"', '""', $data[$sourceIndex]);
        $normalizedRow[] = $value;
    } else {
        $normalizedRow[] = ''; // Valor vacío para columnas faltantes
    }
}
```

**D. Log de Debugging** (línea 277-283):
```php
// ✅ NUEVO: Log para debugging
Log::info('Normalizando CSV', [
    'csv_path' => basename($csvPath),
    'expected_columns' => count($expectedColumns),
    'sheet_name' => $sheetName,
    'has_sheet_name_column' => in_array('sheet_name', $expectedColumns),
]);
```

---

## 📊 Resultados Esperados

### Antes (con nombres genéricos):
```sql
SELECT DISTINCT sheet_name FROM data_source_pagapl WHERE run_id = 1;
-- sheet1
-- sheet2
-- sheet3
-- sheet4
```

### Ahora (con nombres originales):
```sql
SELECT DISTINCT sheet_name FROM data_source_pagapl WHERE run_id = 2;
-- 2021
-- 2022-2023
-- 2024-2025
-- (nombre de hoja 4)
```

### Para DETTRA:
```sql
SELECT DISTINCT sheet_name FROM data_source_dettra WHERE run_id = 2;
-- sheet2          (hoja sin nombre en workbook)
-- Continuación    (nombre original)
```

---

## ✅ Verificación

### Logs de Conversión Go:
```
[2025-10-06 01:00:16] local.INFO: 📄 Hojas procesadas: 2021, sheet2, 2022-2023, 2024-2025
```
✅ Go está leyendo nombres originales correctamente

### Logs de Normalización:
```
[2025-10-06 01:01:19] local.INFO: Normalizando CSV {"csv_path":"sheet2.csv","expected_columns":40,"sheet_name":"sheet2","has_sheet_name_column":true}
```
✅ PHP está recibiendo y usando sheet_name

### Error Resuelto:
```
[2025-10-06 01:00:16] ERROR: Argument #4 ($sheetName) must be of type ?string, int given, called... with 2021
```
**Causa**: Hojas con nombres numéricos (`"2021"`) eran interpretadas como `int` por PHP
**Solución**: Casteo explícito a string: `$sheetName = (string) $sheetName;`

---

## 🔄 Proceso de Recarga

```bash
# 1. Limpiar datos existentes
docker exec poarl-php php artisan tinker --execute="
\$runId = 2;
DB::table('data_source_pagapl')->where('run_id', \$runId)->delete();
DB::table('data_source_pagpla')->where('run_id', \$runId)->delete();
DB::table('data_source_dettra')->where('run_id', \$runId)->delete();
"

# 2. Reiniciar Horizon (cargar código nuevo)
docker-compose restart poarl-horizon

# 3. Despachar jobs
docker exec poarl-php php artisan tinker --execute="
use App\Jobs\LoadExcelWithCopyJob;
dispatch(new LoadExcelWithCopyJob(11, 'PAGAPL'));
dispatch(new LoadExcelWithCopyJob(12, 'PAGPLA'));
dispatch(new LoadExcelWithCopyJob(10, 'DETTRA'));
"
```

---

## 🧪 Testing Post-Implementación

### Queries de Verificación:

```sql
-- 1. Verificar nombres de hojas en PAGAPL
SELECT
    sheet_name,
    COUNT(*) as registros,
    MIN(created_at) as primera_carga,
    MAX(created_at) as ultima_carga
FROM data_source_pagapl
WHERE run_id = 2
GROUP BY sheet_name
ORDER BY sheet_name;

-- 2. Verificar nombres de hojas en PAGPLA
SELECT
    sheet_name,
    COUNT(*) as registros
FROM data_source_pagpla
WHERE run_id = 2
GROUP BY sheet_name
ORDER BY sheet_name;

-- 3. Verificar nombres de hojas en DETTRA
SELECT
    sheet_name,
    COUNT(*) as registros
FROM data_source_dettra
WHERE run_id = 2
GROUP BY sheet_name
ORDER BY sheet_name;

-- 4. Verificar que NO haya sheet1, sheet2, sheet3 genéricos
SELECT
    'PAGAPL' as tabla,
    sheet_name,
    COUNT(*) as registros
FROM data_source_pagapl
WHERE run_id = 2 AND sheet_name LIKE 'sheet%'
UNION ALL
SELECT
    'PAGPLA',
    sheet_name,
    COUNT(*)
FROM data_source_pagpla
WHERE run_id = 2 AND sheet_name LIKE 'sheet%'
UNION ALL
SELECT
    'DETTRA',
    sheet_name,
    COUNT(*)
FROM data_source_dettra
WHERE run_id = 2 AND sheet_name LIKE 'sheet%';
-- Debe retornar 0 registros (excepto hojas que realmente se llamen "sheetX")
```

---

## 📝 Notas Importantes

1. **Hojas Sin Nombre**: Si una hoja en el Excel no tiene nombre explícito en `workbook.xml`, fallback a `sheetX`
2. **Nombres Numéricos**: Hojas con nombres numéricos (`2021`, `2`, `3`) requieren casteo explícito a string
3. **Compatibilidad**: La columna `sheet_name` es case-insensitive en el normalizador (`strtolower($col) === 'sheet_name'`)
4. **Idempotencia**: Mantiene limpieza previa (`DELETE WHERE run_id = X`) antes de insertar

---

**Estado**: ✅ Código implementado y despachado
**Próximo paso**: Verificar que los 3 jobs terminen exitosamente y validar datos en BD
