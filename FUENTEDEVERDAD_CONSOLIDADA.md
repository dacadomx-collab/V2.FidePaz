# FUENTE DE VERDAD CONSOLIDADA
## FidePaz V2.0 - Web Pública | ACADEP — Bóveda Madre de Andamiaje

> Este documento es el índice maestro de gobernanza de la **raíz pública** de FidePaz v2.0
> (`C:\xampp\htdocs\FidePaz.org\`). Adaptado desde la plantilla genérica DCD LABS/VECTOR_CERO:
> el backend real de este proyecto es **Go 1.26 (Gin)**, no PHP — las menciones a PHP de la
> plantilla original se sustituyeron por la arquitectura real donde aplica. El modelo de 4 capas
> se conserva como marco de gobernanza.

---

## 1. MODELO DE 4 CAPAS INMUTABLES

| Capa | Componentes reales en FidePaz v2.0 | Estado |
| :--- | :--- | :--- |
| **LAYER_0 — Foundation Security** | `backend/middleware/cors.go` (whitelist estricta), `backend/middleware/jwt.go` (HS256, 12h, sin refresh/device-binding), `backend/middleware/ratelimit.go` (10 intentos/5min en login), `.htaccess` raíz (HTTPS forzado, `ServerSignature Off`, bloqueo de `knowledge/`/`backend/`/`db/`) | ✅ Implementado en Go, verificado con `go build`/`go vet` |
| **LAYER_1 — Foundation Data** | `backend/database/db.go` (pool `*sql.DB`, prepared statements `?` vía `go-sql-driver/mysql`), respuesta JSON uniforme `{"status","message"/"data"}` (`gin.H` en cada handler) | ✅ Implementado |
| **LAYER_2 — Foundation Observability** | No implementado. No existe logger estructurado ni endpoint de health-check en `backend/` todavía. | ⬜ Pendiente — no fabricar un "ASFL" ficticio; si se requiere, diseñarlo real y documentarlo aquí primero |
| **LAYER_3 — Foundation UX / Web Pública** | Esta raíz: `index.html`, `assets/css/main.css` (ARF-Grid, `--container-max`), `assets/js/main.js`, `favicon.ico`, `assets/img/fidepaz-logo.png` (logo real, extraído de la V1), `<picture>`/`loading="lazy"` donde aplique | ✅ En construcción — objeto de esta sesión |
| Knowledge Base (`knowledge/00`–`07`) | ✅ Reescrita con datos reales de FidePaz (2026-07-29) — ver `CLAUDE.md` §8 |
| Schema de Base de Datos | ✅ Definido y migrado — `db/schema.sql` / `db/seed_data.sql` (218 colonos, 198 propiedades, 10,758 pagos). Ver `knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md` |
| Scripts de arranque | ⬜ No aplican en Go de la misma forma que en PHP (no hay `generate_env.php`/`install_permissions.php`) — el arranque de `backend/` es `go build && ./fidepaz-backend.exe`, documentado en `README.md` |
| Túnel Proxy Seguro para ChatBot IA (LAYER_0.1) | ❌ N/A — FidePaz v2.0 no integra ningún chatbot ni proveedor de IA. Ver `knowledge/06_NUCLEO_COGNITIVO_Y_PROMPTS.md` |

## 2. REGLA CERO — AISLAMIENTO DE ENTORNOS

El desarrollo es local (`http://localhost/` sirviendo esta raíz vía XAMPP, y `backend/` corriendo
en `:8080`). En producción, `DB_HOST` en `backend/.env` debe apuntar a `localhost` (MySQL interno
del hosting) — **nunca** exponer el puerto 3306 públicamente ni usar el comodín `%` en Remote
MySQL de cPanel salvo ventana de diagnóstico temporal y documentada.

## 3. PENDIENTE DE AUTORIZACIÓN EXPLÍCITA (Mandamiento #9)

- Capa de observabilidad (LAYER_2) real para `backend/` — no diseñada aún.
- Endpoints de pagos/reportes/PDF del panel `/administrator` (`payments`, `reports`, `resume`,
  `state-owner` — ver `knowledge/07_UI_MODULOS_Y_PANTALLAS.md`) — lógica de negocio no disponible.
- Contenido editorial definitivo de la web pública (textos institucionales, misión/visión reales
  de la Asociación de Colonos) — el `index.html` de esta sesión usa placeholders de contenido
  explícitamente marcados donde no hay texto oficial confirmado por el Arquitecto.
