#!/bin/bash
# Script para compilar el binario Go excel_to_csv
# Uso: ./compile.sh

set -e

echo "🔨 Compilando binario Go para Excel→CSV..."
echo ""

cd "$(dirname "$0")/src"

# Verificar que Go esté instalado
if ! command -v go &> /dev/null; then
    echo "❌ Go no está instalado."
    echo ""
    echo "Instala Go desde: https://go.dev/dl/"
    echo "O usa:"
    echo "  wget https://go.dev/dl/go1.21.0.linux-amd64.tar.gz"
    echo "  sudo tar -C /usr/local -xzf go1.21.0.linux-amd64.tar.gz"
    echo "  export PATH=\$PATH:/usr/local/go/bin"
    exit 1
fi

echo "✓ Go version: $(go version)"
echo ""

# Inicializar módulo si no existe go.mod
if [ ! -f "go.mod" ]; then
    echo "📦 Inicializando módulo Go..."
    go mod init excel_to_csv
fi

# Descargar dependencias
echo "📥 Descargando dependencias..."
go get github.com/xuri/excelize/v2
go mod tidy
echo ""

# Compilar para Linux AMD64 (Docker)
echo "🏗️  Compilando para Linux AMD64..."
GOOS=linux GOARCH=amd64 go build -ldflags="-s -w" -o ../excel_to_csv excel_to_csv.go

if [ -f "../excel_to_csv" ]; then
    SIZE=$(du -h ../excel_to_csv | cut -f1)
    echo ""
    echo "✅ Binario compilado exitosamente: bin/excel_to_csv"
    echo "   Tamaño: $SIZE"
    echo "   Target: Linux AMD64"
    echo ""
    echo "Para probar:"
    echo "  ./bin/excel_to_csv --input test.xlsx --output /tmp/csvs/"
    echo ""
    echo "Para rebuild Docker:"
    echo "  docker-compose build --no-cache poarl-php"
    echo "  docker-compose up -d"
else
    echo ""
    echo "❌ Error compilando binario"
    exit 1
fi
