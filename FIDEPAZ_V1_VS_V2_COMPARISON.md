# FidePaz — Comparativa técnica V1 vs V2.0 Soberano

**Preparado para:** Junta directiva y dueños del proyecto
**Fecha de auditoría:** julio 2026
**Ubicación del proyecto limpio:** `C:\xampp\htdocs\FidePaz.org\` (raíz)
**Ubicación del respaldo obsoleto:** `C:\xampp\htdocs\FidePaz.org\BackUp_28_Julio_2026\` — **solo lectura**, usado únicamente como fuente de datos (`mercagee_colonoscore.sql`) y assets. No se escribió nada nuevo ahí.

---

## A) Matriz comparativa

| Dimensión | V1 (actual, cPanel GreenGeeks) | V2.0 Soberano (Go 1.26 + Angular + MySQL) |
|---|---|---|
| **Ubicación / origen** | Mezclado dentro de `BackUp_28_Julio_2026\fidepaz\` (WordPress + Angular + basura) | Proyecto limpio, aislado, en la raíz `FidePaz.org\` — separación total del legado |
| **Backend** | Node.js en Google Cloud Run (código fuente no disponible) + un WordPress vacío sin usar en `/api` | **Go 1.26** (Gin), binario único compilado, sin runtime externo que instalar |
| **Archivos en servidor** | **28,992 archivos**, ~6.3 GB (WordPress + WooCommerce + 2 plugins de gestión de archivos + temas sin usar) | **< 50 archivos** en `backend/` + build Angular en `administrator/` — sin núcleo CMS expuesto |
| **Memoria en ejecución** | N/A directamente comparable (proceso PHP-FPM/Apache + Node en Cloud Run, escalado gestionado por Google) | Un binario Go compilado consume típicamente **10-15 MB de RAM en reposo** (goroutines + runtime ligero); cifra a confirmar con medición real en el piloto, no una garantía contractual |
| **Cuentas administrativas** | 9 cuentas en `wp_users`, **7 con patrón de backdoor** confirmado en auditoría forense previa | **Cero cuentas de WordPress.** Autenticación solo contra los 218 colonos reales de `fidepaz_v2_db.user` — esa base nunca importa `mercagee_colonos` |
| **Dependencia de red externa** | Login dependía de `colonos-core-573053143459.us-central1.run.app` — sujeto a bloqueo de firewall saliente de GreenGeeks, cold-starts y CORS cross-origin | Backend y frontend en el mismo servidor/dominio. Cero llamadas HTTP salientes para servir el login o las consultas |
| **Incidentes observados en V1** | 400 Bad Request (CORS/GFE) → 504 Gateway Timeout (`curl_errno 28`, egress bloqueado) — 3 rondas de parches tácticos antes de decidir la reconstrucción | No reproducibles: sin proxy externo que pueda fallar |
| **Base de datos** | `mercagee_colonoscore`: `latin1` declarado (bytes reales UTF-8 mal etiquetados), `DOUBLE` para dinero, sin índices más allá de PK/FK | `fidepaz_v2_db`: `utf8mb4` real, `DECIMAL(10,2)` para montos, 5 índices nuevos en `user_quotas` (10,758 filas) para las consultas reales del panel |
| **Consultas SQL** | Desconocido (código fuente de `colonos-core` no disponible) | 100% `Prepared Statements` con placeholders `?` vía `database/sql` + `go-sql-driver/mysql` — cero concatenación de strings en queries |
| **Autenticación** | JWT emitido por Node/NestJS externo | JWT HS256 propio (`golang-jwt/jwt/v5`), expiración de **12 horas**, verificación de bcrypt nativa (`golang.org/x/crypto/bcrypt`) compatible con los hashes `$2b$10$...` ya existentes — sin necesidad de resetear contraseñas |
| **CORS** | Bloqueado (el incidente que originó todo este proyecto) | Whitelist estricta: `https://administrator.fidepaz.org` + `http://localhost:4200` (dev). Nunca `*` con credenciales |
| **Rate limiting** | No existía | Limitador en memoria por IP en `/api/v2/auth/login` (10 intentos / 5 min) |
| **Rendimiento esperado** | Latencia de red externa + cold-start de Cloud Run + timeouts de 10-30s observados en fallas reales | Consulta local indexada; Go compilado es de los runtimes HTTP más rápidos disponibles. **"Sub-10ms" es una expectativa de diseño para consultas locales indexadas sin carga concurrente real — debe medirse con benchmark (`hey`/`wrk`) en el piloto antes de presentarse como cifra garantizada a la junta** |

