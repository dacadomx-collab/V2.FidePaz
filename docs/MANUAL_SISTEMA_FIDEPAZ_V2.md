# MANUAL MAESTRO DEL SISTEMA — FidePaz V2.0

**Versión:** 2.0 | **Fecha:** 2026-08-13 | **Estado:** Documento vivo — actualizar en la misma
sesión en que cambie el código (Regla de Cierre de Hito, `CLAUDE.md` §5)

> Este manual consolida, en un solo lugar, lo que ya vive repartido entre `knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md`
> (diccionario de datos) y `knowledge/03_CONTRATOS_API_Y_RUTAS.md` (contrato de API) — ambos siguen
> siendo la fuente de verdad más granular; este documento es la vista consolidada para lectura
> humana. Si algo aquí contradice a esos dos archivos, ellos ganan.

---

## Índice

1. [Arquitectura y Stack Tecnológico](#1-arquitectura-y-stack-tecnológico)
2. [Reglas de Oro de Desarrollo](#2-reglas-de-oro-de-desarrollo)
3. [Diccionario de Datos Completo](#3-diccionario-de-datos-completo)
4. [Catálogo de Endpoints REST API v2](#4-catálogo-de-endpoints-rest-api-v2)
5. [Guía de Operación Administrativa](#5-guía-de-operación-administrativa)
6. [Guía del Colono](#6-guía-del-colono)

---

## 1. Arquitectura y Stack Tecnológico

### 1.1 Componentes reales

| Capa | Tecnología | Ubicación |
| :--- | :--- | :--- |
| Web pública | HTML/CSS/JS estático, sin build | raíz del repo (`index.html`, `assets/`) |
| Panel administrativo/colono | HTML/JS nativo (ES modules), sin framework, sin build | `panel/*.html` + `panel/js/` |
| API REST | **PHP 8.2+**, sin Composer, front controller único | `api/v2/index.php` + `api/v2/routes/*.php` |
| Base de datos | MySQL/MariaDB, `utf8mb4_unicode_ci`, 100% Prepared Statements | `mercagee_v2_FidePaz_DB` (remoto/staging) |
| Automatización | Scripts CLI para cron de cPanel | `api/v2/cli/*.php` |

**Nota histórica importante:** el backend originalmente se construyó en **Go 1.26 (Gin)**
(`backend/`), documentado como "el backend real" en versiones anteriores de este proyecto. Ese
binario está **archivado** — no se expone en este hosting compartido (cPanel sin soporte nativo
para procesos Go persistentes) — y el **backend real en producción hoy es el fallback PHP**
(`api/v2/`). Cualquier documento que diga "Go es el backend real" está desactualizado; verificar
siempre contra `knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md` §"VARIABLES DE ENTORNO" antes de asumir.

De la misma forma, el panel administrativo **no** vive en `/administrator/` (build Angular de la
V1, retirado como punto de entrada en las Fases 8 y 9 — sigue en disco por seguridad del pipeline
de FTP, pero 100% inalcanzable por HTTP, cualquier ruta bajo `/administrator/` redirige a
`/panel/`). El panel real es **`/panel/*.html`**, nativo, sin build.

### 1.2 Flujo de una petición típica

```
Navegador (panel/*.html)
   │  fetch() vía panel/js/api.js (adjunta "Authorization: Bearer <JWT>")
   ▼
api/v2/index.php (front controller único)
   │  Cors::apply() → strip de prefijo "/api/v2" → switch de rutas
   ▼
api/v2/routes/*.php (un archivo por dominio: auth, properties, quotas, users,
   contact, announcements, dashboard, admin, messages, caja)
   │  Auth::requireUser() [JWT] → Auth::requireRole() [según el endpoint]
   │  Database::connection()->prepare(...)->execute([...])  ← "?" siempre, nunca concatenación
   ▼
MySQL (mercagee_v2_FidePaz_DB)
```

### 1.3 Entornos

| Entorno | Dominio / ruta | DB |
| :--- | :--- | :--- |
| Producción/Staging | `https://v2.fidepaz.org` | `mercagee_v2_FidePaz_DB` @ `chir205.websitehostserver.net` |
| Local (XAMPP) | `http://localhost/FidePaz.org/` (o `http://fidepaz.local/` si se activó el vhost, ver `httpd-vhosts.conf`) | `fidepaz_v2_db` |

El backend tolera montarse en la raíz del dominio (producción) **o** como subcarpeta (XAMPP local)
— el strip de prefijo en `api/v2/index.php` y las reglas de `administrator/.htaccess` manejan
ambos casos sin divergencia de comportamiento.

---

## 2. Reglas de Oro de Desarrollo

1. **Estilos centralizados.** `assets/css/main.css` define la paleta oficial (variables CSS,
   ver tabla abajo) y los estilos del sitio público; `panel/panel.css` extiende esos mismos
   tokens para componentes exclusivos del panel (`.panel-badge`, `.panel-chip`, `.panel-modal`,
   etc.) — nunca redefine la paleta. **Cero `style="..."` inline, cero `!important`.**
2. **ARF-Grid.** Layouts con `display: flex`, `flex-wrap: wrap`, sin anchos fijos en `px` para
   contenedores responsivos — ver `.arf-grid`/`.arf-col-*` en `main.css`.
3. **Nomenclatura.** `snake_case` en base de datos y backend PHP; `camelCase` en JS/frontend.
   Excepción documentada: `numOficial`, `createAt`, `updateAt`, `deleteAt` en `user`/`property`
   se conservan en camelCase por ser datos heredados 1:1 de la V1 (ver Codex §"EXCEPCIÓN DE
   NAMING").
4. **Seguridad Zero-Trust.**
   - 100% Prepared Statements (`?`) — nunca `fmt.Sprintf`/concatenación para SQL.
   - Contraseñas en BCrypt (`$2b$10$...`/`$2y$10$...`, compatibles entre sí).
   - JWT HS256, verificación de rol estricta en **cada** endpoint protegido vía
     `Auth::requireRole($claims, [...])` — nunca solo del lado del cliente (ocultar un botón/link
     no sustituye el control del servidor).
   - Todo secreto vía `api/v2/.env` (`Env::get()`/`Env::required()`), nunca hardcodeado.
5. **Anti-alucinación.** Si una tabla/columna/endpoint no está en
   `knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md` o `knowledge/03_CONTRATOS_API_Y_RUTAS.md`, no se
   inventa — se audita primero.
6. **Inmutabilidad del sistema.** No se crean tablas ni se altera el schema de tablas existentes
   sin autorización explícita del Arquitecto — aditivo siempre (columnas nuevas con `DEFAULT`,
   nunca se borran/renombran columnas con datos reales sin ventana de migración documentada).

### Paleta oficial (`assets/css/main.css`, ambos temas)

| Token | Light | Dark | Uso |
| :--- | :--- | :--- | :--- |
| `--color-primary` | `#1c64f2` | `#3f83f8` | Acciones principales, marca |
| `--color-danger` | `#d32f2f` | `#f87171` | Estados "Pendiente"/urgente (`.panel-badge-pending`, `.panel-badge-nuevo`) |
| `--color-success` | `#0e9f6e` | `#34d399` | Estados "Pagado"/positivo (`.panel-badge-paid`) |
| `--color-warning` | `#d97706` | `#fbbf24` | Estados "en proceso" (`.panel-badge-abierto`) |
| `--color-bg` / `--color-bg-alt` | blancos | azules muy oscuros | Fondos base/alterno |
| `--color-text` / `--color-text-muted` | grises oscuros | grises claros | Texto principal/secundario |

Modo oscuro vía `[data-theme="dark"]` en `<html>` (toggle en `panel/js/shell.js`) — **cualquier
control de formulario debe estar envuelto en `.panel-form-field` (o usar `.panel-chip`/`.panel-search`)
para heredar estos tokens**; un `<select>`/`<input>` suelto se renderiza con los colores nativos del
navegador (blanco/negro fijos) sin importar el tema — bug real encontrado y corregido 2026-08-12.

---

## 3. Diccionario de Datos Completo

Fuente exacta y siempre vigente: `knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md` (columna por columna,
con notas de por qué existe cada cosa) y `db/schema.sql` (DDL ejecutable). Resumen de relaciones:

```
street (25) ──┐
              ├──< property (196–199) >── quota_id ──> quota (13, catálogo de tarifas)
              │         │
              │         └──< user_quotas (8,500+, TRANSACCIONAL) >── user_id ──> user (204+ colonos)
              │
user (204+) ──┴──< announcements.author_id (quién publicó)
              ├──< audit_logs.changed_by (quién hizo qué cambio)
              ├──< messages.user_id (dueño del hilo) ──< message_replies.author_id
              └──< contact_messages (SIN FK — formulario público anónimo, remitente no necesariamente registrado)
```

| Tabla | Filas aprox. | Propósito | Notas clave |
| :--- | :--- | :--- | :--- |
| `street` | 25 | Catálogo de calles/privadas | |
| `quota` | 13 | Catálogo de tarifas de mantenimiento (`name`, `cost`) | Fuente del monto al generar cuotas |
| `user` | 204+ | Colonos/propietarios. `role` ∈ `owner`\|`admin`\|`super_admin` | `password` nunca se expone en JSON; soft-delete vía `deleteAt` |
| `property` | 196–199 | Casas/unidades. FK a `street`/`quota` | Sin FK directa a `user` — dueño se infiere del `user_quotas` más reciente |
| `user_quotas` | 8,500+ | **Tabla transaccional** — una fila por cuota/mes/propiedad | `status` 1=pendiente/2=pagado; `receipt`=comprobante subido; `official_receipt_url`=recibo oficial generado (**nuevo** 2026-08-13); `pay_date`=fecha real de pago; `captured_at`=cuándo se capturó en el sistema (**nuevo** 2026-08-13, distinto de `pay_date`) |
| `contact_messages` | — | Log del formulario público anónimo (`index.html`) | Sin auth, sin FK — remitente no necesariamente colono |
| `announcements` | 61+ | Comunicados/informes financieros | `category` ∈ `comunicados`\|`financiero`\|`reportes`; `status` ∈ `published`\|`draft`; `visibility` ∈ `public`\|`private` (¿se expone en la Landing Page pública o solo dentro del panel autenticado?) |
| `audit_logs` | — | Bitácora create/update/delete | `details_json` guarda `{before,after}` completo, no solo el diff |
| `messages` | 0+ | **Nueva 2026-08-12** — hilos de mensajería colono↔admin | `status` ∈ `nuevo`\|`abierto`\|`respondido`\|`cerrado`; `is_broadcast` marca los masivos |
| `message_replies` | 0+ | **Nueva 2026-08-12** — cada mensaje dentro de un hilo | Un hilo siempre tiene ≥1 respuesta (el mensaje original) |

---

## 4. Catálogo de Endpoints REST API v2

Base: `/api/v2/`. Todos requieren `Authorization: Bearer <JWT>` salvo los marcados **pública**.
Fuente exhaustiva y siempre vigente: `knowledge/03_CONTRATOS_API_Y_RUTAS.md`.

### Autenticación
| Método | Ruta | Rol | Notas |
| :--- | :--- | :--- | :--- |
| POST | `/auth/login` | pública | `{email,password}` → `{token,accessToken,user:{...,privacy}}` |

### Cuotas y generación automática
| Método | Ruta | Rol | Notas |
| :--- | :--- | :--- | :--- |
| GET | `/quota`, `/quota/filter` | admin/super_admin | Catálogo de tarifas |
| POST/PUT/DELETE | `/quota/create`, `/quota/update/{id}`, `/quota/delete/{id}` | admin/super_admin | CRUD del catálogo |
| POST | `/quota/generate-period` | admin/super_admin | `{period:"YYYY-MM", dryRun}` — genera la cuota pendiente del mes por propiedad, idempotente. También corre por cron real (`api/v2/cli/generate_monthly_quotas.php`, día 1 de cada mes, sin JWT) |

### Pagos ("Pagos" clásico — **solo super_admin**) y autoservicio del colono
| Método | Ruta | Rol | Notas |
| :--- | :--- | :--- | :--- |
| GET | `/payment`, `/payment/filter` | **super_admin** | Listado admin de todos los pagos |
| PUT | `/payment/pay/{id}` | **super_admin** | Marca pagado manualmente (1 mes, sin recibo oficial) |
| POST | `/payment/upload-receipt` | **super_admin** | Sube comprobante suelto |
| GET | `/payment/get-file/{id}` | owner (solo lo propio) o **super_admin** | Descarga comprobante |
| GET | `/payment/owners` | JWT (owner incluido) | Autoservicio — "mis cuotas" |
| GET | `/payment/list-owners`, `/payment/download-report*` | JWT | Pantalla "Estado de Cuenta" (no restringida a super_admin, es reporte distinto de "Pagos") |

### Módulo de Caja ("Registrar Pago" — **exclusivo super_admin**)
| Método | Ruta | Notas |
| :--- | :--- | :--- |
| GET | `/caja/search?q=` | Busca colono por nombre/correo/código/calle/número oficial |
| GET | `/caja/pending/{userId}` | Matriz de meses pendientes de un colono |
| POST | `/caja/register-payment` | Transacción atómica: marca pagado(s), guarda comprobante(s), genera recibo oficial con folio (HTML imprimible → PDF vía navegador) |

### Comunicados / Informes Financieros
| Método | Ruta | Rol | Notas |
| :--- | :--- | :--- | :--- |
| GET | `/posts`, `/comunicados`, `/informes` | pública | Solo `status=published` + `visibility=public` |
| GET | `/avisos`, `/avisos/years` | JWT (cualquier rol) | Feed del panel — published, público **y** privado (estar autenticado ya implica ser colono legítimo) |
| GET/POST/PUT/DELETE | `/announcements*` | admin/super_admin | CRUD completo (`panel/comunicados.html`, `panel/informes.html`) |
| POST | `/announcements/upload` | admin/super_admin | Sube PDF adjunto |

### Mensajería interna
| Método | Ruta | Rol | Notas |
| :--- | :--- | :--- | :--- |
| GET | `/messages` | JWT (cualquier rol) | Bandeja — owner ve lo suyo, admin/super_admin ve todo |
| GET | `/messages/{id}` | dueño del hilo o admin/super_admin | Detalle + respuestas |
| POST | `/messages` | JWT (cualquier rol) | Inicia un hilo propio |
| POST | `/messages/{id}/reply` | dueño del hilo o admin/super_admin | Responde |
| PUT | `/messages/{id}/status` | admin/super_admin | Cambio manual de estatus |
| POST | `/messages/broadcast` | admin/super_admin | Un hilo por colono activo |

### Otros
| Método | Ruta | Rol | Notas |
| :--- | :--- | :--- | :--- |
| GET/POST/PUT/DELETE | `/user*`, `/property*` | admin/super_admin | CRUD Propietarios/Propiedades |
| GET | `/dashboard/summary`, `/dashboard/yearly-trends` | admin/super_admin | KPIs del panel |
| GET | `/audit-logs`, `/{entidad}/{id}/history` | admin/super_admin | Bitácora |
| POST | `/contact` | pública | Formulario de contacto de la web pública |

---

## 5. Guía de Operación Administrativa

### 5.1 Módulo de Caja / Registrar Pago (`panel/caja.html`, solo super_admin)
1. Busca al colono por nombre, correo, código, calle o número oficial (autocompletado en vivo).
2. Selecciona los meses pendientes a cobrar — hay un botón "Seleccionar año completo" por cada
   año con pendientes.
3. Elige el modo de comprobante: **único** (un solo archivo cubre todos los meses seleccionados)
   o **individual** (un archivo por mes).
4. Opcionalmente, cambia la tarifa aplicada desde el catálogo de Cuotas (recalcula el total).
5. Al registrar, el sistema marca los meses como pagados, guarda el/los comprobante(s), y genera
   un **recibo oficial con folio** (`FIDEPAZ-{año}-{id}`) — una página HTML lista para
   imprimir/guardar como PDF (Ctrl+P). El colono puede ver este mismo recibo desde su propia
   cuenta (`mi-cuenta.html`/`pagos.html`).

### 5.2 Generación mensual automática de cuotas
- **Automática:** cron de cPanel (`api/v2/cli/generate_monthly_quotas.php`), día 1 de cada mes.
  Revisa cada propiedad activa; si ya tiene una cuota (pagada o pendiente) para el mes, no hace
  nada; si no, inserta una nueva en `pendiente` con la tarifa asignada a esa propiedad.
- **Manual:** botón "Generar cuotas del mes" en `panel/cuotas.html` — exige correr la vista
  previa (`dryRun`) antes de habilitar el botón real, para nunca generar a ciegas.

### 5.3 Mensajería masiva (`panel/mensajes.html`)
Botón "📢 Mensaje masivo" (solo admin/super_admin) — un asunto y cuerpo que se envía como un hilo
independiente a cada colono activo. Cada colono puede responder su propio hilo, que a partir de
ahí funciona como una conversación normal 1:1.

### 5.4 Comunicados públicos vs. privados (`panel/comunicados.html`, `panel/informes.html`)
Cada comunicado/informe tiene una `visibility`: **privado** (default, solo visible dentro del
panel autenticado) o **público** (se muestra también en la sección "Noticias y avisos" de la
Landing Page, sin necesidad de login). Los adjuntos PDF se suben con validación de tipo MIME real
(nunca se confía en la extensión del archivo).

---

## 6. Guía del Colono

### 6.1 Mi cuenta (`panel/mi-cuenta.html`)
Resumen de total pagado/pendiente, historial de cuotas filtrable por año, y recordatorio de aviso
de privacidad (una vez aceptado, el botón desaparece y queda un recordatorio de solo lectura).

### 6.2 Estado de Cuenta imprimible (`reportes/estado-de-cuenta.html`)
Vista elegante, agrupada por año, con badges de color (verde=pagado, rojo=pendiente) — accesible
desde "Ver estado de cuenta elegante" en Mi cuenta.

### 6.3 Descarga de recibos y comprobantes
Cuando un pago se registra desde el Módulo de Caja, el colono puede ver tanto el comprobante de
depósito (subido por la administración) como el **recibo oficial del sistema** (con folio) desde
su historial de cuotas.

### 6.4 Comunicados (`panel/avisos.html`)
Todos los avisos e informes financieros publicados (públicos y privados — estar dentro del panel
ya confirma que eres colono), filtrables por año (arranca en el año actual) y categoría, con
opción de ver el PDF adjunto en el navegador o descargarlo aparte.

### 6.5 Enviar un mensaje a la administración (`panel/mensajes.html`)
Botón "+ Nuevo mensaje" — redacta un asunto y mensaje; la administración lo verá en su bandeja y
podrá responder directamente en el mismo hilo.
