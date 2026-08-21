# MANUAL MAESTRO DEL SISTEMA — FidePaz V2.0

**Versión:** 2.1 | **Fecha:** 2026-08-14 | **Estado:** Documento vivo — actualizar en la misma
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
| GET | `/payment`, `/payment/filter` | **super_admin** | Listado admin de todos los pagos — cada fila incluye `receiptViewToken`/`officialReceiptViewToken`, ver 4.1 |
| PUT | `/payment/pay/{id}` | **super_admin** | Marca pagado manualmente (1 mes, sin recibo oficial) |
| POST | `/payment/upload-receipt` | **super_admin** | Sube comprobante suelto |
| GET | `/payment/get-file/{id}` | owner (solo lo propio) o **super_admin**, **o** `?token=` válido | Descarga comprobante (archivo real subido) |
| GET | `/payment/receipt/{id}` | owner (solo lo propio) o **super_admin**, **o** `?token=` válido | **Nueva 2026-08-13.** Recibo Oficial renderizado al vuelo desde la BD — nunca lee/escribe archivo. Cubre también el histórico V1. Ver 6.3 |
| GET | `/payment/owners` | JWT (owner incluido) | Autoservicio — "mis cuotas", también incluye los tokens de 4.1 |
| GET | `/payment/list-owners`, `/payment/download-report*` | JWT | Pantalla "Estado de Cuenta" (no restringida a super_admin, es reporte distinto de "Pagos") |

#### 4.1 Enlaces de un solo clic para comprobante/recibo (tokens de vida corta)

`GET /payment/get-file/{id}` y `GET /payment/receipt/{id}` aceptan **dos** formas de
autenticación: el `Authorization: Bearer <JWT>` de siempre, **o** un parámetro `?token=` de un
solo propósito. Este segundo mecanismo (2026-08-14) resuelve un problema real de UX: un `<a
href>` normal no puede mandar el header `Authorization`, así que abrir el archivo exigía primero
un `fetch()` autenticado y RECIÉN AHÍ un `window.open()` — que Chrome (y otros navegadores)
bloqueaba como pop-up no solicitado por no ocurrir de forma perfectamente síncrona con el clic.

En vez de pelear con esa heurística, cada fila de `/payment/owners`, `/payment/filter` y
`/payment/quotas-owners/{id}` trae ya emitido un JWT de **15 minutos de vida**, firmado con el
mismo `JWT_SECRET` (reutiliza `Jwt::issue()`/`Jwt::verify()` de `core/Jwt.php`, sin criptografía
nueva), con claims `{quotaId, userId, purpose}` — `purpose` es `'comprobante'` o `'recibo'`, así
que un token nunca puede reutilizarse para el otro archivo ni para otra cuota distinta a la que
fue emitido (`quota_view_token_verify()` en `routes/quotas.php` valida ambas cosas). El frontend
arma el `<a href="...&token=...">` directo desde el primer render de la fila: un solo clic humano
normal, sin `fetch`/`window.open()` de por medio, así que ningún bloqueador de pop-ups puede
interferir. Si el token viene ausente/vencido/no coincide, la ruta cae automáticamente al flujo de
siempre (Bearer + verificación de dueño/rol) — 100% retrocompatible.

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

### 5.2 Generación mensual automática de cuotas — despliegue del Cron Job

**Automática vía Cron Job de cPanel** — cPanel → *Cron Jobs* → *Add New Cron Job*:

| Campo | Valor |
| :--- | :--- |
| Frecuencia (crontab) | `0 0 1 * *` — minuto 0, hora 0, día 1 de cada mes, todos los meses, cualquier día de la semana |
| Comando | `php /home/mercagee/public_html/v2.fidepaz.org/api/v2/cli/generate_monthly_quotas.php` |

> Ajustar `/home/mercagee/public_html/v2.fidepaz.org/` a la ruta real del `home` de la cuenta de
> hosting si cambia (verificar con `pwd` en una terminal SSH de cPanel, o con el "Document Root"
> que muestra cPanel para el dominio/subdominio real). El script se autoprotege contra ejecución
> por HTTP por partida doble — `api/v2/cli/.htaccess` (`Require all denied`) **y** un chequeo
> `PHP_SAPI !== 'cli'` dentro del propio script — así que aunque alguien adivine la URL, nunca se
> ejecuta desde un navegador, solo desde el cron real del servidor.

**Qué hace exactamente, propiedad por propiedad** (`generate_quotas_for_period()`, núcleo
compartido con `POST /quota/generate-period`):
1. Recorre toda `property` activa (`deleteAt IS NULL`) que tenga un `quota_id` (tarifa) asignado.
2. **Validación anti-duplicado/anti-sobrecarga:** si esa propiedad **ya tiene** una fila en
   `user_quotas` para el mes que se está generando (`DATE_FORMAT(due_date,'%Y-%m') = período`),
   la salta por completo — sin importar si esa cuota ya está pagada o sigue pendiente. Este es el
   mecanismo real que evita cobrar dos veces el mismo mes a un colono: cubre tanto el caso "el
   cron ya corrió este mes" como el caso de un colono que adelantó pagos y ya tiene ese período
   cubierto de antemano — en ambos casos la fila ya existe, así que nunca se genera una segunda.
3. Si no hay fila para ese mes, resuelve el dueño más reciente conocido de esa propiedad y crea
   una `user_quotas` nueva en `status=1` (pendiente), con el `due_date` recortado al último día
   real del mes si el día de corte configurado no existe (ej. día 31 en un mes de 30 días).
