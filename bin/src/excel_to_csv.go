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

		rows, err := f.Rows(sheetName)
		if err != nil {
			csvFile.Close()
			result.Success = false
			result.Error = fmt.Sprintf("Error reading rows from sheet %s: %v", sheetName, err)
			outputJSON(result)
			os.Exit(1)
		}

		rowCount := 0
		isFirstRow := true

		for rows.Next() {
			row, err := rows.Columns()
			if err != nil {
				continue
			}

			// Agregar columna sheet_name al header (primera fila)
			if isFirstRow {
				row = append(row, "sheet_name")
				isFirstRow = false
			} else {
				// Agregar valor de sheet_name a las filas de datos
				row = append(row, sheetName)
			}

			if err := writer.Write(row); err != nil {
				csvFile.Close()
				result.Success = false
				result.Error = fmt.Sprintf("Error writing row to CSV: %v", err)
				outputJSON(result)
				os.Exit(1)
			}

			rowCount++
		}

		if err := rows.Close(); err != nil {
			csvFile.Close()
			result.Success = false
			result.Error = fmt.Sprintf("Error closing rows iterator: %v", err)
			outputJSON(result)
			os.Exit(1)
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
