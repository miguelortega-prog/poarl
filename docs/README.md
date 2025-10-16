# Documentación del Proyecto POARL

Bienvenido a la documentación técnica del proyecto POARL (Portal Administrativo de Recaudo Laboral).

## 📚 Índice de Documentación

### 🎯 [CONTEXTO_GENERAL_PROYECTO.md](./CONTEXTO_GENERAL_PROYECTO.md)
**Documento maestro** - Punto de partida para comprender el proyecto.

Incluye:
- Información general del proyecto
- Stack tecnológico completo
- Estructura del proyecto
- Estado actual del desarrollo
- Comandos frecuentes
- Convenciones y estándares
- Troubleshooting común
- Roadmap

**Recomendado para**: Nuevos desarrolladores, contexto rápido del proyecto.

---

### 🐳 [CONTEXTO_INFRAESTRUCTURA_DOCKER.md](./CONTEXTO_INFRAESTRUCTURA_DOCKER.md)
**Infraestructura y DevOps** - Todo sobre la arquitectura Docker.

Incluye:
- Arquitectura de 5 servicios Docker
- Configuración de PHP 8.3-FPM
- Nginx, PostgreSQL 16, Redis 7, Horizon
- Binarios Go para conversión de Excel
- Optimizaciones de performance
- Volúmenes y storage
- Comandos Docker útiles
- Troubleshooting de infraestructura

**Recomendado para**: Configuración de entorno, debugging de Docker, optimización de performance.

---

### 🔄 [CONTEXTO_PIPELINE_COMUNICADOS.md](./CONTEXTO_PIPELINE_COMUNICADOS.md)
**Sistema de Comunicados de Recaudo** - Documentación técnica detallada del core del sistema.

Incluye:
- Arquitectura de tablas (11 tablas principales)
- Flujo completo de procesamiento (8 fases)
- Patrones de manejo de datos (6 patrones)
- Procesadores completos:
  - ConstitucionMoraAportantesProcessor (24 pasos) ✅
  - ConstitucionMoraIndependientesProcessor (22 pasos) ✅
- Data sources disponibles (12 tipos)
- Validación y carga de archivos
- Steps del pipeline con detalles técnicos
- Issues conocidos y soluciones

**Recomendado para**: Desarrollo de pipelines, debugging de procesamiento, implementación de nuevos comunicados.

---

### 📌 [ESTADO_ACTUAL.md](./ESTADO_ACTUAL.md)
**Estado del Proyecto** - Snapshot actual del desarrollo (actualizado frecuentemente).

Incluye:
- Branch actual y working directory status
- Último trabajo completado
- Issues conocidos y solucionados
- Testing pendiente
- Próximos pasos recomendados
- Estado de base de datos
- Configuración actual
- Métricas de desarrollo
- Guía para retomar el trabajo

**Recomendado para**: Retomar trabajo después de pausa, entender qué está pendiente, ver progreso actual.

---

## 🚀 Quick Start

### Primera vez con el proyecto
1. Lee [CONTEXTO_GENERAL_PROYECTO.md](./CONTEXTO_GENERAL_PROYECTO.md) para entender la visión general
2. Revisa [CONTEXTO_INFRAESTRUCTURA_DOCKER.md](./CONTEXTO_INFRAESTRUCTURA_DOCKER.md) para levantar el entorno
3. Consulta comandos frecuentes en el documento general

### Trabajando en pipelines de comunicados
1. Lee [CONTEXTO_PIPELINE_COMUNICADOS.md](./CONTEXTO_PIPELINE_COMUNICADOS.md) sección completa
2. Revisa procesadores existentes como referencia
3. Consulta data sources y sus columnas
4. Implementa siguiendo patrones establecidos

### Debugging de problemas
1. Identifica el área: infraestructura, pipeline, o aplicación general
2. Consulta sección de Troubleshooting del documento correspondiente
3. Usa comandos de debugging proporcionados

---

## 📋 Guías Rápidas por Tarea

### Levantar el proyecto
```bash
# 1. Copiar variables de entorno
cp infra/.env.example infra/.env
# Editar infra/.env con valores correctos

# 2. Levantar servicios
docker-compose up -d

# 3. Instalar dependencias
docker-compose exec poarl-php composer install

# 4. Ejecutar migraciones
docker-compose exec poarl-php php artisan migrate

# 5. Ejecutar seeders
docker-compose exec poarl-php php artisan db:seed
```

