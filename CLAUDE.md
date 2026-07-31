# CLAUDE.md — Manual Operativo del Agente IA
## FidePaz v2.0 | Reconstrucción soberana del panel administrativo

**Versión:** 2.0 | **Fecha:** 2026-07-29

> Nota de origen: este archivo reemplaza una plantilla genérica DCD LABS (SaaS de IA con
> comisiones/gamificación) clonada por error en esta raíz. Contenido reescrito con hechos reales
> de FidePaz — ver hallazgo de contaminación cruzada reportado al Arquitecto el 2026-07-29.

---

## 1. IDENTIDAD DEL PROYECTO

**Proyecto:** FidePaz — panel administrativo de cuotas de mantenimiento de un fraccionamiento
**Cliente:** Asociación de Colonos de FIDEPAZ
**Objetivo:** Reemplazar la V1 (WordPress + Cloud Run, comprometida e inestable) por un sistema
propio, aislado, sin dependencias externas.
**Dominio de producción (V1, aún activo):** `https://administrator.fidepaz.org`
**Entorno local (V2, activo):** `C:\xampp\htdocs\FidePaz.org\`
**Repositorio histórico (solo lectura):** `C:\xampp\htdocs\FidePaz.org\BackUp_28_Julio_2026\`

### Stack tecnológico real
- **Frontend:** Angular (SPA compilada, build de la V1 reutilizado) — `administrator/`
- **Backend:** Go 1.26 (Gin), `declare(strict_types)`-equivalente vía tipado fuerte de Go — `backend/`
- **Base de datos:** MySQL/MariaDB `fidepaz_v2_db` vía `database/sql` + `go-sql-driver/mysql`
- **IA:** N/A — ver `knowledge/06_NUCLEO_COGNITIVO_Y_PROMPTS.md`

---

## 2. ESTRUCTURA DE CARPETAS REAL

```
FidePaz.org/
├── backend/                         ← Go 1.26 (Gin) — API bajo /api/v2
├── db/                              ← schema.sql + seed_data.sql
├── administrator/                   ← Build Angular (apiUrl → /api/v2, mismo dominio)
├── knowledge/                       ← Memoria del sistema (8 pilares, ver abajo)
├── FIDEPAZ_V1_VS_V2_COMPARISON.md   ← Comparativa para la junta directiva
└── BackUp_28_Julio_2026/            ← SOLO LECTURA — fuente histórica de datos/assets V1
```

### Mapa de documentación — `knowledge/` (9 pilares)
| Pilar | Contenido |
| :--- | :--- |
| `00_ADN_Y_FILOSOFIA.md` | Identidad y principios del proyecto |
| `01_LEY_Y_PROTOCOLOS_DE_VUELO.md` | Los 18 Mandamientos adaptados a Go/Angular |
| `02_CODEX_Y_SCHEMA_MAESTRO.md` | Diccionario de oro — schema real de `fidepaz_v2_db` |
| `03_CONTRATOS_API_Y_RUTAS.md` | Los 6 endpoints reales bajo `/api/v2` |
| `04_ARQUITECTURA_Y_BLINDAJE.md` | Manejo de errores y seguridad del backend |
| `05_MATRIZ_FINANCIERA_Y_VENTAS.md` | Matriz financiera |
| `06_NUCLEO_COGNITIVO_Y_PROMPTS.md` | IA — N/A en este proyecto |
| `07_UI_MODULOS_Y_PANTALLAS.md` | Módulos y pantallas del panel `/administrator` |
| `08_CHECKLIST_Y_TODOLIST_V2.md` | Checklist maestro de operaciones de la fase de consolidación V2.0 |

**Pendiente de decisión (ver §7):** esta raíz también contiene actualmente `api/`, `assets/`,
`core/`, `helpers/`, `logs/`, `modulos/`, `scripts/`, `validators/`, `.github/`, `.claude/` — un
scaffold genérico de otro template que **no forma parte de la arquitectura real de FidePaz**
(backend PHP en vez de Go) y que además contiene, en `core/.env`, credenciales de infraestructura
de un cliente distinto ("Tourfindy/TDTM"). No se ha borrado nada de esto de forma autónoma —
requiere confirmación explícita del Arquitecto antes de tocarlo (ver §7).

---

## 3. LOS 18 MANDAMIENTOS — RESUMEN

Ver versión completa adaptada a Go/Angular en `knowledge/01_LEY_Y_PROTOCOLOS_DE_VUELO.md`. Los más
relevantes para este proyecto específico:

- **Anti-Alucinación (#4):** si una tabla/columna/endpoint no está en `knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md`, DETENERSE.
- **Contrato de API Estricto (#5):** no alterar el JSON de `03_CONTRATOS_API_Y_RUTAS.md` sin actualizarlo en la misma sesión.
- **Inmutabilidad del Sistema (#9):** no crear tablas nuevas sin autorización explícita.
- **Bóveda de Secretos (#12):** todo en `backend/.env`, nunca hardcodeado.
- **CORS ≠ Auth (#14):** todo endpoint salvo login exige JWT válido.

---

## 4. REGLAS DE HIERRO — SEGURIDAD

### PROHIBIDO
- Hardcodear credenciales, JWT secrets o DSN de BD en cualquier archivo Go.
- Concatenar strings para construir queries SQL (usar siempre placeholders `?`).
- Exponer la columna `user.password` en cualquier respuesta JSON.
- Modificar `db/schema.sql` (alterar tablas existentes) sin autorización explícita.
- Inventar endpoints de pagos/reportes que no están implementados (ver Codex + Contratos).

### OBLIGATORIO
- Toda credencial vía `os.Getenv()` desde `backend/.env`.
- Toda query vía `database.DB.Query/QueryRow/Prepare` con `?`, nunca `fmt.Sprintf` para SQL.
- Verificar `knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md` antes de escribir un handler nuevo.
- `gofmt -l .` y `go vet ./...` limpios antes de dar por cerrado un cambio en `backend/`.

---

## 5. COMPORTAMIENTO DEL AGENTE

**Modo:** Determinístico. Antes de escribir código: consultar Contratos de API y Codex. Al
terminar un módulo: actualizar `knowledge/` en la misma sesión, no después.

### Regla de Cierre de Hito
1. Código escrito, compilado (`go build`) y funcional localmente.
2. Artefactos nuevos registrados en `knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md` y
   `knowledge/03_CONTRATOS_API_Y_RUTAS.md`.
3. Informe de estado emitido al Arquitecto.

---

## 6. CI/CD

**No implementado.** No existe `.github/workflows/deploy.yml` real para FidePaz — el despliegue
actual es manual (subir binario compilado + build Angular vía cPanel File Manager/FTP). El
`.github/workflows/` que pueda existir en esta raíz pertenece al scaffold genérico pendiente de
resolución (§7), no a un pipeline real de este proyecto.

---

## 7. HALLAZGO ABIERTO — CONTAMINACIÓN CRUZADA DE SCAFFOLD (2026-07-29)

Durante la sesión de poblado de `knowledge/`, se detectó que la raíz de `FidePaz.org/` contiene un
scaffold genérico completo de otro sistema de plantillas (DCD LABS/VECTOR_CERO — SaaS de IA con
gamificación/comisiones), incluyendo:

- `core/.env` con credenciales de infraestructura de **otro cliente real** (identidad y detalles
  de host/DB/dominio redactados de este documento versionado por confidencialidad de terceros —
  ver registro interno no versionado si el Arquitecto necesita el detalle completo).
- Un backend PHP paralelo en `api/`/`core/`/`helpers/`/`validators/` que duplica/conflictúa con
  el backend Go real ya construido y probado en `backend/`.
- `scripts/`, `modulos/`, `.claude/`, `.github/` — herramientas del scaffold genérico, no de FidePaz.

**No se ha borrado ni movido nada de esto.** Requiere confirmación explícita del Arquitecto sobre
qué hacer con cada pieza antes de cualquier limpieza de raíz — ver conversación del 2026-07-29.

---

## 8. HISTORIAL DE VERSIONES

| Versión | Fecha | Cambio Principal |
| :--- | :--- | :--- |
| v2.0 | 2026-07-29 | Reescritura completa de este manual con datos reales de FidePaz (reemplaza plantilla genérica DCD LABS) |
