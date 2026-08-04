# 📊 INFORME EJECUTIVO DE AUDITORÍA Y MIGRACIÓN: FIDEPAZ V1 VS V2.0

**Fecha de actualización:** 4 de agosto de 2026
**Autor:** Dirección Técnica & Core Architecture
**Estado:** 🔴 V1 dada de baja (Zona Contaminada) · 🟡 V2 en despliegue — bloqueador de infraestructura activo

> **Nota metodológica:** este informe solo reporta cifras verificadas contra el código, los
> dumps SQL y pruebas en vivo. Donde no existe una medición real (p. ej. tiempos de respuesta
> de endpoints que aún no están corriendo, uso de RAM del proceso en el servidor), se marca
> explícitamente como **pendiente de benchmark** en vez de estimarse — no se reportan cifras de
> rendimiento inventadas a directivos ni clientes.

---

## 🚀 1. RESUMEN EJECUTIVO

FidePaz está migrando de un sistema V1 monolítico (WordPress + WooCommerce + un backend Node en
Google Cloud Run) — hoy totalmente comprometido por malware y dado de baja — hacia una
arquitectura propia V2.0 en Go 1.26 + Angular, con una base de datos reducida a solo las tablas
de negocio reales. La base de datos y el frontend de V2 ya están migrados y desplegados; el
**backend Go aún no queda accesible en producción** porque el pipeline de despliegue sube el
binario compilado pero no lo ejecuta en el servidor — es el único bloqueador identificado antes
de declarar V2 operativa.

---

## 📈 2. TABLA COMPARATIVA DE MÉTRICAS CLAVE (VERIFICADAS)

| Criterio / Métrica | Sistema V1 (Legacy WordPress) | Sistema V2.0 (Go + Angular) | Impacto / Mejora |
| :--- | :--- | :--- | :--- |
| **Tamaño de dump de BD** | 30.7 MB (`mercagee_colonos`, WordPress/WooCommerce) | 955 KB (`mercagee_colonoscore` → `fidepaz_v2_db`) | **-96.9% de peso** |
| **Tablas en base de datos** | 53 tablas (WordPress/WooCommerce/plugins) | 6 tablas de negocio (`street`, `quota`, `user`, `property`, `user_quotas`, `contact_messages`) | **-88.7% de complejidad** |
| **Tecnología backend** | PHP/WordPress monolítico + proxy a Node/Cloud Run | Go 1.26 + Gin, binario único compilado | Superficie de ataque drásticamente menor |
| **Cuentas admin comprometidas** | 8 de 9 usuarios con patrón de malware/backdoor confirmado (88.8%) | 0 — JWT HS256 + hashing de contraseña, altas controladas | **100% del perímetro de acceso saneado** |
| **Endpoints expuestos** | Superficie amplia de WordPress/WooCommerce + plugins de terceros | 8 endpoints explícitos bajo `/api/v2`, sin superficie adicional | Superficie mínima y auditable |
| **Tiempo de respuesta API** | No medido formalmente (reportado como lento e inestable, ver §3) | ⏳ **Pendiente de benchmark** — el backend aún no está accesible en producción (ver §4) | No reportable aún |
| **Consumo de memoria en servidor** | No medido | ⏳ **Pendiente de benchmark** — requiere el proceso corriendo en el servidor de destino | No reportable aún |
| **Disponibilidad del frontend estático V2** | N/A | ✅ HTTP 200 confirmado en `https://v2.fidepaz.org/` (prueba en vivo, 2026-08-04) | Verificado |
| **Disponibilidad del API V2** | N/A | ❌ HTTP 404 en `https://v2.fidepaz.org/api/v2` (prueba en vivo, 2026-08-04) | Bloqueador activo, ver §4 |

---

## 🛡️ 3. REPORTE TÁCTICO DE CIBERSEGURIDAD — HALLAZGOS EN V1

Auditoría de la tabla `wp_users` de `mercagee_colonos` (dump del 28 de julio de 2026): de 9
cuentas totales, **8 presentan un patrón de cuenta backdoor/malware**, dejando **1 sola cuenta
legítima** (`acadep`, ID 1).

| ID | Usuario | Email | Patrón detectado |
| :-- | :--- | :--- | :--- |
| 2 | `bot` | bot@local.invalid | Bot inyectado |
| 3 | `admlnlx` | wordpresupport@fidepaz.org | Soporte falso (typosquat de "admin") |
| 4 | `admins` | admins@fexpost.com | Acceso externo no autorizado |
| 5 | `adminbackup` | adminbackup@wordpress.org | Falso plugin de respaldo |
| 6 | `mono81745` | mono81745@fidepaz.org | Shell persistente |
| 7 | `orchestrator_admin_1295` | orchestrator_admin_1295@support.com | Inyección de orquestador |
| 8 | `adm_4cc14c` | adm_4cc14c@fidepaz.org | Administrador clon |
| 9 | `ninja_236` | admin@\<hash\>.local | Webshell activa |

