# Fix FINAL: Mapeo Correcto de Nombres de Hojas Excel
**Fecha**: 2025-10-06
**Estado**: ✅ RESUELTO

---

## 🎯 Problema Original

Después del primer fix, aún había problemas:
- **PAGAPL** mostraba "sheet2" en lugar de "2020" (perdía primera hoja)
- **DETTRA** mostraba "sheet2" en lugar de "Base" (perdía primera hoja)

---

## 🔍 Causa Raíz

El binario Go estaba usando `sheetId` del `workbook.xml` como clave de mapeo, pero este ID **no corresponde** al índice del archivo físico.

### Ejemplo del problema (PAGAPL):

**workbook.xml**:
```xml
<sheet name="2020" sheetId="5" r:id="rId1"/>
<sheet name="2021" sheetId="4" r:id="rId2"/>
<sheet name="2022-2023" sheetId="3" r:id="rId3"/>
<sheet name="2024-2025" sheetId="1" r:id="rId4"/>
```

**Archivos físicos**: `sheet1.xml`, `sheet2.xml`, `sheet3.xml`, `sheet4.xml`

**Relaciones (xl/_rels/workbook.xml.rels)**:
```xml
<Relationship Id="rId1" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Target="worksheets/sheet2.xml"/>
<Relationship Id="rId3" Target="worksheets/sheet3.xml"/>
<Relationship Id="rId4" Target="worksheets/sheet4.xml"/>
```

### Mapeo INCORRECTO (versión anterior):
```
sheetId="5" → "2020"  // ❌ No existe sheet5.xml
sheetId="4" → "2021"  // ❌ No existe sheet4.xml
sheetId="3" → "2022-2023"
sheetId="1" → "2024-2025"
```

### Mapeo CORRECTO (versión final):
```
rId1 → sheet1.xml → "2020"
rId2 → sheet2.xml → "2021"
rId3 → sheet3.xml → "2022-2023"
rId4 → sheet4.xml → "2024-2025"
```

---

## 🔧 Solución Implementada

### 1. Binario Go - Nuevo Mapeo por r:id

**Archivo**: `bin/src/excel_streaming.go`

#### A. Struct para Relaciones (línea 50):
```go
type Relationships struct {
	XMLName      xml.Name       `xml:"Relationships"`
	Relationship []Relationship `xml:"Relationship"`
}

type Relationship struct {
	ID     string `xml:"Id,attr"`
	Target string `xml:"Target,attr"`
}
```

#### B. Función para Cargar Relaciones (línea 236):
```go
func loadRelationships(zipReader *zip.Reader) (map[string]string, error) {
	// Buscar xl/_rels/workbook.xml.rels
	for _, file := range zipReader.File {
		if file.Name == "xl/_rels/workbook.xml.rels" {
			rc, err := file.Open()
			if err != nil {
				return nil, err
			}
			defer rc.Close()

			var rels Relationships
			decoder := xml.NewDecoder(rc)
			if err := decoder.Decode(&rels); err != nil {
				return nil, err
			}

			// Crear mapa de rId → target (ej: rId1 → worksheets/sheet1.xml)
			relMap := make(map[string]string)
			for _, rel := range rels.Relationship {
				relMap[rel.ID] = rel.Target
			}

			return relMap, nil
		}
	}

	return make(map[string]string), nil
}
```

#### C. Función loadSheetNames() Actualizada (línea 265):
```go
func loadSheetNames(zipReader *zip.Reader) (map[string]string, error) {
	// Cargar relaciones (rId → archivo XML)
	rels, err := loadRelationships(zipReader)
	if err != nil {
		return nil, fmt.Errorf("error loading relationships: %v", err)
	}

	// Buscar workbook.xml
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

			// Crear mapa de nombre_archivo → nombre_hoja
			// Usando rId para conectar workbook.xml con archivos físicos
			sheetMap := make(map[string]string)
			for _, sheet := range workbook.Sheets.Sheet {
				// Obtener target del rId (ej: rId1 → worksheets/sheet1.xml)
				target := rels[sheet.RID]
				if target != "" {
					// Extraer nombre base del archivo (ej: worksheets/sheet1.xml → sheet1)
					baseName := strings.TrimSuffix(strings.TrimPrefix(target, "worksheets/"), ".xml")
					sheetMap[baseName] = sheet.Name
				}
			}

			return sheetMap, nil
		}
	}

	return make(map[string]string), nil
}
```

#### D. Uso en main() (línea 128):
```go
// Procesar cada hoja
for _, file := range zipReader.File {
	if !strings.HasPrefix(file.Name, "xl/worksheets/sheet") || !strings.HasSuffix(file.Name, ".xml") {
		continue
	}

	// Extraer nombre base del archivo (xl/worksheets/sheet1.xml → sheet1)
	sheetFilename := strings.TrimSuffix(strings.TrimPrefix(file.Name, "xl/worksheets/"), ".xml")

	// Obtener nombre real de la hoja desde el mapa (usa sheetFilename completo como clave)
	sheetName := sheetNames[sheetFilename]
	if sheetName == "" {
		sheetName = sheetFilename // Fallback al nombre del archivo si no se encuentra
	}

	csvPath := filepath.Join(*output, sheetName+".csv")
	// ...
}
```

