package main

import (
	"archive/zip"
	"encoding/csv"
	"encoding/json"
	"encoding/xml"
	"flag"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Estructuras para parsear XML de Excel
type SST struct {
	XMLName xml.Name `xml:"sst"`
	SI      []SI     `xml:"si"`
}

type SI struct {
	T string `xml:"t"`
}

type Row struct {
	XMLName xml.Name `xml:"row"`
	R       string   `xml:"r,attr"` // Número de fila
	C       []Cell   `xml:"c"`
}

type Cell struct {
	R string `xml:"r,attr"` // Referencia (ej: A1)
	T string `xml:"t,attr"` // Tipo (s=string, n=number)
	V string `xml:"v"`      // Valor
}

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

type SheetResult struct {
	Name     string `json:"name"`
	Path     string `json:"path"`
	Rows     int    `json:"rows"`
	SizeKB   int64  `json:"size_kb"`
	Duration int    `json:"duration_ms"`
}

type Result struct {
	Success   bool          `json:"success"`
	Sheets    []SheetResult `json:"sheets"`
	TotalRows int           `json:"total_rows"`
	TotalTime int           `json:"total_time_ms"`
	Error     string        `json:"error,omitempty"`
}

func main() {
	input := flag.String("input", "", "Excel file path")
	output := flag.String("output", "", "Output directory")
	delimiter := flag.String("delimiter", ";", "CSV delimiter")
	flag.Parse()

	if *input == "" || *output == "" {
		fmt.Fprintln(os.Stderr, "Usage: excel_streaming --input <file.xlsx> --output <dir> [--delimiter ;]")
		os.Exit(1)
	}

	startTime := time.Now()
	result := Result{Success: true, Sheets: []SheetResult{}}

	// Abrir ZIP del Excel
	zipReader, err := zip.OpenReader(*input)
	if err != nil {
		result.Success = false
		result.Error = fmt.Sprintf("Error opening Excel ZIP: %v", err)
		outputJSON(result)
		os.Exit(1)
	}
	defer zipReader.Close()

	// Crear directorio de salida
	if err := os.MkdirAll(*output, 0755); err != nil {
		result.Success = false
		result.Error = fmt.Sprintf("Error creating output directory: %v", err)
		outputJSON(result)
		os.Exit(1)
	}

	// Cargar sharedStrings en memoria (necesario para textos)
	sharedStrings, err := loadSharedStrings(&zipReader.Reader)
	if err != nil {
		result.Success = false
		result.Error = fmt.Sprintf("Error loading shared strings: %v", err)
		outputJSON(result)
		os.Exit(1)
	}

	// Cargar nombres de hojas desde workbook.xml
	sheetNames, err := loadSheetNames(&zipReader.Reader)
	if err != nil {
		result.Success = false
		result.Error = fmt.Sprintf("Error loading sheet names: %v", err)
		outputJSON(result)
		os.Exit(1)
	}

	totalRows := 0

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

		sheetStart := time.Now()

		csvPath := filepath.Join(*output, sheetName+".csv")
		csvFile, err := os.Create(csvPath)
		if err != nil {
			result.Success = false
			result.Error = fmt.Sprintf("Error creating CSV for %s: %v", sheetName, err)
			outputJSON(result)
			os.Exit(1)
		}

		writer := csv.NewWriter(csvFile)
		writer.Comma = rune((*delimiter)[0])

		// Procesar hoja con streaming
		rowCount, err := processSheetStreaming(file, writer, sharedStrings, sheetName)
		if err != nil {
			csvFile.Close()
			result.Success = false
			result.Error = fmt.Sprintf("Error processing sheet %s: %v", sheetName, err)
			outputJSON(result)
			os.Exit(1)
		}

		writer.Flush()
		csvFile.Close()

		// Obtener tamaño del CSV
		fileInfo, err := os.Stat(csvPath)
		if err != nil {
			result.Success = false
			result.Error = fmt.Sprintf("Error getting CSV file info: %v", err)
			outputJSON(result)
			os.Exit(1)
		}

		sheetDuration := int(time.Since(sheetStart).Milliseconds())

		result.Sheets = append(result.Sheets, SheetResult{
			Name:     sheetName,
			Path:     csvPath,
			Rows:     rowCount,
			SizeKB:   fileInfo.Size() / 1024,
			Duration: sheetDuration,
		})

		totalRows += rowCount
	}

	result.TotalRows = totalRows
	result.TotalTime = int(time.Since(startTime).Milliseconds())

	outputJSON(result)
}