**Decisión:** no se invierte tiempo en desinfectar la V1 — el costo de auditar y limpiar un
WordPress con 88.8% del acceso admin comprometido, plugins de terceros y un dump de 30.7 MB
supera ampliamente el de completar la V2, que ya tiene la base de datos real migrada y
verificada. **La V1 queda formalmente dada de baja** y clasificada como zona contaminada; no se
reactivará el backend de Cloud Run ni se restaurará acceso público a `administrator.fidepaz.org`.

---

## ⚡ 4. ARQUITECTURA FIDEPAZ V2.0 Y BLOQUEADOR ACTIVO

**Pila:** backend Go 1.26 (Gin) compilado a binario único, frontend Angular (SPA estática),
MariaDB (`fidepaz_v2_db`, 6 tablas). Seguridad activa: 100% de las queries vía `database/sql`
con placeholders (`?`), JWT con expiración de 12h, rate limiting, CORS restringido a
`v2.fidepaz.org` / `administrator.fidepaz.org` / `localhost:4200`, credenciales solo vía
`backend/.env` (nunca hardcodeadas).

**🔴 Hallazgo crítico de despliegue (2026-08-04):** el workflow `.github/workflows/deploy.yml`
compila `fidepaz-backend` para Linux y lo sube por FTP a `chir205.websitehostserver.net`, pero
**no existe ningún paso que lo ejecute en el servidor**. El hosting sirve el frontend estático
vía Apache/cPanel (`administrator/.htaccess` responde `200` en `/`), pero no hay proceso Go
corriendo ni una regla de proxy reverso hacia él, por lo que `/api/v2` devuelve `404` (el propio
`404 Not Found` de Apache, no una respuesta del backend). Adicionalmente, `.htaccess` conserva
reglas heredadas de una arquitectura PHP previa (`administrator/api/index.php`) que ya no existe
en el repo actual.

**Pendiente de decisión:** para dejar el backend accesible se necesita alguna de estas rutas:
1. Acceso SSH al hosting cPanel para arrancar el binario como proceso persistente
   (systemd/supervisor/`nohup` + `@reboot` en cron), más una regla de `ProxyPass` en Apache hacia
   `localhost:8080`, o
2. Mover el backend Go a una plataforma que sostenga procesos persistentes de forma nativa (VPS
   pequeño, Railway, Fly.io, etc.), dejando el frontend estático en cPanel y apuntando el
   `apiUrl` de Angular a esa nueva URL.

---

## 📦 5. INTEGRIDAD Y RESCATE DE DATOS (MIGRACIÓN)

- **Residentes/colonos migrados:** 218 cuentas.
- **Propiedades registradas:** 198 propiedades.
- **Historial de cuotas/pagos preservado:** 10,758 registros.
- **Fuente:** `mercagee_colonoscore` (955 KB) → `fidepaz_v2_db`, excluyendo la tabla `extras`.
- **Verificación pendiente (checklist):** confirmación explícita de FKs entre `user_quotas` /
  `property` / `user`, y `EXPLAIN` de las queries de cuotas sobre los 5 índices ya creados.

---

## 📅 6. BITÁCORA DE AVANCE Y PRÓXIMOS PASOS

| Fecha | Hito | Estado |
| :--- | :--- | :--- |
| 2026-07-29 | Documentación de knowledge/ (9 pilares) y hallazgo de contaminación de scaffold | ✅ Completado |
| 2026-07-30 | Release de staging V2.0 (BD remota, SMTP, API de contacto, CI/CD FTP) | ✅ Completado |
| 2026-08-04 | Corrección de bug `apiUrl` embebido (apuntaba a dominio de V1) | ✅ Completado y desplegado |
| 2026-08-04 | Auditoría `wp_users` V1: 8/9 cuentas backdoor confirmadas | ✅ Completado — V1 dada de baja |
| 2026-08-04 | Verificación en vivo de endpoints V2 (`curl`) | ⚠️ Frontend OK, API en 404 — bloqueador de infraestructura documentado en §4 |
| Pendiente | Resolver ejecución persistente del backend Go en el servidor | ⏳ Requiere decisión de infraestructura (§4) |
| Pendiente | Verificación de FKs/índices y cierre de checklist de BD | ⏳ No iniciado |
| Pendiente | Retiro seguro de `mercagee_colonos` y `mercagee_colonoscore` del hosting legado | ⏳ No iniciado |