---

## B) Plan de desarrollo local y migración sin afectación al usuario

### Fase 0 — Completado en este entregable
- [x] Backend Go 1.26 funcional en `backend/` (Gin, JWT 12h, bcrypt, prepared statements, CORS whitelist, rate limit).
- [x] `db/schema.sql` + `db/seed_data.sql` — mismo esquema sanitizado ya validado (218 colonos, 198 propiedades, 10,758 pagos), copiado desde el respaldo sin modificarlo ahí.
- [x] `administrator/` — build Angular apuntando a `/api/v2` en el mismo dominio.

### Fase 1 — Validación local
1. `cd backend && go mod tidy && go build -o fidepaz-backend.exe .`
2. Crear `backend/.env` desde `.env.example` con credenciales de un usuario MySQL local **sin privilegios de root**, limitado a `fidepaz_v2_db`.
3. Importar `db/schema.sql` y `db/seed_data.sql` en el MySQL de XAMPP.
4. Ejecutar el binario y probar `/api/v2/auth/login` con un usuario real antes de tocar producción.
5. Medir tiempos de respuesta reales bajo carga simulada (`hey -n 1000 -c 20 ...`) para reemplazar la cifra "sub-10ms" por un dato medido.

### Fase 2 — Pendiente de especificación (no fabricado deliberadamente)
Los endpoints de pagos/reportes (`/payment/get-file`, `/payment/download-report`, `/payment/download-report-state`) referenciados en el bundle Angular **no tienen lógica de negocio disponible** en este backup (vivían solo en el código fuente no incluido de `colonos-core`). Se requiere:
- Acceso al repositorio original, o
- Re-especificación del cálculo de cuotas y generación de recibos con el negocio, antes de implementarlos en Go.

### Fase 3 — Corte a producción
1. **Decisión de despliegue pendiente de confirmar con el hosting**: GreenGeeks (cPanel compartido) típicamente no soporta ejecutar binarios Go persistentes de forma nativa (a diferencia de Node vía "Setup Node.js App"). Antes de comprometer esta arquitectura en producción, confirmar con soporte si existe *Application Manager*/Passenger para binarios genéricos, o si se requiere migrar a un VPS pequeño para alojar `backend/`. **Esto es un riesgo abierto, no un detalle menor.**
2. Si el binario corre en un puerto local (ej. `:8080`), Apache/cPanel necesita reenviar `/api/v2/*` a ese puerto — de nuevo sujeto a las mismas limitaciones de `mod_proxy` en `.htaccess` ya documentadas en iteraciones previas de este proyecto.
3. Ventana corta de corte anunciada (pagos/altas congeladas ~30 min) para la sincronización final de `user_quotas`.
4. Validar login y consultas con usuarios reales antes de apagar V1.
5. Como proyecto de seguridad **independiente y urgente** (no bloqueante para V2.0): purgar las 7 cuentas backdoor de `wp_users`, desinstalar los gestores de archivos vulnerables, rotar credenciales de `mercagee_colonos`.

### Riesgos abiertos a comunicar a la junta
1. **Compatibilidad de hosting**: la arquitectura Go requiere confirmar soporte de proceso persistente en GreenGeeks o presupuestar un VPS.
2. **Paridad funcional incompleta**: pagos/reportes/PDF quedan en Fase 2, sin fecha, hasta conseguir la especificación real.
3. **Cifras de rendimiento** ("sub-10ms", "<15MB RAM") son objetivos de diseño razonables para esta arquitectura, no mediciones de carga con usuarios concurrentes reales — se recomienda no presentarlas como compromiso contractual sin el benchmark de la Fase 1.
4. **Incidente de seguridad de WordPress** (7 cuentas backdoor) sigue activo en V1 y es independiente de esta migración — no debe esperar a que V2.0 esté en producción.