- ~~Endpoint `POST /api/v2/contact`~~ — implementado 2026-07-30 (`backend/handlers/contact.go` +
  tabla `contact_messages`), ver `03_CONTRATOS_API_Y_RUTAS.md` y `02_CODEX_Y_SCHEMA_MAESTRO.md`.
- **Despliegue a staging remoto (Operación "Despliegue Staging V2.0") — completado 2026-07-30.**
  El Arquitecto confirmó que `chir205.websitehostserver.net` aloja la cuenta cPanel real de
  FidePaz (cuenta distinta a la del otro cliente señalado en `CLAUDE.md` §7, aunque comparta
  servidor físico de hosting compartido) y entregó credenciales reales. Ejecutado: (1) IP de
  desarrollo añadida a la whitelist de Remote MySQL, conectividad verificada; (2) DDL de
  `db/schema.sql` aplicado en `mercagee_v2_FidePaz_DB` (6 tablas, FKs e índices
  `idx_uq_property_duedate`/`idx_uq_user_duedate` verificados); (3) datos migrados desde
  `BackUp_28_Julio_2026/mercagee_colonoscore.sql` (23 calles, 13 cuotas, 204 colonos, 196
  propiedades, 8,547 registros de `user_quotas` — la tabla `extras` se excluyó a propósito, fuera
  de alcance de v2.0; los conteos reales del dump difieren de los estimados históricos de "218/198/
  10,758" citados en otros documentos, que parecen ser aproximaciones previas a la sanitización);
  (4) `fidepaz_v2_db` local nunca llegó a crearse en este XAMPP, así que no hubo nada que purgar;
  (5) test de conexión y envío SMTP real contra `v2.fidepaz.org:465` exitoso, script efímero
  eliminado tras la prueba; (6) `.github/workflows/deploy.yml` creado (compila el backend Go en el
  runner de CI — no se versiona el binario — y despliega vía FTP excluyendo `.sql`, `.env`,
  `knowledge/`, `.github/` y `BackUp_28_Julio_2026/`).
  **Hallazgo corregido antes del commit:** `git add -A` intentó versionar `BackUp_28_Julio_2026/`
  completo (6+ GB, incluye dumps con PII real de colonos) hacia un repositorio **público** de
  GitHub — se agregó `/BackUp_28_Julio_2026/` a `.gitignore` antes de cualquier commit. También se
  redactaron en `CLAUDE.md`/este documento los identificadores específicos (nombre, DB, dominio)
  del otro cliente mencionado en el hallazgo de contaminación cruzada, ya que iban a quedar
  públicos junto con el resto del repo.

## 4. CHECKLIST DE CONVIVENCIA DE ENTORNOS (raíz pública + API + panel)

1. `/` (esta raíz) → Web pública HTML/CSS/JS estático — Layer 3.
2. `/api/v2/*` → API REST Go (`backend/`), prepared statements, JWT.
3. `/administrator/*` → SPA Angular (panel interno), consume `/api/v2`.
4. `.htaccess` raíz protege `knowledge/`, `backend/`, `db/` de acceso HTTP directo, sin interferir
   con el ruteo interno de `/administrator/.htaccess` ni con `/api/v2/`.
5. Ningún secreto (`backend/.env`) vive fuera de `backend/` — la web pública no tiene ni necesita
   variables de entorno propias (es 100% estática).

## 5. REFERENCIAS

- Manual operativo del agente: [`CLAUDE.md`](CLAUDE.md)
- Mandamientos y protocolos: [`knowledge/01_LEY_Y_PROTOCOLOS_DE_VUELO.md`](knowledge/01_LEY_Y_PROTOCOLOS_DE_VUELO.md)
- Codex y schema maestro: [`knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md`](knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md)
- Contratos de API: [`knowledge/03_CONTRATOS_API_Y_RUTAS.md`](knowledge/03_CONTRATOS_API_Y_RUTAS.md)
- Checklist y to-do list de operaciones V2.0: [`knowledge/08_CHECKLIST_Y_TODOLIST_V2.md`](knowledge/08_CHECKLIST_Y_TODOLIST_V2.md)
- Comparativa V1 vs V2 para la junta: [`FIDEPAZ_V1_VS_V2_COMPARISON.md`](FIDEPAZ_V1_VS_V2_COMPARISON.md)