func loadSharedStrings(zipReader *zip.Reader) ([]string, error) {
	for _, file := range zipReader.File {
		if file.Name == "xl/sharedStrings.xml" {
			rc, err := file.Open()
			if err != nil {
				return nil, err
			}
			defer rc.Close()

			decoder := xml.NewDecoder(rc)

			// Parsear con streaming para evitar cargar todo en memoria
			strings := []string{}
			for {
				token, err := decoder.Token()
				if err == io.EOF {
					break
				}
				if err != nil {
					return nil, err
				}

				if se, ok := token.(xml.StartElement); ok && se.Name.Local == "si" {
					var si SI
					if err := decoder.DecodeElement(&si, &se); err != nil {
						return nil, err
					}
					strings = append(strings, si.T)
				}
			}

			return strings, nil
		}
	}
	return []string{}, nil
}

func loadSheetNames(zipReader *zip.Reader) (map[string]string, error) {
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

func processSheetStreaming(zipFile *zip.File, writer *csv.Writer, sharedStrings []string, sheetName string) (int, error) {
	rc, err := zipFile.Open()
	if err != nil {
		return 0, err
	}
	defer rc.Close()

	decoder := xml.NewDecoder(rc)
	rowCount := 0
	var currentRow []string
	var maxCol int

	for {
		token, err := decoder.Token()
		if err == io.EOF {
			break
		}
		if err != nil {
			return 0, err
		}

		switch se := token.(type) {
		case xml.StartElement:
			if se.Name.Local == "row" {
				var row Row
				if err := decoder.DecodeElement(&row, &se); err != nil {
					return 0, err
				}

				// Determinar número máximo de columnas en primera fila
				if rowCount == 0 {
					maxCol = len(row.C)
				}

				// Inicializar fila con valores vacíos
				currentRow = make([]string, maxCol+1) // +1 para sheet_name

				// Llenar valores de celdas
				for _, cell := range row.C {
					colIdx := getColumnIndex(cell.R)
					if colIdx < maxCol {
						value := cell.V
						// Si es string shared, obtener de sharedStrings
						if cell.T == "s" {
							idx := 0
							fmt.Sscanf(value, "%d", &idx)
							if idx < len(sharedStrings) {
								value = sharedStrings[idx]
							}
						}
						currentRow[colIdx] = value
					}
				}

				// Agregar sheet_name
				if rowCount == 0 {
					currentRow[maxCol] = "sheet_name"
				} else {
					currentRow[maxCol] = sheetName
				}

				// Escribir fila a CSV
				if err := writer.Write(currentRow); err != nil {
					return 0, err
				}

				rowCount++

				// Log progreso cada 50k filas
				if rowCount%50000 == 0 {
					fmt.Fprintf(os.Stderr, "Procesadas %d filas de %s\n", rowCount, sheetName)
				}
			}
		}
	}

	return rowCount, nil
}

func getColumnIndex(cellRef string) int {
	// Convertir referencia de celda (ej: "A1", "AB5") a índice de columna
	col := ""
	for _, c := range cellRef {
		if c >= 'A' && c <= 'Z' {
			col += string(c)
		} else {
			break
		}
	}

	idx := 0
	for i, c := range col {
		idx = idx*26 + int(c-'A'+1)
		if i > 0 {
			idx--
		}
	}
	return idx - 1
}

func outputJSON(result Result) {
	json, err := json.MarshalIndent(result, "", "  ")
	if err != nil {
		fmt.Fprintf(os.Stderr, "Error marshaling JSON: %v\n", err)
		os.Exit(1)
	}
	fmt.Println(string(json))
}
