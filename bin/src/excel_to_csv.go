package main

import (
	"encoding/csv"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"github.com/xuri/excelize/v2"
)

// SheetResult representa el resultado de procesar una hoja
type SheetResult struct {
	Name     string `json:"name"`
	Path     string `json:"path"`
	Rows     int    `json:"rows"`
	SizeKB   int64  `json:"size_kb"`
	Duration int    `json:"duration_ms"`
}

// Result representa el resultado completo de la conversión
type Result struct {
	Success   bool          `json:"success"`
	Sheets    []SheetResult `json:"sheets"`
	TotalRows int           `json:"total_rows"`
	TotalTime int           `json:"total_time_ms"`
	Error     string        `json:"error,omitempty"`
}

func main() {
	// Parsear argumentos
	input := flag.String("input", "", "Excel file path")
	output := flag.String("output", "", "Output directory")
	delimiter := flag.String("delimiter", ";", "CSV delimiter")
	flag.Parse()

	if *input == "" || *output == "" {
		fmt.Fprintln(os.Stderr, "Usage: excel_to_csv --input <file.xlsx> --output <dir> [--delimiter ;]")
		os.Exit(1)
	}

	startTime := time.Now()
	result := Result{Success: true, Sheets: []SheetResult{}}

	// Abrir archivo Excel
	f, err := excelize.OpenFile(*input)
	if err != nil {
		result.Success = false
		result.Error = fmt.Sprintf("Error opening Excel file: %v", err)
		outputJSON(result)
		os.Exit(1)
	}
	defer f.Close()

	// Crear directorio de salida
	if err := os.MkdirAll(*output, 0755); err != nil {
		result.Success = false
		result.Error = fmt.Sprintf("Error creating output directory: %v", err)
		outputJSON(result)
		os.Exit(1)
	}

	totalRows := 0

	// Procesar cada hoja
	for _, sheetName := range f.GetSheetList() {
		sheetStart := time.Now()

		csvPath := filepath.Join(*output, sheetName+".csv")
		csvFile, err := os.Create(csvPath)
		if err != nil {
			result.Success = false
			result.Error = fmt.Sprintf("Error creating CSV for sheet %s: %v", sheetName, err)
			outputJSON(result)
			os.Exit(1)
		}

		writer := csv.NewWriter(csvFile)
		writer.Comma = rune((*delimiter)[0])

		// IMPORTANTE: Usar GetRows() con opción RawCellValue para NO formatear fechas automáticamente
		// Esto preserva el texto tal como se muestra en Excel
		rows, err := f.GetRows(sheetName, excelize.Options{RawCellValue: true})
		if err != nil {
			csvFile.Close()
			result.Success = false
			result.Error = fmt.Sprintf("Error reading rows from sheet %s: %v", sheetName, err)
			outputJSON(result)
			os.Exit(1)
		}

		rowCount := 0

		for rowIndex, row := range rows {
			rowCount++

			// Para todas las filas (incluyendo header), agregar columna sheet_name
			if rowIndex == 0 {
				// Primera fila es el header
				row = append(row, "sheet_name")
			} else {
				// Para filas de datos, agregar valor de sheet_name
				// IMPORTANTE: RawCellValue: true lee los valores sin aplicar formato
				// Las fechas se mantienen como texto visible en Excel (6/01/2024)
				row = append(row, sheetName)
			}

			if err := writer.Write(row); err != nil {
				csvFile.Close()
				result.Success = false
				result.Error = fmt.Sprintf("Error writing row to CSV: %v", err)
				outputJSON(result)
				os.Exit(1)
			}
		}

		writer.Flush()
		if err := writer.Error(); err != nil {
			csvFile.Close()
			result.Success = false
			result.Error = fmt.Sprintf("Error flushing CSV writer: %v", err)
			outputJSON(result)
			os.Exit(1)
		}

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

func outputJSON(result Result) {
	json, err := json.MarshalIndent(result, "", "  ")
	if err != nil {
		fmt.Fprintf(os.Stderr, "Error marshaling JSON: %v\n", err)
		os.Exit(1)
	}
	fmt.Println(string(json))
}