4. Propiedades sin ningún colono asociado en su historial quedan en `skippedNoOwner` — requieren
   asignación manual, el script nunca inventa un propietario.

**Manual (respaldo/reintento):** botón "Generar cuotas del mes" en `panel/cuotas.html` — exige
correr la vista previa (`dryRun`) antes de habilitar el botón real, para nunca generar a ciegas.
Útil si el cron falla un mes o para regenerar un período pasado específico
(`POST /quota/generate-period`, autenticado admin/super_admin).

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

### 6.3 Arquitectura de recibos y comprobantes — render dinámico, cero basura en disco

Cuando un pago se registra desde el Módulo de Caja, el colono puede ver tanto el comprobante de
depósito (subido por la administración) como el **recibo oficial del sistema** (con folio) desde
su historial de cuotas (`mi-cuenta.html`), desde `panel/pagos.html` (admin) o desde el Estado de
Cuenta imprimible. Dos piezas muy distintas, con dos arquitecturas de almacenamiento distintas a
propósito:

**Recibo Oficial (`GET /payment/receipt/{id}`) — 100% dinámico, sin archivo:**
El HTML del recibo **no se guarda como archivo que el servidor tenga que servir para verlo**. Cada
vez que alguien pide verlo, `handle_payment_receipt_view()` (`api/v2/routes/quotas.php`) arma el
documento completo **al vuelo**, en la misma petición, a partir de columnas que ya existen en
`user_quotas`/`user`/`property`/`street` (importe, fecha, folio, colono, propiedad) — y lo entrega
directo como la respuesta HTTP (`Content-Type: text/html`). No hay `file_put_contents()`, no hay
`readfile()`, no hay ninguna escritura a disco en esta ruta. Consecuencias reales:
- **Ver el mismo recibo 1 vez o 1,000 veces genera exactamente 0 bytes nuevos en el servidor** —
  no existe una "copia cacheada" que crezca con cada vista. La única escritura real de un recibo
  a disco ocurre una sola vez, al momento del cobro en Caja (ver nota al final de esta sección),
  nunca al volver a abrirlo.
- **Cubre también el histórico completo** (10,758 cuotas migradas de la V1, que jamás generaron
  un recibo porque ese concepto no existía antes de V2): cualquier cuota con `status=2` puede
  mostrar su recibo bajo demanda, sin necesidad de "regenerar" ni "migrar" nada primero.
- **Apertura de un solo clic, sin bloqueo de pop-ups:** el `<a href>` de "Ver recibo oficial" no
  dispara ningún `fetch()`/`window.open()` por script — apunta directo a la URL de la API con un
  token de sesión de un solo uso incluido (ver 4.1 abajo), así que el navegador la trata como una
  navegación normal, imposible de bloquear.
- **Privacidad/Zero-Trust:** sin token válido y escopeado a esa cuota exacta, la ruta exige el JWT
  de sesión normal + verificación de dueño — nadie puede enumerar recibos ajenos cambiando el
  número en la URL.

**Comprobante de depósito (`GET /payment/get-file/{id}`) — sí es un archivo real, y así debe ser:**
A diferencia del recibo, el comprobante (la foto/PDF del depósito o transferencia que sube el
colono o la administración) **es contenido real subido por una persona** — no hay forma de
"regenerarlo" desde datos de la BD, así que **sí** vive como archivo en
`assets/uploads/receipts/` (excluido de git, ver `.gitignore`). Esto es correcto e inevitable: es
evidencia de un depósito real, no un documento derivado. Lo que sí es 100% dinámico ahí es el
**enlace de acceso**: igual que el recibo, se abre con un token de un solo uso de vida corta, sin
pasar por `fetch`/blob de por medio.

**En resumen — dos piezas, una sola filosofía:** todo lo que se PUEDE derivar de datos que ya
viven en la BD (el recibo) se genera al vuelo y nunca toca el disco al verse; todo lo que es
inherentemente un archivo subido por una persona (el comprobante) sí se guarda, pero su acceso es
igual de dinámico y seguro. Ningún archivo de recibo/comprobante se genera "por si acaso" —
excepto la única excepción documentada abajo.

**Única escritura de recibo a disco (por diseño, no accidental):** `POST /caja/register-payment`
sigue guardando, además, UNA copia estática del recibo en `assets/uploads/official_receipts/` en
el momento exacto del cobro — como bitácora/respaldo histórico de "así se veía el recibo el día
que se generó", independiente de si el diseño del recibo cambia después. Esa copia **nunca** se
usa para mostrarle el recibo a nadie (eso siempre pasa por el render dinámico de arriba); es
puramente un archivo de auditoría, uno por cobro real, no "basura" que crezca sin control cada vez
que alguien mira un recibo.

### 6.4 Comunicados (`panel/avisos.html`)
Todos los avisos e informes financieros publicados (públicos y privados — estar dentro del panel
ya confirma que eres colono), filtrables por año (arranca en el año actual) y categoría, con
opción de ver el PDF adjunto en el navegador o descargarlo aparte.

### 6.5 Enviar un mensaje a la administración (`panel/mensajes.html`)
Botón "+ Nuevo mensaje" — redacta un asunto y mensaje; la administración lo verá en su bandeja y
podrá responder directamente en el mismo hilo.