Ver detalles completos en: [CONTEXTO_INFRAESTRUCTURA_DOCKER.md](./CONTEXTO_INFRAESTRUCTURA_DOCKER.md)

---

### Crear un nuevo tipo de comunicado

1. **Crear procesador**
   ```php
   // app/UseCases/Recaudo/Comunicados/Processors/MiNuevoProcessor.php
   class MiNuevoProcessor extends BaseCollectionNoticeProcessor
   {
       protected function defineSteps(): array {
           return [
               // ... tus steps
           ];
       }
   }
   ```

2. **Crear steps necesarios**
   ```php
   // app/UseCases/Recaudo/Comunicados/Steps/MiNuevoStep.php
   class MiNuevoStep extends BaseStep
   {
       public function execute(CollectionNoticeRun $run): void {
           // ... tu lógica
       }
   }
   ```

3. **Registrar en configuración**
   ```php
   // config/collection-notices.php
   'processors' => [
       'mi_nuevo_tipo' => MiNuevoProcessor::class,
   ],
   ```

4. **Crear seeder de data sources**
   ```php
   // database/seeders/NoticeDataSourceSeeder.php
   // Agregar tus data sources y relaciones
   ```

Ver detalles completos en: [CONTEXTO_PIPELINE_COMUNICADOS.md](./CONTEXTO_PIPELINE_COMUNICADOS.md)

---

### Debugging de un run fallido

```bash
# 1. Ver estado del run
docker-compose exec poarl-php php artisan tinker --execute="
\$run = \App\Models\CollectionNoticeRun::find({run_id});
dd(\$run->status, \$run->errors, \$run->metadata);
"

# 2. Ver logs de Laravel
docker-compose logs --tail=200 poarl-php | grep "ERROR"

# 3. Verificar datos cargados
docker-compose exec poarl-pgsql psql -U usuario -d db -c "
SELECT COUNT(*) FROM data_source_dettra WHERE run_id = {run_id};
"

# 4. Ver archivos cargados
docker-compose exec poarl-php php artisan tinker --execute="
\$run = \App\Models\CollectionNoticeRun::with('files.dataSource')->find({run_id});
foreach (\$run->files as \$file) {
    echo \$file->dataSource->code . ': ' . \$file->original_name . PHP_EOL;
}
"
```

Ver más comandos en: [CONTEXTO_GENERAL_PROYECTO.md](./CONTEXTO_GENERAL_PROYECTO.md) - Sección "Comandos Frecuentes"

---

## 🔍 Búsqueda Rápida

### Por tema