---

## 🔨 Compilación y Despliegue

```bash
# 1. Compilar binario actualizado
docker run --rm -v /home/migleor/poarl/poarl-backend/bin:/app golang:1.22 \
  sh -c 'cd /app/src && go build -o /app/excel_streaming excel_streaming.go && chmod +x /app/excel_streaming'

# 2. Copiar a contenedores
docker cp /home/migleor/poarl/poarl-backend/bin/excel_streaming poarl-php:/usr/local/bin/excel_streaming
docker cp /home/migleor/poarl/poarl-backend/bin/excel_streaming poarl-horizon:/usr/local/bin/excel_streaming

# 3. Verificar funcionamiento
docker-compose exec poarl-php /usr/local/bin/excel_streaming \
  --input /var/www/html/storage/app/collection/collection_notice_runs/2/2/pagos-aplicados_20251005_230106.xlsx \
  --output /tmp/test_pagapl \
  --delimiter ';'
```

**Salida esperada**: JSON con 4 hojas ("2024-2025", "2021", "2022-2023", "2020")

---

## 🔄 Proceso de Recarga

```bash
# 1. Limpiar datos del run 2
docker-compose exec poarl-php php artisan tinker --execute="
DB::table('data_source_pagapl')->where('run_id', 2)->delete();
DB::table('data_source_pagpla')->where('run_id', 2)->delete();
DB::table('data_source_dettra')->where('run_id', 2)->delete();
"

# 2. Reiniciar Horizon
docker-compose restart poarl-horizon

# 3. Despachar jobs
docker-compose exec poarl-php php artisan tinker --execute="
use App\Jobs\LoadExcelWithCopyJob;
dispatch(new LoadExcelWithCopyJob(11, 'PAGAPL'));
dispatch(new LoadExcelWithCopyJob(12, 'PAGPLA'));
dispatch(new LoadExcelWithCopyJob(10, 'DETTRA'));
"
```

---

## ✅ Resultados Finales

### Nombres de Hojas en Base de Datos:

**PAGAPL** (4 hojas):
```
2020: 442,097 registros
2021: 442,102 registros
2022-2023: 888,253 registros
2024-2025: 820,143 registros
```

**PAGPLA** (3 hojas):
```
1: 1,043,047 registros
2: 898,129 registros
3: 1,023,230 registros
```

**DETTRA** (2 hojas):
```
Base: 703,188 registros
Continuación: 550,000 registros
```

**Total**: 6,810,189 registros cargados exitosamente
**Nombres genéricos (sheet1, sheet2)**: ✅ 0 (eliminados)

---

## 📚 Referencias

- **RFC sobre OOXML**: [Office Open XML File Formats](http://officeopenxml.com/)
- **Estructura de Excel**:
  - `xl/workbook.xml`: Metadata de hojas (nombres, IDs)
  - `xl/_rels/workbook.xml.rels`: Relaciones entre IDs y archivos físicos
  - `xl/worksheets/sheetN.xml`: Datos de cada hoja

---

## 🧪 Testing

```sql
-- Verificar nombres de hojas
SELECT
    'PAGAPL' as tabla,
    sheet_name,
    COUNT(*) as registros
FROM data_source_pagapl
WHERE run_id = 2
GROUP BY sheet_name
UNION ALL
SELECT 'PAGPLA', sheet_name, COUNT(*)
FROM data_source_pagpla
WHERE run_id = 2
GROUP BY sheet_name
UNION ALL
SELECT 'DETTRA', sheet_name, COUNT(*)
FROM data_source_dettra
WHERE run_id = 2
GROUP BY sheet_name
ORDER BY tabla, sheet_name;

-- Verificar que NO haya nombres genéricos
SELECT
    sheet_name,
    COUNT(*) as registros
FROM data_source_pagapl
WHERE run_id = 2 AND sheet_name LIKE 'sheet%'
GROUP BY sheet_name
UNION ALL
SELECT sheet_name, COUNT(*)
FROM data_source_pagpla
WHERE run_id = 2 AND sheet_name LIKE 'sheet%'
GROUP BY sheet_name
UNION ALL
SELECT sheet_name, COUNT(*)
FROM data_source_dettra
WHERE run_id = 2 AND sheet_name LIKE 'sheet%'
GROUP BY sheet_name;
-- Debe retornar 0 filas
```

---

**Estado**: ✅ RESUELTO - Todos los nombres de hojas se preservan correctamente
**Próximo paso**: Continuar con `ProcessCollectionDataJob` para transformaciones SQL