| Tema | Documento |
|------|-----------|
| Arquitectura general | [CONTEXTO_GENERAL_PROYECTO.md](./CONTEXTO_GENERAL_PROYECTO.md) |
| Estado actual del proyecto | [ESTADO_ACTUAL.md](./ESTADO_ACTUAL.md) |
| Docker / DevOps | [CONTEXTO_INFRAESTRUCTURA_DOCKER.md](./CONTEXTO_INFRAESTRUCTURA_DOCKER.md) |
| Pipelines de comunicados | [CONTEXTO_PIPELINE_COMUNICADOS.md](./CONTEXTO_PIPELINE_COMUNICADOS.md) |
| Tablas de base de datos | [CONTEXTO_PIPELINE_COMUNICADOS.md](./CONTEXTO_PIPELINE_COMUNICADOS.md#arquitectura-de-tablas) |
| Data sources | [CONTEXTO_PIPELINE_COMUNICADOS.md](./CONTEXTO_PIPELINE_COMUNICADOS.md#data-sources-disponibles) |
| Steps del pipeline | [CONTEXTO_PIPELINE_COMUNICADOS.md](./CONTEXTO_PIPELINE_COMUNICADOS.md#procesadores-existentes) |
| Comandos Docker | [CONTEXTO_INFRAESTRUCTURA_DOCKER.md](./CONTEXTO_INFRAESTRUCTURA_DOCKER.md#comandos-útiles) |
| Troubleshooting | Todos los documentos tienen sección dedicada |
| Stack tecnológico | [CONTEXTO_GENERAL_PROYECTO.md](./CONTEXTO_GENERAL_PROYECTO.md#stack-tecnológico) |
| Convenciones de código | [CONTEXTO_GENERAL_PROYECTO.md](./CONTEXTO_GENERAL_PROYECTO.md#convenciones-y-estándares) |
| Próximos pasos | [ESTADO_ACTUAL.md](./ESTADO_ACTUAL.md#próximos-pasos-recomendados) |
| Testing pendiente | [ESTADO_ACTUAL.md](./ESTADO_ACTUAL.md#testing-pendiente) |

---

## 📝 Contribuir a la Documentación

Esta documentación es un recurso vivo. Al hacer cambios significativos en el código:

1. **Actualiza el documento correspondiente**
   - Cambios en infraestructura → CONTEXTO_INFRAESTRUCTURA_DOCKER.md
   - Cambios en pipelines → CONTEXTO_PIPELINE_COMUNICADOS.md
   - Cambios generales → CONTEXTO_GENERAL_PROYECTO.md

2. **Agrega issues conocidos y soluciones**
   - Si encuentras un bug y lo solucionas, documéntalo
   - Incluye el error, la causa raíz y la solución
   - Ejemplo: Ver "Issues Conocidos" en CONTEXTO_PIPELINE_COMUNICADOS.md

3. **Actualiza la fecha de última modificación**
   - Al final de cada documento
   - Incluye breve descripción del cambio

4. **Mantén consistencia de formato**
   - Usa Markdown estándar
   - Sigue la estructura existente
   - Usa bloques de código con sintaxis highlighting

---

## 🏷️ Versionado de Documentación

| Versión | Fecha | Cambios Principales |
|---------|-------|---------------------|
| 1.0 | 2025-10-14 | Creación inicial de los 4 documentos principales |
| - | - | CONTEXTO_GENERAL_PROYECTO.md (visión general) |
| - | - | CONTEXTO_INFRAESTRUCTURA_DOCKER.md (DevOps) |
| - | - | CONTEXTO_PIPELINE_COMUNICADOS.md (sistema comunicados) |
| - | - | ESTADO_ACTUAL.md (snapshot del desarrollo) |
| - | - | README.md (índice de navegación) |

---

## ❓ FAQ

**P: ¿Dónde empiezo si soy nuevo en el proyecto?**
R: Lee [CONTEXTO_GENERAL_PROYECTO.md](./CONTEXTO_GENERAL_PROYECTO.md) de principio a fin.

**P: ¿Cómo levanto el entorno de desarrollo?**
R: Sigue la guía en [CONTEXTO_INFRAESTRUCTURA_DOCKER.md](./CONTEXTO_INFRAESTRUCTURA_DOCKER.md).

**P: ¿Cómo funciona el sistema de comunicados?**
R: Lee el flujo completo en [CONTEXTO_PIPELINE_COMUNICADOS.md](./CONTEXTO_PIPELINE_COMUNICADOS.md#flujo-completo-de-procesamiento).

**P: ¿Qué data sources existen?**
R: Consulta la tabla completa en [CONTEXTO_PIPELINE_COMUNICADOS.md](./CONTEXTO_PIPELINE_COMUNICADOS.md#data-sources-disponibles).

**P: ¿Cómo debugging un pipeline que falla?**
R: Usa los comandos de la sección "Debugging de Comunicados" en [CONTEXTO_GENERAL_PROYECTO.md](./CONTEXTO_GENERAL_PROYECTO.md#comandos-frecuentes).

**P: ¿Por qué se usan binarios Go para Excel?**
R: Lee la explicación en [CONTEXTO_PIPELINE_COMUNICADOS.md](./CONTEXTO_PIPELINE_COMUNICADOS.md#por-qué-go-en-lugar-de-php).

---

## 📞 Soporte

Para dudas o problemas:
1. Consulta la sección de Troubleshooting del documento relevante
2. Revisa los issues conocidos en CONTEXTO_PIPELINE_COMUNICADOS.md
3. Busca en los logs: `docker-compose logs -f poarl-php`

---

**Documentación actualizada**: 14 de Octubre, 2025
