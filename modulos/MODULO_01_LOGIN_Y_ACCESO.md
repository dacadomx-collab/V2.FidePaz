# MODULO_01_LOGIN_Y_ACCESO — Ley Suprema de Autenticación

**Clasificación:** Módulo Genérico de Arquitectura y Diseño Técnico | **Versión:** 8.0 (+ Navegación Global, RBAC Dinámico por Módulo, Núcleo Cognitivo de Bienvenida, Jerarquía de Widgets Acción→Estado→Historial, Ledgers Paginados, Entregabilidad Anti-SPAM Universal, Módulos con Rol Fijo No-Delegable, Formato Regional de Fecha Configurable)
**Alcance:** Documento agnóstico, reutilizable por cualquier proyecto de `{{HOLDING_NAME}}`. Ningún nombre de proyecto, cliente o dominio real debe aparecer aquí — sustituir siempre por `{{PROJECT_NAME}}`, `{{TABLE_PREFIX}}`, etc.
**Relación con v2.0:** Este documento es el **blueprint técnico ejecutable** (SQL → PHP → Frontend). La v2.0 (To-Do list + checklist de madurez enterprise) permanece como anexo de gobernanza al final de este archivo — úsala para auditar qué tan lejos del "ideal enterprise" está la implementación concreta que este v4.0 describe.

> ⚠️ **Mandamiento de Secuencia SQL→PHP:** ningún endpoint de este módulo se escribe en un proyecto consumidor sin que el Schema Maestro (Sección 1) exista primero, palabra por palabra, como script `.sql` versionado en `/database` de ese proyecto. Ningún nombre de columna se traduce libremente — el Codex del proyecto consumidor es la única fuente de verdad para el mapeo final `{{PLACEHOLDER}}` → nombre real.

> 📐 **Mapa de conformidad (auditoría 2026-08-04):** este documento antecede el formato estándar
> de 5 secciones de `MODULO_02`/`MODULO_03`. Se mantiene su estructura original (más detallada, con
> referencias cruzadas por número de sección que no conviene renumerar) y se mapea así:
> **1. Objetivo** → párrafo "Alcance" arriba. **2. Arquitectura/Especificación técnica** →
> Secciones 1–2, 4–8, 10 (schema, endpoints, UI, roles, políticas). **3. Checklist paso a paso**
> → Sección 8 (First-Run Provisioning) y Sección 9 (alta de usuarios). **4. Seguridad Zero
> Trust** → Sección 2.2 (fuerza bruta/timing attacks), Sección 3 completa (tokens/JWT/device
> binding/reset), Sección 1.1 (BCrypt cost=12). **5. Definición de Hecho** → Sección 11
> (Validación de Consistencia del Flujo) + Anexo final. Verificado sin huecos en los tres puntos
> de seguridad más comunes (BCrypt, rate limiting, JWT) — cubiertos en 1.1, 2.2 y 3.1.1
> respectivamente.

> 📎 **Módulos relacionados:** [`MODULO_02_CMS_EDICION_VISUAL.md`](MODULO_02_CMS_EDICION_VISUAL.md) (Motor de Edición Visual en Caliente) y [`MODULO_03_CRM_EVENTOS_EN_VIVO.md`](MODULO_03_CRM_EVENTOS_EN_VIVO.md) (Captación de Interesados y Orquestación de Sesiones en Vivo) dependen de la sesión, roles y Mapeo Dinámico de Permisos definidos aquí (§3, §6, §6.1) — viven en archivos propios porque resuelven problemas distintos al de autenticación.

---

## 1. 🗄️ SCHEMA MAESTRO DE AUTENTICACIÓN (SQL PRIMERO)

### 1.1 Tabla `{{TABLE_PREFIX}}usuarios`

```sql
CREATE TABLE `{{TABLE_PREFIX}}usuarios` (
    `id`                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nombre`            VARCHAR(120)        NOT NULL,
    `email`             VARCHAR(190)        NOT NULL,
    `password_hash`     CHAR(60)            NOT NULL COMMENT 'BCrypt cost=12 — password_hash(PASSWORD_BCRYPT, ["cost" => 12])',
    `rol`               ENUM('admin','editor','usuario') NOT NULL DEFAULT 'usuario',
    `estatus`           ENUM('activo','pendiente','suspendido') NOT NULL DEFAULT 'pendiente',
    `token_acceso`      CHAR(64)            NULL     COMMENT 'Hex de 256 bits (random_bytes(32)) — token opaco de sesión activa',
    `token_expira_en`   DATETIME            NULL,
    `device_hash`       CHAR(64)            NULL     COMMENT 'SHA-256(IP + User-Agent) del dispositivo vinculado (Device Binding)',
    `intentos_fallidos` SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
    `bloqueado_hasta`   DATETIME            NULL     COMMENT 'Tarpitting — NULL si no hay bloqueo activo',
    `hito_cumplido`     TINYINT(1)          NOT NULL DEFAULT 0 COMMENT 'Fricción Cero Gate — 1 solo cuando el API confirma el primer hito/cliente cerrado',
    `creado_en`         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_{{TABLE_PREFIX}}usuarios_email` (`email`),
    KEY `idx_{{TABLE_PREFIX}}usuarios_token` (`token_acceso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notas de diseño:**
- `password_hash` almacena **siempre** el resultado de `password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12])` — nunca MD5/SHA1, nunca texto plano.
- `token_acceso` es un token **opaco**, no un JWT auto-contenido, para permitir revocación inmediata en base de datos (ver Sección 3).
- `hito_cumplido` es el flag que consulta el "Fricción Cero Gate" del frontend (Sección 3.4) — nunca se infiere en el cliente, solo el API lo determina.
- Ninguna columna nueva se agrega a esta tabla en un proyecto consumidor sin pasar primero por el Mandamiento 9 (Inmutabilidad del Sistema) de ese proyecto.

### 1.2 Tabla `{{TABLE_PREFIX}}log_actividad` (Bitácora Append-Only)

```sql
CREATE TABLE `{{TABLE_PREFIX}}log_actividad` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`     BIGINT UNSIGNED     NULL COMMENT 'NULL si el intento falló antes de resolver el usuario (anti-enumeración)',
    `evento`         VARCHAR(60)         NOT NULL COMMENT 'login_exitoso | login_fallido | logout | token_refresh | bloqueo_temporal',
    `ip_hash`        CHAR(64)            NOT NULL COMMENT 'SHA-256 de la IP — nunca IP en claro (privacidad)',
    `device_hash`    CHAR(64)            NOT NULL,
    `detalle`        VARCHAR(255)        NULL COMMENT 'Mensaje técnico genérico. JAMÁS credenciales ni tokens.',
    `creado_en`      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_{{TABLE_PREFIX}}log_usuario` (`usuario_id`),
    KEY `idx_{{TABLE_PREFIX}}log_evento_fecha` (`evento`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Triggers de inmutabilidad (append-only real, no solo por convención de aplicación):**

```sql
DELIMITER $$

CREATE TRIGGER `trg_{{TABLE_PREFIX}}log_no_update`
BEFORE UPDATE ON `{{TABLE_PREFIX}}log_actividad`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Bitácora append-only: UPDATE prohibido sobre log_actividad.';
END$$

CREATE TRIGGER `trg_{{TABLE_PREFIX}}log_no_delete`
BEFORE DELETE ON `{{TABLE_PREFIX}}log_actividad`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Bitácora append-only: DELETE prohibido sobre log_actividad.';
END$$

DELIMITER ;
```

**Notas de diseño:**
- Los triggers convierten el "append-only" en una garantía de motor de base de datos, no en una promesa de la capa PHP — ni siquiera una cuenta con privilegios elevados comprometida puede alterar el historial sin `DROP TRIGGER` explícito (evento auditable aparte).
- `ip_hash`/`device_hash` nunca se loguean en claro — se calculan igual que `device_hash` en `usuarios` (Sección 3.3), permitiendo correlación sin exponer PII directamente en la bitácora.
- La purga/archivado de bitácora antigua (retención) es una decisión de producto del proyecto consumidor — no se automatiza aquí sin autorización explícita.

### 1.3 Tabla `{{TABLE_PREFIX}}configuracion_seguridad` (Política de Contraseña — fila única)

```sql
CREATE TABLE `{{TABLE_PREFIX}}configuracion_seguridad` (
    `id`                        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `politica_password`         ENUM('simple','media','fuerte') NOT NULL DEFAULT 'media',
    `duracion_recordarme_dias`  SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Canónico: 60 (2 meses) o 120 (4 meses) — Sección 3.5/7.5',
    `actualizado_en`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `{{TABLE_PREFIX}}configuracion_seguridad` (`id`, `politica_password`, `duracion_recordarme_dias`)
VALUES (1, '{{POLITICA_PASSWORD_DEFAULT}}', {{DURACION_RECORDARME_DEFAULT}})
ON DUPLICATE KEY UPDATE `id` = `id`;
```

**Notas de diseño:**
- Fila única (`id = 1` siempre) — no es una tabla de historial, es la configuración activa. Ver Sección 7 (Motor Dinámico de Políticas) para el contrato completo.
- Se siembra en la misma migración (vía `INSERT ... ON DUPLICATE KEY UPDATE`) para que exista **antes** de que corra el First-Run Provisioning (Sección 8) — evita el problema del huevo y la gallina (el genesis también debe poder leer una política activa).
- `{{POLITICA_PASSWORD_DEFAULT}}` es una decisión de producto del proyecto consumidor, documentada en su Codex — el default de fábrica de este módulo es `'media'` si el proyecto consumidor no especifica lo contrario.
- `{{DURACION_RECORDARME_DEFAULT}}` acepta únicamente `60` o `120` (Sección 3.5) — cualquier otro valor es responsabilidad exclusiva del proyecto consumidor y rompe la garantía de "opciones canónicas" de este módulo.

---

## 2. 🛡️ PATRÓN CANÓNICO BLINDADO DE ENDPOINTS (6 CAPAS)

Aplica a `login.php`, `logout.php`, `token_refresh.php` y cualquier endpoint de acceso derivado. **Las 6 capas se ejecutan siempre en este orden — ninguna capa posterior se alcanza si una anterior falla.**

```
Request HTTP
   │
   ▼
┌─────────────────────────────────────────────────────────┐
│ CAPA 1 — CORS estricto                                   │
│  · Origen leído de ALLOWED_ORIGINS (.env), nunca "*"      │
│  · OPTIONS (preflight) → 204 inmediato, sin tocar capas   │
│    siguientes                                             │
│  · Origen no permitido → 403 y fin de ejecución            │
└─────────────────────────────────────────────────────────┘
   │
   ▼
┌─────────────────────────────────────────────────────────┐
│ CAPA 2 — Middleware de autenticación / rol / estatus       │
│  · logout.php y token_refresh.php exigen sesión válida     │
│  · estatus = 'suspendido' → 403 inmediato (bloqueo          │
│    preventivo, sin importar validez del token)              │
│  · login.php es el único endpoint que NO exige sesión       │
│    previa (es el punto de entrada)                          │
└─────────────────────────────────────────────────────────┘
   │
   ▼
┌─────────────────────────────────────────────────────────┐
│ CAPA 3 — Restricción explícita de método HTTP               │
│  · Toda mutación (login/logout/refresh) → POST exclusivo    │
│  · Cualquier otro verbo → 405 Method Not Allowed             │
│    (header Allow: POST incluido en la respuesta)             │
└─────────────────────────────────────────────────────────┘
   │
   ▼
┌─────────────────────────────────────────────────────────┐
│ CAPA 4 — Lectura y sanitización estricta de payload         │
│  · Content-Type debe ser application/json (si no → 415)     │
│  · json_decode estricto, UTF-8, JSON_THROW_ON_ERROR          │
│  · Sanitización nativa (filter_var, trim, validación de      │
│    tipo/longitud por campo)                                  │
│  · Cualquier campo inválido/faltante → 422 inmediato con      │
│    detalle de qué campo falló (nunca detalle de credenciales) │
└─────────────────────────────────────────────────────────┘
   │
   ▼
┌─────────────────────────────────────────────────────────┐
│ CAPA 5 — Interfaz segura de persistencia (PDO)               │
│  · PDO::ATTR_EMULATE_PREPARES = false (previene SQLi nativo) │
│  · PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION                │
│  · 100% prepared statements con binding explícito             │
│    ($stmt->bindValue(':email', $email, PDO::PARAM_STR))       │
│  · Cero interpolación de variables en la cadena SQL            │
└─────────────────────────────────────────────────────────┘
   │
   ▼
┌─────────────────────────────────────────────────────────┐
│ CAPA 6 — Try/Catch global de infraestructura                 │
│  · Envuelve TODA la lógica de negocio de las capas 4-5        │
│  · catch (PDOException $e): log interno con SQLSTATE +         │
│    mensaje técnico (error_log, jamás al frontend)               │
│  · catch (Throwable $e): mismo tratamiento genérico             │
│  · Respuesta al cliente SIEMPRE en el Contrato de Respuesta:    │
│    { "status": "error", "message": "...", "data": null }         │
│    con HTTP 500 — nunca se expone traza, SQLSTATE o nombre       │
│    de tabla/columna al frontend                                   │
└─────────────────────────────────────────────────────────┘
   │
   ▼
Respuesta JSON { status, message, data }
```

### 2.1 Contrato de Respuesta (obligatorio en las 6 capas)

```json
{
  "status": "success | error",
  "message": "Texto genérico, seguro de mostrar al usuario final",
  "data": { }
}
```

**Regla de limpieza del contrato (cero fugas de entorno):** ningún campo de
`data` ni de `message` puede contener rutas locales, URLs de depuración,
nombres de host internos, stack traces o cualquier detalle de configuración
de entorno (`{{APP_ENV}}`, rutas de filesystem, DSN, etc.). Este tipo de
información es exclusivamente para operación interna y viaja **únicamente**
a `error_log` (Capa 6) — nunca al cliente HTTP, ni siquiera en entornos de
desarrollo local. Si un endpoint necesita generar un enlace de un solo uso
(invitación, recuperación de contraseña) para trazabilidad manual, el enlace
se registra en el log de errores del servidor, no en la respuesta JSON. El
canal legítimo para que el enlace llegue a su destinatario es el correo
transaccional (§9.4), nunca la respuesta de la API.

### 2.2 Prevención de fuerza bruta y timing attacks (transversal a Capas 4-6)

- **Anti-enumeración:** si el email no existe, ejecutar igualmente `password_verify()` contra un hash BCrypt "dummy" precalculado — el tiempo de respuesta debe ser indistinguible entre "usuario no existe" y "contraseña incorrecta". Mensaje de error siempre: `"Credenciales inválidas."`.
- **Doctrina Zero Enumeration extendida a `estatus`:** una cuenta con `estatus = 'suspendido'` (Capa 2) responde con el **mismo** `401 "Credenciales inválidas."` que una contraseña incorrecta — nunca un mensaje distintivo tipo "cuenta suspendida". Revelar el motivo exacto del rechazo es en sí mismo un canal de enumeración (confirma que el email existe). Si el negocio necesita que el usuario suspendido entienda por qué no puede entrar, ese aviso viaja por un canal fuera de banda (correo, soporte humano), nunca en la respuesta del endpoint de login.
- **Tarpitting progresivo:** cada `login_fallido` incrementa `intentos_fallidos`; al superar el umbral (`{{MAX_INTENTOS}}`, ej. 5), se escribe `bloqueado_hasta = NOW() + INTERVAL {{MINUTOS_BLOQUEO}} MINUTE` y se retorna 429 con mensaje genérico, sin revelar el umbral exacto.
- **Rate limiting multivectorial:** el límite se evalúa por `device_hash` **y** por `ip_hash` de forma independiente — un atacante rotando IP sigue frenado por el device hash, y viceversa.
- **Reset de contador:** `intentos_fallidos = 0` únicamente tras un `login_exitoso` verificado en Capa 5.

### 2.3 Diagnóstico responsable de fallas de conexión a BD (`DB_HOST`)

Un `500` en producción con `PDOException` en el log **nunca** se resuelve alterando `{{DB_HOST}}` de forma condicional en código (ej. "si el dominio es de producción, forzar `localhost`") sin haber leído primero el error real:

- `{{DB_HOST}}` suele apuntar a un servidor de base de datos físicamente distinto al del hosting web (de ahí el subdominio propio, ej. `{{DB_SUBHOST}}.{{PROVEEDOR}}.com`, en vez de `localhost`) — es un patrón estándar de hosting compartido. Forzar `localhost` en ese escenario **no evade un firewall**, apunta a un servidor MySQL que probablemente ni siquiera existe en esa máquina, y convierte una falla diagnosticable en una desconexión total.
- Antes de tocar la clase `Database`, se lee el `error_log` real del entorno afectado — el mensaje de `PDOException` (código SQLSTATE + texto) casi siempre apunta a la causa real: credenciales desincronizadas entre `.env` de cada entorno, un límite de conexiones concurrentes del proveedor, una tabla/columna que existe en un entorno pero no en otro, o simplemente código nuevo desplegado sin que la migración SQL correspondiente se haya ejecutado ahí.
- Si el mismo `{{DB_HOST}}` ya fue probado exitosamente desde otro entorno (ej. local) contra la **misma** base de datos compartida, eso es evidencia fuerte de que el host es alcanzable — la causa del `500` casi seguro no es de red/firewall, y buscarla ahí retrasa encontrar la causa real.
- La IA ejecutora nunca debe "adivinar" un fix de infraestructura de producción sin haber visto el error real — pedir el log es la acción correcta, no un bloqueo.

### 2.4 Ley de Oro de Entregabilidad Anti-SPAM (requisito universal, toda mutación por correo)

Todo correo transaccional saliente del holding — sin excepción de plantilla ni de endpoint — debe cumplir, a nivel de infraestructura (no solo de una plantilla puntual), con:

- **Dominio de origen limpio:** el remitente (`{{SMTP_USER}}`/`{{MAIL_FROM_NAME}}`) nunca lleva prefijos de desarrollo (`test-`, `staging-`, `dev.`) en el entorno de producción — un dominio con apariencia de entorno no productivo es una señal fuerte de SPAM para los filtros de los ISPs.
- **URLs absolutas siempre, vía `{{APP_URL}}`:** toda imagen, logotipo o enlace firmado dentro del cuerpo del correo se construye concatenando la variable de entorno `{{APP_URL}}` — nunca una ruta relativa (los clientes de correo no tienen noción del dominio del sitio que los envía).
- **Cabecera `List-Unsubscribe` (RFC 2369/8058), a nivel de infraestructura de envío:** el cliente SMTP centralizado (ej. `enviarCorreoTransaccional()`) agrega `List-Unsubscribe: <mailto:{{CORREO_CONTACTO}}?subject=...>` y `List-Unsubscribe-Post: List-Unsubscribe=One-Click` a **toda** cabecera saliente, sin que cada llamador tenga que declararlo — esto cubre incluso los correos que usan una plantilla simple/no premium (ej. notificaciones masivas de evento), que de otro modo quedarían fuera del blindaje si solo se documentara a nivel de una plantilla específica.
- **Footer de cumplimiento visible (CAN-SPAM):** además de la cabecera técnica, el cuerpo HTML incluye un pie visible con el placeholder de dirección postal física del remitente y un enlace funcional de baja (`mailto:` o web) — ver MODULO_01 §9.4 para la implementación completa en la plantilla premium.
- Sin esta estructura completa (cabecera + footer visible + URLs absolutas + dominio limpio), el correo puede ser filtrado silenciosamente por Gmail/Yahoo/Outlook — no como excepción, sino como comportamiento esperado del proveedor. Es un requisito de arranque, no una mejora opcional posterior.

---

## 3. 🔑 PROTOCOLO DE TOKENS, SEGURIDAD DE SESIÓN Y DISPOSITIVOS

### 3.1 Elección de esquema: Token Opaco (recomendado) vs. JWT

| Criterio | Token Opaco Hex 256-bit (default) | JWT Enterprise |
| :--- | :--- | :--- |
| Revocación inmediata | ✅ Nativa (DELETE en `token_acceso`) | ⚠️ Requiere blocklist adicional |
| Superficie de ataque | Menor (no auto-contiene claims) | Mayor (`alg:none`, confusión de claves) |
| Complejidad de infraestructura | Baja — una columna + índice | Media — firma, rotación de claves |
| Uso recomendado | Backend monolítico con sesión centralizada (**default de este módulo**) | Arquitecturas con múltiples microservicios que no comparten DB de sesión |

**Generación del token opaco (obligatoria por default):**

```php
$token = bin2hex(random_bytes(32)); // 256 bits, criptográficamente seguro — nunca uniqid(), rand() ni md5(time())
```

### 3.1.1 Variante JWT — RBAC de roles múltiples (`role` primario + `roles[]`)

Cuando un proyecto consumidor elige la columna JWT de la tabla anterior y su modelo de datos usa RBAC normalizado (`{{TABLE_PREFIX}}usuario_roles` many-to-many, en vez del `rol` ENUM plano de la Sección 1.1), el token debe emitir **ambos** claims — nunca solo uno:

```php
$claims = [
    'sub'   => (int) $usuario['id'],
    'email' => (string) $usuario['email'],
    'role'  => $roles[0], // rol de mayor jerarquía (primer id de la tabla de roles) — compat. con middleware de un solo rol
    'roles' => $roles,    // set completo — consumido por middleware RBAC granular cuando exista
];
```

- **Motivo de emitir ambos:** permite introducir RBAC de múltiples roles por usuario sin romper un middleware `requireRole()` preexistente que solo lee `payload['role']` — el consumo granular de `roles[]` se activa cuando el middleware del proyecto se actualice, sin requerir un segundo `login` para invalidar tokens viejos.
- Si el usuario no tiene ningún rol asignado en la tabla de mapeo, el login **falla** con `403` genérico (nunca se emite un token sin rol) — un usuario sin rol no es un caso de "rol por defecto silencioso".
- El orden de `roles[]` es el de la jerarquía declarada en la tabla `{{TABLE_PREFIX}}roles` (id ascendente = mayor privilegio primero), consistente con la Sección 6 (Matriz de Roles).

### 3.2 Cookies de sesión

- `HttpOnly` — obligatorio siempre (bloquea acceso vía `document.cookie`, mitiga XSS de robo de sesión).
- `SameSite=Lax` — default (protege contra CSRF en navegación cruzada manteniendo usabilidad de enlaces entrantes). Usar `Strict` solo si el flujo de negocio no requiere entrada desde enlaces externos.
- `Secure` — obligatorio en producción (`{{APP_ENV}} === 'production'`); en local/XAMPP sobre HTTP puede omitirse solo en `{{APP_ENV}} === 'local'`.
- Mitigación de session fixation: si el proyecto consumidor usa sesiones nativas de PHP (`$_SESSION`), llamar `session_regenerate_id(true)` en cada login exitoso — **pero solo dentro de una sesión ya iniciada** (`session_start()` previo), o PHP emite un *Warning* que contamina la respuesta JSON. Si el proyecto usa el esquema de Token Opaco (Sección 3.1, default de este módulo) **sin** `$_SESSION`, el equivalente ya ocurre al emitir un token nuevo (`random_bytes`) en cada login — no se llama `session_regenerate_id()` en absoluto.

### 3.3 Device Binding (IP + User-Agent)

```php
$deviceHash = hash('sha256', $ipAddress . '|' . $userAgent . '|' . {{APP_SECRET}});
```

- Se calcula en cada request autenticado y se compara contra `usuarios.device_hash`.
- **Mismatch de device_hash con token válido** → tratar como posible secuestro de sesión: invalidar el token inmediatamente, registrar `evento = 'device_mismatch'` en la bitácora, forzar nuevo login.
- El hash incluye `{{APP_SECRET}}` (pimienta del proyecto consumidor, en `.env`) para que no sea reproducible fuera del sistema.
- Cambios legítimos de red (IP dinámica de ISP/VPN) son un trade-off conocido — el proyecto consumidor decide si notifica al usuario en vez de bloquear duro (ver v2.0, Fase 4/7).

### 3.4 Mandamiento de Blindaje Fiscal por Cartera (Fricción Cero Gate)

Regla de negocio genérica: **ningún formulario de datos sensibles o fiscales del perfil se desbloquea en el frontend sin confirmación determinística del backend.**

- El frontend renderiza el formulario sensible **siempre**, pero bajo un overlay `glassmorphism` (`backdrop-filter: blur(...)`, fondo semitransparente) con un candado visual y mensaje explicativo.
- El overlay se retira **exclusivamente** cuando una llamada al API (ej. `GET /api/estado_hito.php`) responde `{"status":"success","data":{"hito_cumplido": true}}`, reflejando `usuarios.hito_cumplido = 1` en DB.
- **Prohibido** desbloquear el overlay por estado local, flag de frontend, o "optimismo" tras una acción del usuario — siempre se re-consulta el API. Esto evita que un usuario manipule `localStorage`/DevTools para saltarse el gate.
- El endpoint que marca `hito_cumplido = 1` es responsabilidad del módulo de negocio consumidor (fuera de alcance de este módulo de login), pero **la lectura** del flag sí vive en el contrato de este módulo de autenticación.

### 3.5 "Mantenerse Registrado" (Remember Me — Persistencia de Sesión Extendida)

Checkbox opcional en el formulario de login que extiende la duración del token/cookie de sesión más allá del TTL ordinario, **sin** debilitar ninguna otra capa de seguridad (device binding, `HttpOnly`, `SameSite`, revocación inmediata siguen aplicando igual).

- La duración extendida **no se hardcodea** — se lee de la misma tabla de configuración del Motor Dinámico de Políticas (Sección 7), fila única, columna `duracion_recordarme_dias`. Opciones canónicas de este módulo: **60 días (2 meses)** o **120 días (4 meses)** — el `super_admin` elige una de las dos desde el Dashboard (Sección 7.5), nunca un valor libre arbitrario.
- Backend (`login.php`, Capa 5): si `recordarme === true` en el payload, `token_expira_en = NOW() + INTERVAL {{DURACION_RECORDARME_DIAS}} DAY` y la cookie se emite con el mismo TTL; si es `false` (default), se usa el TTL de sesión ordinario del proyecto consumidor (ej. 8 horas).
- La cookie extendida conserva **todas** las banderas de la Sección 3.2 (`HttpOnly`, `SameSite=Lax`, `Secure` en producción) — "recordarme" extiende la duración, nunca relaja la protección del transporte.
- El device binding (Sección 3.3) se sigue validando en cada request sin excepción — una sesión "recordada" robada desde otro dispositivo sigue siendo rechazada por mismatch de `device_hash`.

### 3.6 Recuperación de Contraseña (Password Reset)

Mismo patrón de token de un solo uso que la invitación (Sección 9.2), aplicado al flujo de "Olvidé mi contraseña" — reutiliza el contrato, no lo reinventa.

```sql
CREATE TABLE `{{TABLE_PREFIX}}recuperacion_password` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`  BIGINT UNSIGNED NOT NULL,
    `token_hash`  CHAR(64)        NOT NULL COMMENT 'SHA-256 del token — el token en claro solo viaja en el email',
    `expira_en`   DATETIME        NOT NULL COMMENT 'TTL corto — {{TTL_RECUPERACION}}, ej. 1 hora (más corto que una invitación)',
    `usado`       TINYINT(1)      NOT NULL DEFAULT 0,
    `creado_en`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_{{TABLE_PREFIX}}recuperacion_token_hash` (`token_hash`),
    KEY `idx_{{TABLE_PREFIX}}recuperacion_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Paso 1 — Solicitud (endpoint público, ej. `recuperar_password.php`):**
- Recibe solo `email`. **Anti-enumeración estricta:** la respuesta es **siempre** `{"status":"success","message":"Si el correo existe, recibirás un enlace."}` — idéntica exista o no la cuenta, con el mismo tiempo de respuesta (mismo principio de la Sección 2.2).
- Si el email existe y `estatus = 'activo'`, genera `tokenClaro = bin2hex(random_bytes(32))`, persiste solo el hash, y dispara la plantilla de correo transaccional con el enlace firmado (`{{RUTA_RESTABLECER}}?token=$tokenClaro`).
- Invalida (marca `usado = 1`) cualquier token de recuperación previo no usado del mismo `usuario_id` antes de crear uno nuevo — evita que enlaces viejos sigan siendo válidos en paralelo.

**Paso 2 — Vista standalone de restablecimiento:**
- Ruta pública temporal (ej. `/restablecer-password.php?token=...`), sin sesión previa.
- Incluye **obligatoriamente** el Visibility Toggle y el Medidor de Fuerza 0-100% (Sección 4.6), calibrados contra la política activa (Sección 7).
- Valida `token_hash` + `expira_en > NOW()` + `usado = 0`, igual que la invitación — mismo mensaje genérico ante cualquier fallo ("Este enlace ya no es válido").

**Paso 3 — Confirmación (endpoint público, ej. `restablecer_password.php`):**
- Valida la nueva contraseña contra `passwordCumplePolitica()` (Sección 7) antes de aceptar.
- `UPDATE usuarios SET password_hash = ...` + `UPDATE recuperacion_password SET usado = 1` en una sola transacción.
- Tras el éxito, **revoca la sesión activa existente** (`token_acceso = NULL`) del usuario si la tenía — un reset de contraseña implica que cualquier sesión anterior (potencialmente comprometida) deja de ser válida.

---

## 4. 🎨 ARQUITECTURA VISUAL DE ACCESO (REGLA DE ORO Y ARF-GRID)

### 4.1 Variables de tema (declarar en `:root` de la hoja de estilos raíz del proyecto consumidor)

```css
:root {
    --auth-bg: {{COLOR_FONDO_OSCURO}};        /* Estudio nocturno — ej. #10141E */
    --auth-accent: {{COLOR_ACENTO_NEON}};     /* Acento vibrante — ej. #E91E63 */
    --auth-text: {{COLOR_TEXTO_PRINCIPAL}};   /* Contraste WCAG >= 4.5:1 sobre --auth-bg */
    --auth-text-muted: {{COLOR_TEXTO_SECUNDARIO}};
    --auth-radius: {{RADIO_BORDE_BASE}};
}
```

- Prohibido cualquier color hardcodeado fuera de estas variables dentro de los componentes de este módulo.
- El proyecto consumidor mapea estas variables a su propia paleta (`--ajedrez-*`, `--{{PROJECT_NAME}}-*`, etc.) sin renombrar la semántica (`bg`, `accent`, `text`, `text-muted`).

### 4.2 Mobile-First

- Contenedor principal del formulario de acceso: sin `width`/`height` fijos en `px`. Usar `%`, `clamp()`, `vh`/`vw` o las variables de diseño ya declaradas (`--container-max`, etc.) del proyecto consumidor.
- Inputs y botones con áreas táctiles ≥ 44×44px (accesibilidad móvil), definidas por `padding` relativo, no por `width`/`height` absolutos.

### 4.3 ARF-Grid (si existen módulos repetitivos: alternativas de login, badges de seguridad, selector de rol, etc.)

```css
.auth-arf-grid {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 1rem; /* o variable de espaciado del proyecto consumidor */
}

.auth-arf-grid__item {
    flex: 1 1 240px;     /* fluido — nunca ancho fijo en px */
    max-width: 320px;
    aspect-ratio: 1 / 1; /* o el ratio que defina el diseño */
    transition: transform 0.18s ease, box-shadow 0.18s ease; /* atómico, sin reflow */
}

.auth-arf-grid__item:hover {
    transform: translateY(-4px); /* transform/opacity/box-shadow únicamente */
}
```

- `justify-content: center` es obligatorio para que la última fila incompleta no quede desalineada.
- Los efectos `:hover` se limitan a `transform`, `opacity` y `box-shadow` sobre el `aspect-ratio` ya establecido — cualquier propiedad que dispare reflow (`width`, `height`, `margin`, `top/left` sin `position` ya fijado) queda prohibida en estados interactivos.

### 4.4 Regla de Oro de Estilos

- **Cero** `style="..."` inline en HTML/JS generado por este módulo.
- **Cero** `!important` — cualquier conflicto de especificidad se resuelve reordenando la cascada o aumentando especificidad de forma limpia (ej. anidando el selector), nunca forzando.
- Toda regla vive en la hoja de estilos centralizada del proyecto consumidor (no se crean archivos `.css` nuevos por módulo salvo autorización explícita).

### 4.5 Invariabilidad UI/UX ante fallos críticos (frontend)

Contrato de estado obligatorio para el script de login del proyecto consumidor:

```javascript
const authState = {
    halted: false, // true = irreversible para esta carga de página
};

function haltAuthFlow(message) {
    authState.halted = true; // se fija ANTES de cualquier otra operación de DOM

    // Pinta el error en un contenedor DEDICADO y persistente — nunca en un toast/snackbar efímero
    const errorBox = document.getElementById('auth-error-container');
    errorBox.textContent = message;
    errorBox.hidden = false;

    // El botón de reintento/retorno debe quedar operativo de forma infalible:
    // se re-habilita explícitamente, sin depender de que el resto del flujo funcione.
    const retryBtn = document.getElementById('auth-retry-btn');
    retryBtn.disabled = false;
}

// En cualquier función transaccional (submit, refresh, etc.):
function submitLogin(payload) {
    if (authState.halted) {
        return; // ninguna función transaccional se ejecuta una vez halted = true
    }
    // ... lógica normal ...
}
```

- Una vez `authState.halted = true`, **ninguna** función transaccional (submit, refresh silencioso, reintentos automáticos) puede ejecutarse de nuevo en esa carga de página — solo una recarga completa o una acción explícita de "Reintentar" gestionada por el propio `haltAuthFlow` puede limpiar el estado.
- El contenedor de error dedicado (`#auth-error-container`) es persistente en el DOM (no desaparece solo); se oculta únicamente cuando el usuario reintenta con éxito.
- El botón de retorno/reintento se habilita **antes** de cualquier `return` temprano de la función que detectó el fallo, garantizando que el usuario nunca quede con la UI congelada sin salida.

### 4.6 Controles de Contraseña — Visibility Toggle y Medidor de Fuerza

**Todo** `<input type="password">` de este módulo incluye un botón "ojito" (Password Visibility Toggle):

```html
<div class="password-field">
    <input class="auth-input" type="password" id="{{CAMPO_ID}}" name="password" autocomplete="{{new-password|current-password}}" required>
    <button type="button" class="password-field__toggle" data-password-toggle="{{CAMPO_ID}}" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
</div>
```

```javascript
function initPasswordToggles() {
    document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
        const input = document.getElementById(btn.dataset.passwordToggle);
        if (!input) {
            return;
        }

        btn.addEventListener('click', function () {
            const visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            btn.setAttribute('aria-pressed', String(!visible));
            btn.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
        });
    });
}
```

- El toggle **nunca** dispara el submit del formulario (`type="button"`, nunca `type="submit"`).
- Cambiar la visibilidad **no** limpia ni reformatea el valor del input — el usuario debe poder verificar exactamente lo que escribió antes de enviar.

**Medidor de fuerza (0-100%)** bajo todo formulario de creación/cambio de contraseña — lee la política activa (Sección 7) para calibrar sus umbrales, nunca hardcodea los suyos propios:

```html
<div class="password-strength" data-password-strength-for="{{CAMPO_ID}}">
    <div class="password-strength__track">
        <div class="password-strength__fill" data-password-strength-fill></div>
    </div>
    <p class="password-strength__label" data-password-strength-label></p>
</div>
```

```javascript
function calcularFuerzaPassword(password, politica) {
    // `politica` = { longitud_minima, requiere_mayuscula, requiere_minuscula, requiere_numero, requiere_simbolo }
    const checks = [
        password.length >= politica.longitud_minima,
        !politica.requiere_mayuscula || /[A-Z]/.test(password),
        !politica.requiere_minuscula || /[a-z]/.test(password),
        !politica.requiere_numero || /[0-9]/.test(password),
        !politica.requiere_simbolo || /[^a-zA-Z0-9]/.test(password),
    ];

    const cumplidos = checks.filter(Boolean).length;
    return Math.round((cumplidos / checks.length) * 100);
}
```

- El porcentaje se recalcula en cada evento `input` — nunca solo al enviar el formulario (feedback en tiempo real, Fricción Cero).
- El relleno de la barra (`.password-strength__fill`) anima únicamente `width` sobre un contenedor de altura fija (`.password-strength__track`) — es la única excepción documentada a "nunca animar `width`" (Sección 5.3) porque aquí `width` **es** la información que se comunica, no un efecto decorativo, y el contenedor padre no cambia de tamaño (no hay reflow del layout circundante).
- Colores de la barra (`débil`/`media`/`fuerte`) se mapean a variables ya declaradas (`--auth-accent` y variantes), nunca colores hardcodeados nuevos.

---

## 5. 📱 ARQUITECTURA DEL DASHBOARD UNIVERSAL (MOBILE-FIRST 90+)

### 5.1 Estructura semántica base

```html
<div class="dash-shell" data-dash-shell>
    <header class="dash-topbar">
        <button type="button" class="dash-topbar__burger" id="dash-burger" aria-label="Abrir menú" aria-expanded="false" aria-controls="dash-nav">
            <span class="dash-topbar__burger-line"></span>
            <span class="dash-topbar__burger-line"></span>
            <span class="dash-topbar__burger-line"></span>
        </button>
        <span class="dash-topbar__brand">{{PROJECT_NAME}}</span>
    </header>

    <nav class="dash-nav" id="dash-nav" data-dash-nav aria-hidden="true">
        <ul class="dash-nav__list">
            <li><a href="#" class="dash-nav__link">{{NAV_ITEM_1}}</a></li>
            <li><a href="#" class="dash-nav__link">{{NAV_ITEM_2}}</a></li>
            <!-- ...tantos ítems como el proyecto consumidor requiera -->
        </ul>
    </nav>

    <div class="dash-nav-backdrop" data-dash-nav-close></div>

    <main class="dash-content">
        <!-- contenido de cada vista del dashboard -->
    </main>
</div>
```

### 5.2 Menú hamburguesa — lógica nativa (sin dependencias externas)

```javascript
function initDashNav() {
    const shell = document.querySelector('[data-dash-shell]');
    const burger = document.getElementById('dash-burger');
    const nav = document.querySelector('[data-dash-nav]');
    if (!shell || !burger || !nav) {
        return;
    }

    function openNav() {
        shell.classList.add('dash-shell--nav-open');
        burger.setAttribute('aria-expanded', 'true');
        nav.setAttribute('aria-hidden', 'false');
    }

    function closeNav() {
        shell.classList.remove('dash-shell--nav-open');
        burger.setAttribute('aria-expanded', 'false');
        nav.setAttribute('aria-hidden', 'true');
    }

    burger.addEventListener('click', function () {
        shell.classList.contains('dash-shell--nav-open') ? closeNav() : openNav();
    });

    document.querySelectorAll('[data-dash-nav-close]').forEach(function (el) {
        el.addEventListener('click', closeNav);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeNav();
        }
    });
}
```

### 5.3 Reglas visuales de adaptabilidad (obligatorias)

- **Contenedores fluidos:** `.dash-content`, `.dash-shell` y cualquier wrapper de nivel superior usan `width: 100%` + `max-width` en variable de diseño — nunca `px` fijo.
- **Control de desbordamiento:** todo bloque con contenido potencialmente ancho (tablas, bloques de código, imágenes) se envuelve en `overflow-x: auto` propio — el `<body>` nunca desarrolla scroll horizontal.
- **Transiciones atómicas del menú:** el panel `.dash-nav` se anima solo con `transform: translateX(...)` y `opacity` sobre un `position: fixed` ya establecido — nunca animando `width`/`left`/`margin` (evita reflow, mantiene 90+ en Lighthouse mobile).
- **Backdrop del menú:** `.dash-nav-backdrop` fijo, `opacity` 0→1 con `pointer-events` condicionado, igual que el patrón de lightbox/modal ya usado en este módulo (consistencia de patrones dentro del mismo sistema).
- **Áreas táctiles:** el botón hamburguesa y todo ítem de `.dash-nav__link` mantienen un área mínima de 44×44px vía `padding`, nunca vía `width`/`height` fijos.

### 5.3.1 Navegación en Acordeón (Anti-Crowding Sidebar)

**Prohibido** un `.dash-nav__list` plano con más de ~5 ítems — a partir de ahí, el menú se organiza en grupos colapsables (`{{GRUPO_1}}`, `{{GRUPO_2}}`, ...), cada uno con sus propios ítems internos. Solo un grupo permanece abierto a la vez (acordeón real, no simplemente colapsable independiente) — mantiene la interfaz limpia en pantallas táctiles pequeñas.

```html
<nav class="dash-nav" data-dash-nav>
    <div class="dash-accordion" data-accordion-group>
        <button type="button" class="dash-accordion__trigger" data-accordion-trigger aria-expanded="false">
            {{GRUPO_NOMBRE}}
        </button>
        <ul class="dash-accordion__panel" data-accordion-panel hidden>
            <li><a href="#{{ANCLA_1}}" class="dash-nav__link">{{ITEM_1}}</a></li>
            <li><a href="#{{ANCLA_2}}" class="dash-nav__link">{{ITEM_2}}</a></li>
        </ul>
    </div>
    <!-- ...un .dash-accordion por grupo -->
</nav>
```

```javascript
function initAccordionNav() {
    document.querySelectorAll('[data-accordion-trigger]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            const grupo = trigger.closest('[data-accordion-group]');
            const panel = grupo.querySelector('[data-accordion-panel]');
            const abrirlo = trigger.getAttribute('aria-expanded') !== 'true';

            // Cierra todos los demás grupos — solo uno abierto a la vez.
            document.querySelectorAll('[data-accordion-trigger]').forEach(function (otro) {
                otro.setAttribute('aria-expanded', 'false');
                otro.closest('[data-accordion-group]').querySelector('[data-accordion-panel]').hidden = true;
            });

            trigger.setAttribute('aria-expanded', String(abrirlo));
            panel.hidden = !abrirlo;
        });
    });
}
```

- La transición de apertura anima `max-height`/`opacity` de forma atómica (o `grid-template-rows: 0fr → 1fr` si el proyecto consumidor soporta ese patrón moderno) — nunca `height: auto` directo, que no es animable de forma nativa.
- Cerrar todos los demás grupos al abrir uno nuevo es intencional (Sección de negocio: "Anti-Crowding") — evita que el usuario pierda contexto con múltiples paneles largos abiertos simultáneamente en una pantalla pequeña.

### 5.3.2 Conmutador de Paneles (Anti-Saturación del Área de Trabajo)

El acordeón (Sección 5.3.1) resuelve el amontonamiento del **menú**; este patrón resuelve el amontonamiento del **contenido**. La pantalla de inicio del Dashboard, inmediatamente después del login, muestra **únicamente** el bloque de bienvenida (Sección 10) — ningún módulo operativo (alta de usuarios, configuraciones, ledgers, etc.) se renderiza visible por defecto.

```html
<!-- Cada sección operativa nace oculta -->
<section id="{{MODULO_ID}}" class="dash-panel" hidden>...</section>

<!-- Cada disparador de navegación declara qué panel(es) activa -->
<a href="#{{MODULO_ID}}" class="dash-nav__link" data-panel-target="{{MODULO_ID}}">{{ETIQUETA}}</a>
```

```javascript
function initDashPanelSwitcher() {
    const disparadores = document.querySelectorAll('[data-panel-target]');
    const paneles = document.querySelectorAll('.dash-panel');

    function mostrarPaneles(destino) {
        const idsVisibles = destino === '' ? [] : destino.split(',');
        paneles.forEach(function (panel) {
            panel.hidden = !idsVisibles.includes(panel.id);
        });
    }

    disparadores.forEach(function (el) {
        el.addEventListener('click', function (event) {
            const href = el.getAttribute('href');
            if (el.tagName === 'A' && href && href.startsWith('#')) {
                event.preventDefault(); // nunca el salto de ancla nativo del navegador
            }
            mostrarPaneles(el.dataset.panelTarget);
        });
    });
}
```

- `data-panel-target` acepta una lista separada por comas — un disparador de **grupo** (ej. el trigger del acordeón "{{GRUPO_NOMBRE}}") puede revelar varios paneles relacionados a la vez (todo lo que pertenece a esa área de producto), mientras que un disparador de **ítem específico** dentro del grupo típicamente apunta a un solo panel.
- `data-panel-target=""` (cadena vacía, ej. el ítem "Inicio") es el estado de reposo — oculta todos los paneles y deja visible solo el bloque de bienvenida.
- Un panel oculto por `hidden` **nunca** se retira del DOM — sigue siendo el mismo formulario/tabla con su estado, solo se re-muestra; evita perder el progreso de un formulario a medio llenar por navegar a otra sección y volver.

### 5.3.3 Jerarquía Ejecutiva por Widgets (Crowd Control dentro de un panel)

Una vez dentro de un panel operativo (Sección 5.3.2), el contenido **tampoco** se presenta como una sábana plana de formularios amontonados uno tras otro. Se ordena en tres capas de prioridad visual, cada una un bloque (`{{WIDGET_CLASS}}`) independiente y visualmente distinto del resto:

- **Sección A — Acceso Rápido / Planeador:** lo primero que ve el operador al entrar al panel — un solo widget compacto y destacado (fondo elevado + acento de marca) que concentra la acción operativa que más se repite en el día a día. Si esa acción histórica requería varios formularios encadenados (ej. crear un evento + adjuntar un archivo + redactar un mensaje, antes en 2-3 formularios y 2-3 endpoints separados), se fusiona en **un solo formulario y un solo endpoint** que reciba todo junto (`multipart/form-data` si incluye archivo) — el operador ejecuta un único botón de acción central, no una secuencia de pasos.
- **Sección B — Métricas Activas en Tarjetas (KPIs):** justo debajo, un grid de tarjetas (`{{TIMETABLE_PANEL}}`) que responde "¿cómo va todo ahora mismo?" sin que el operador tenga que abrir nada ni hacer clic — cada tarjeta es un solo dato (fecha, contador, estado activo), nunca una tabla completa.
- **Sección C — Historial / Ledger:** al final, comprimido y con menor peso visual, el registro histórico tabular (Sección 9.3/5 de los módulos de auditoría) — es información de consulta ocasional, no la razón por la que el operador abrió el panel.

Este ordenamiento (Acción → Estado → Historial) es el patrón por defecto para **cualquier** panel operativo del Dashboard con más de un formulario o tabla — no solo el que lo originó.

### 5.4 Controles de Navegación Globales

**Scroll-to-Top:** botón flotante fijo (esquina inferior, `position: fixed`) oculto por defecto, que se activa vía clase (`opacity`/`transform`, nunca `display` a secas para permitir transición) cuando `window.scrollY` supera un umbral (ej. 400px). Reutiliza el mismo patrón atómico de transición ya establecido en este módulo (Sección 4.5/5.3) — nunca dispara reflow.

**Toggle Día/Noche (Light/Dark):** conmutador visual persistente (ej. `localStorage`) que alterna un atributo (`data-theme="light|dark"`) en la raíz del documento. **Nunca** se implementa duplicando reglas CSS por selector — las variables de tema (Sección 4.1) se redeclaran dentro de un bloque `[data-theme="light"]`, y todo el resto del sistema (que ya consume esas variables) cambia de apariencia automáticamente sin tocar ninguna otra regla:

```css
:root, [data-theme="dark"] {
    --auth-bg: {{COLOR_FONDO_OSCURO}};
    --auth-text: {{COLOR_TEXTO_SOBRE_OSCURO}};
}

[data-theme="light"] {
    --auth-bg: {{COLOR_FONDO_CLARO}};
    --auth-text: {{COLOR_TEXTO_SOBRE_CLARO}};
}
```

```javascript
function initThemeToggle() {
    const toggle = document.querySelector('[data-theme-toggle]');
    const root = document.documentElement;
    const guardado = localStorage.getItem('{{PROJECT_NAME}}_theme');
    if (guardado) {
        root.setAttribute('data-theme', guardado);
    }

    if (!toggle) {
        return;
    }

    toggle.addEventListener('click', function () {
        const actual = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', actual);
        localStorage.setItem('{{PROJECT_NAME}}_theme', actual);
    });
}
```

- Aplica en **todo el portal administrativo** (Dashboard y páginas de acceso) — el proyecto consumidor decide si también lo extiende a su sitio público, evaluando si contradice una identidad de marca de paleta fija ya establecida (Regla de Oro, Sección 4.4).
- El contraste WCAG ≥ 4.5:1 se valida en **ambos** temas, no solo en el default.
- **Bug de contraste recurrente a vigilar:** un fondo con color hardcodeado (ej. `background-color: rgba(16, 20, 30, 0.92)` fijo en una barra superior) combinado con un texto que sí usa `var(--auth-text)` produce texto invisible en cuanto el tema activo cambia — el texto conmuta correctamente pero el fondo se queda fijo. **Todo** elemento de la interfaz administrativa que tenga fondo propio debe usar una variable de tema (`var(--auth-bg-elevated)` o equivalente), nunca un valor de color fijo, sin excepción — ni siquiera "solo para el efecto de transparencia".
- **Logo como imagen rasterizada (PNG/SVG con texto incrustado):** un logo `{{LOGO_PATH}}` con texto horneado en el propio archivo (no HTML/CSS real) **no puede** conmutar de color vía `var()` — el texto vive dentro de los píxeles de la imagen. Si el logo está diseñado en tono claro para el fondo oscuro oficial, se pierde sobre un fondo claro en modo Día. Solución sin generar un segundo archivo de logo: invertir la imagen dinámicamente vía filtro CSS, condicionado al tema activo:

```css
body[data-theme="light"] .{{CLASE_LOGO}} {
    filter: invert(1) brightness(0.2);
}
```

- Esta técnica solo es válida si el logo es monocromático o de bajo contraste cromático (texto claro sobre fondo transparente) — un logo con múltiples colores con significado propio (ej. un isotipo con paleta de marca) **no** debe invertirse así, porque `invert()` also invierte el matiz de cada color, no solo su luminosidad; en ese caso el proyecto consumidor necesita dos variantes de archivo reales, una por tema.

### 5.5 Validación de Entorno en cada página del Dashboard

- Toda página del Dashboard valida, **antes de renderizar cualquier contenido**, la cookie de sesión (Sección 3.1) server-side — mismo guard de `dashboard.php` (Sección 5.1), nunca una validación solo-cliente que permita el "flash" de contenido protegido.
- Toda página HTML del sistema (pública o del Dashboard) declara `<link rel="icon" href="{{FAVICON_PATH}}">` en el `<head>` — el proyecto consumidor asegura que el archivo exista físicamente antes de considerar la página "terminada" (look & feel premium, cero íconos rotos en la pestaña del navegador).

---

## 6. 👑 MATRIZ DE ROLES Y JERARQUÍA EXTENSIBLE

| Rol | Nivel | Alcance |
| :--- | :---: | :--- |
| `super_admin` | 100 | Acceso total. Único rol que puede: provisionar/revocar `admin`, gestionar la Política de Contraseña activa (Sección 7.3), ver la bitácora completa sin filtros, ejecutar acciones irreversibles de sistema. Es el usuario creado por el First-Run Provisioning (Sección 8) — **solo puede existir uno por instancia**, salvo que el proyecto consumidor documente explícitamente lo contrario en su Codex. |
| `admin` | 80 | Gestor operativo. Puede dar de alta usuarios (Sección 9, Métodos A y B), gestionar `roles_variables`, pero **no** puede modificar ni revocar a un `super_admin`, ni cambiar la Política de Contraseña activa. |
| `{{ROL_VARIABLE_1}}` … `{{ROL_VARIABLE_N}}` | `{{NIVEL}}` (< 80) | Espacio extensible — el proyecto consumidor define aquí sus roles de negocio (`operador`, `soporte`, `moderador`, etc.) y su alcance de permisos, registrándolos en su propio Codex antes de usarlos en código (Soberanía de Nomenclatura). |

**Reglas de jerarquía:**
- La comparación de permisos siempre es **por nivel numérico**, nunca por cadena de texto (`if ($usuario->nivel_rol >= 80)`), para que agregar un rol variable no rompa condicionales existentes.
- Un rol puede otorgar su propio nivel o uno inferior, **nunca** uno superior al propio (`admin` puede crear otro `admin`, pero jamás un `super_admin` — el backend recorta el rol solicitado al máximo permitido, sin confiar en el payload).
- El campo `rol` de la tabla `{{TABLE_PREFIX}}usuarios` (Sección 1.1) se extiende de `ENUM('admin','editor','usuario')` a `ENUM('super_admin','admin','{{ROL_VARIABLE_1}}', ...)` **solo** cuando el proyecto consumidor haya definido su lista real de roles variables en su Codex — este cambio de ENUM es, en sí mismo, una alteración de schema y requiere la misma autorización explícita que cualquier otra (Mandamiento 9).

### 6.1 Mapeo Dinámico de Permisos por Módulo

El `super_admin` tiene **siempre** visibilidad absoluta de todo el sistema — esto **no** es configurable, es una garantía de la Sección 6. Lo que sí es configurable en runtime es qué módulos ve el rol `admin` (y, si existen, los `roles_variables`).

```sql
CREATE TABLE `{{TABLE_PREFIX}}permisos_modulos` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `modulo`           VARCHAR(60)  NOT NULL COMMENT 'Identificador estable del módulo, ej. "usuarios", "seguridad", "{{MODULO_X}}"',
    `visible_para_rol` VARCHAR(30)  NOT NULL COMMENT 'Rol al que aplica esta fila — nunca "super_admin" (siempre ve todo)',
    `habilitado`       TINYINT(1)   NOT NULL DEFAULT 1,
    `actualizado_en`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_{{TABLE_PREFIX}}permisos_modulo_rol` (`modulo`, `visible_para_rol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **Fail-safe explícito:** si un módulo no tiene fila para un rol dado, el default es `habilitado = 1` (visible) — la matriz sirve para **restringir** selectivamente, no para exigir que cada módulo nuevo se registre manualmente o desaparezca por omisión. Este comportamiento se documenta en el Codex del proyecto consumidor, nunca se asume implícito en el código.
- El endpoint de mutación (ej. `permisos_modulos.php`) exige `requireAuth($pdo, ['super_admin'])` — igual patrón que la Sección 7.3.
- El frontend del Dashboard (Sección 5) consulta esta matriz **server-side**, en la misma página que ya valida la cookie (Sección 5.5) — un módulo deshabilitado ni siquiera se envía al HTML, no se oculta con CSS. Ocultar con CSS un módulo que el HTML igual entrega es una fuga de información (Capa 2 rota en la práctica).

---

## 7. 🎛️ MOTOR DINÁMICO DE POLÍTICAS DE CONTRASEÑA

Reemplaza el "estándar único de 14 caracteres" de versiones anteriores de este módulo por **tres perfiles canónicos configurables en runtime**, seleccionables por el `super_admin` desde el Dashboard, y leídos dinámicamente tanto por el backend (Capa 4 de cualquier endpoint que reciba una contraseña) como por el medidor de fuerza del frontend (Sección 4.6).

### 7.1 Perfiles canónicos

| Perfil | Valor ENUM | Requisitos |
| :--- | :---: | :--- |
| 1. Sencilla / Simple | `simple` | Mínimo 6 caracteres, cualquier tipo. |
| 2. Mediana / Medium | `media` | Mínimo 8 caracteres, combinación obligatoria de letras **y** números. |
| 3. Fuerte / Strong | `fuerte` | Mínimo 14 caracteres, con mayúscula, minúscula, número **y** símbolo especial obligatorios. |

```php
function politicaSeguridadDefinicion(string $perfil): array
{
    return match ($perfil) {
        'simple' => ['longitud_minima' => 6, 'requiere_mayuscula' => false, 'requiere_minuscula' => false, 'requiere_numero' => false, 'requiere_simbolo' => false],
        'media'  => ['longitud_minima' => 8, 'requiere_mayuscula' => false, 'requiere_minuscula' => true, 'requiere_numero' => true, 'requiere_simbolo' => false],
        'fuerte' => ['longitud_minima' => 14, 'requiere_mayuscula' => true, 'requiere_minuscula' => true, 'requiere_numero' => true, 'requiere_simbolo' => true],
        default  => politicaSeguridadDefinicion('media'),
    };
}

function passwordCumplePolitica(string $password, array $definicion): bool
{
    if (mb_strlen($password) < $definicion['longitud_minima']) {
        return false;
    }

    if ($definicion['requiere_mayuscula'] && preg_match('/[A-Z]/', $password) !== 1) {
        return false;
    }

    if ($definicion['requiere_minuscula'] && preg_match('/[a-z]/', $password) !== 1) {
        return false;
    }

    if ($definicion['requiere_numero'] && preg_match('/[0-9]/', $password) !== 1) {
        return false;
    }

    if ($definicion['requiere_simbolo'] && preg_match('/[^a-zA-Z0-9]/', $password) !== 1) {
        return false;
    }

    return true;
}
```

### 7.2 Lectura de la política activa (única fuente de verdad: `{{TABLE_PREFIX}}configuracion_seguridad`)

```php
function obtenerPoliticaActiva(PDO $pdo): string
{
    $stmt = $pdo->query('SELECT politica_password FROM {{TABLE_PREFIX}}configuracion_seguridad WHERE id = 1 LIMIT 1');
    $fila = $stmt->fetch();

    return $fila['politica_password'] ?? 'media'; // 'media' = fallback si la fila no existe aún
}
```

- **Todo** endpoint que reciba una contraseña nueva (First-Run Provisioning, Método A, confirmación de invitación, cambio de contraseña) llama a `obtenerPoliticaActiva()` y valida con `passwordCumplePolitica()` — ninguno hardcodea su propio umbral. Esto incluye al propio First-Run Provisioning (Sección 8.2): al leer de una tabla de configuración sembrada en la misma migración (Sección 1.3), no hay problema de "huevo y gallina" con la tabla `usuarios` vacía.
- El endpoint público de solo-lectura (Sección 7.4) expone la `politicaSeguridadDefinicion()` resultante (no el nombre crudo del ENUM únicamente) para que el medidor de fuerza del frontend (Sección 4.6) calibre sus umbrales sin duplicar las reglas en JavaScript.

### 7.3 Módulo exclusivo del `super_admin` en el Dashboard

- Endpoint de mutación (ej. `configuracion_seguridad.php`, método `PUT`/`POST`) exige `requireAuth($pdo, ['super_admin'])` — **ningún** otro rol puede cambiar la política activa (Capa 2).
- El Dashboard renderiza los 3 perfiles como tarjetas seleccionables bajo el patrón ARF-Grid (Sección 5.3) — nunca un `<select>` nativo sin estilo, para mantener consistencia visual con el resto del sistema.
- Cambiar la política **no** re-valida retroactivamente las contraseñas ya almacenadas (los hashes existentes siguen siendo válidos) — solo afecta a la próxima contraseña que se cree o cambie.

### 7.4 Endpoint de lectura pública (sin autenticación)

- Ruta de solo lectura (ej. `GET configuracion_seguridad.php`) — **sin** `requireAuth()`, porque las páginas públicas de creación de contraseña (First-Run Provisioning, aceptación de invitación) necesitan calibrar su medidor de fuerza (Sección 4.6) antes de que exista cualquier sesión.
- Responde únicamente la definición de umbrales (`longitud_minima`, `requiere_mayuscula`, etc.) — **nunca** información de usuarios, tokens, ni conteos que pudieran ayudar a un atacante a enumerar el estado del sistema.

### 7.5 Duración de "Mantenerse Registrado" (mismo módulo del `super_admin`)

- El mismo endpoint de mutación de la Sección 7.3 acepta también `duracion_recordarme_dias` — un único formulario/panel gestiona ambas configuraciones de seguridad (política de contraseña + persistencia de sesión), no dos módulos separados.
- Solo dos valores válidos: `60` (2 meses) o `120` (4 meses) — cualquier otro valor se rechaza en Capa 4 con 422. El Dashboard los renderiza como opciones explícitas (radio/select estilizado), nunca un input numérico libre.
- El endpoint de lectura pública (Sección 7.4) también expone `duracion_recordarme_dias` — el checkbox "Mantenerse registrado" del login (Sección 3.5) no necesita autenticación previa para saber cuánto va a durar la sesión que está a punto de crear.

---

## 8. 🚀 LOOP DE PRIMER ARRANQUE: CONFIGURACIÓN GÉNESIS (FIRST-RUN PROVISIONING)

### 8.1 Detección de estado "vacío"

```php
function sistemaRequiereProvisioning(PDO $pdo): bool
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM {{TABLE_PREFIX}}usuarios');
    return (int) $stmt->fetchColumn() === 0;
}
```

- Esta verificación se ejecuta en el punto de entrada del sistema (bootstrap/router), **antes** de resolver cualquier otra ruta.
- Si `sistemaRequiereProvisioning() === true`, toda ruta que no sea la de inicialización redirige (302) a `{{RUTA_INICIALIZACION}}` (ej. `/setup-genesis.php`).
- Si es `false`, `{{RUTA_INICIALIZACION}}` responde **403 Forbidden** de forma determinista y permanente (Sección 8.4) para cualquier intento de mutación (`POST`) — nunca 200, nunca expone si "existió antes" o no.

### 8.2 Formulario público temporal — política de contraseña dinámica

Campos: `nombre`, `email`, `password`, `password_confirmacion`.

- La política de contraseña **no está hardcodeada** en este paso — se lee vía `obtenerPoliticaActiva()` (Sección 7.2) igual que cualquier otro endpoint de creación/cambio de contraseña. El proyecto consumidor decide, mediante la fila sembrada en `{{TABLE_PREFIX}}configuracion_seguridad` (Sección 1.3), si el arranque inicial exige el perfil `simple`, `media` o `fuerte` — este módulo no impone un mínimo especial "solo para el root" distinto del resto del sistema.
- Rechazo explícito si coincide con listas de contraseñas comprometidas conocidas (integración opcional con un servicio de *pwned passwords* — si el proyecto consumidor no tiene ese servicio disponible, se documenta como pendiente, no se omite en silencio).
- `password === password_confirmacion` se valida en el **backend**, nunca solo en el frontend.

### 8.3 Mutación de creación — doble función (provisioning + healthcheck)

```php
try {
    $pdo->beginTransaction();

    $definicion = politicaSeguridadDefinicion(obtenerPoliticaActiva($pdo));
    if (!passwordCumplePolitica($password, $definicion)) {
        throw new InvalidArgumentException('La contraseña no cumple la política activa.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO {{TABLE_PREFIX}}usuarios (nombre, email, password_hash, rol, estatus)
         VALUES (:nombre, :email, :password_hash, :rol, :estatus)'
    );
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':password_hash', password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), PDO::PARAM_STR);
    $stmt->bindValue(':rol', 'super_admin', PDO::PARAM_STR);
    $stmt->bindValue(':estatus', 'activo', PDO::PARAM_STR);
    $stmt->execute();

    $pdo->commit();
    // Éxito = validador determinístico de que PDO y la integridad física de la BD
    // están operando al 100%. No se requiere un endpoint de healthcheck separado
    // para esta verificación puntual — la propia mutación lo demuestra.
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('[GENESIS_PROVISIONING] ' . $e->getCode() . ' — ' . $e->getMessage());
    // Respuesta genérica de Capa 6 — nunca detalle de $e al frontend
}
```

### 8.4 Auto-deshabilitación de la ruta

- Inmediatamente después del `COMMIT` exitoso, cualquier request subsecuente a `{{RUTA_INICIALIZACION}}` debe evaluar `sistemaRequiereProvisioning()` de nuevo y responder **403 Forbidden** de forma determinista — la ruta no se "elimina" del código (no hay redeploy), se **autodesactiva por estado de datos**, que es la única fuente de verdad.
- No debe existir una variable de entorno o flag de config que reabra esta ruta — la única forma de volver a provisionar es un estado de BD verdaderamente vacío (ej. entorno de desarrollo reseteado), nunca una reactivación manual en producción.
- El código 403 (en vez de 200 con un mensaje de error) es deliberado: un atacante automatizando reintentos contra esta ruta recibe siempre la misma respuesta determinista, sin importar cuántas veces lo intente ni qué payload envíe.

---

## 9. 🔄 FLUJO DUAL DE INVITACIÓN Y ALTA DE USUARIOS

### 9.0 Descripción Pedagógica (obligatoria en la UI)

La sección de alta de usuarios del Dashboard **nunca** presenta los dos métodos como formularios desnudos — cada uno lleva una descripción breve, amigable y en lenguaje llano (no jerga técnica) explicando cuándo usarlo, dirigida al perfil real de quien administra el sistema (`{{ADMIN_NAME}}`, no necesariamente una persona técnica). Ejemplo de tono (a adaptar por el proyecto consumidor, nunca copiar literal entre proyectos — Mandamiento 10):

> **Método A:** "Usa esto cuando ya conoces a la persona y quieres darle acceso de inmediato — tú defines su contraseña inicial."
> **Método B:** "Usa esto cuando prefieras que la persona elija su propia contraseña de forma segura — le llega un correo con un enlace único."

### 9.1 Método A — Creación Directa

- Endpoint (ej. `usuarios_crear.php`) exige rol `admin` o superior (Capa 2).
- Campos: `nombre`, `email`, `password` (el propio administrador la define o el sistema genera una temporal — decisión de producto del proyecto consumidor), **`rol`** (asignado explícitamente por quien crea al usuario, no un default silencioso). Se valida con la misma `obtenerPoliticaActiva()` / `passwordCumplePolitica()` de la Sección 7.
- Jerarquía (Sección 6): el `rol` solicitado se recorta siempre al nivel máximo que el actor puede otorgar — un `admin` nunca puede asignar un rol de nivel igual o superior al propio, sin importar qué envíe el payload.
- `estatus = 'activo'` inmediato — sin paso intermedio.
- Caso de uso: administrador que da de alta a alguien presencialmente o por un canal ya verificado fuera de banda.

### 9.2 Método B — Invitación Segura por Plantilla

**Paso 1 — Alta en `pendiente` + token de invitación**

Requiere una tabla adicional (sujeta a autorización explícita antes de crearse en un proyecto consumidor real, igual que cualquier tabla nueva — Mandamiento 9):

```sql
CREATE TABLE `{{TABLE_PREFIX}}invitaciones` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`      BIGINT UNSIGNED NOT NULL,
    `token_hash`      CHAR(64)        NOT NULL COMMENT 'SHA-256 del token — el token en claro solo viaja en el email, nunca se persiste',
    `expira_en`       DATETIME        NOT NULL,
    `usado`           TINYINT(1)      NOT NULL DEFAULT 0,
    `creado_en`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_{{TABLE_PREFIX}}invitaciones_token_hash` (`token_hash`),
    KEY `idx_{{TABLE_PREFIX}}invitaciones_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```php
$tokenClaro = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $tokenClaro);
// INSERT usuarios (nombre, email, rol, estatus='pendiente', password_hash=NULL)
//   — el mismo recorte de jerarquía de la Sección 9.1 aplica aquí: el actor
//   nunca puede invitar a alguien con un rol de nivel igual o superior al propio.
// INSERT invitaciones (usuario_id, token_hash, expira_en = NOW() + INTERVAL {{TTL_INVITACION}} HOUR)
// El enlace firmado ({{RUTA_INVITACION}}?token=$tokenClaro) viaja SOLO por la
// plantilla de correo transaccional — nunca se muestra en pantalla ni se loguea.
```

**Paso 2 — Vista standalone de aceptación**

- Ruta pública temporal por invitación (ej. `/invitacion.php?token=...`), sin sesión previa.
- Valida: `hash('sha256', $tokenRecibido) === token_hash` **y** `expira_en > NOW()` **y** `usado = 0` — las tres condiciones, sin excepción.
- Formulario: `password`, `password_confirmacion` — validado con la política activa (Sección 7), igual que el resto del sistema. Incluye visibility toggle y medidor de fuerza (Sección 4.6).
- Comparación de tokens con `hash_equals()` (comparación segura contra timing attacks), nunca `===` directo sobre el hash.

**Paso 3 — Confirmación y activación**

```php
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'UPDATE {{TABLE_PREFIX}}usuarios SET password_hash = :hash, estatus = :estatus WHERE id = :id'
    );
    $stmt->bindValue(':hash', password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), PDO::PARAM_STR);
    $stmt->bindValue(':estatus', 'activo', PDO::PARAM_STR);
    $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
    $stmt->execute();

    $stmt = $pdo->prepare('UPDATE {{TABLE_PREFIX}}invitaciones SET usado = 1 WHERE id = :id');
    $stmt->bindValue(':id', $invitacionId, PDO::PARAM_INT);
    $stmt->execute();

    $pdo->commit();
    // El usuario queda 'activo' y puede autenticarse de inmediato en el Login
    // principal — no requiere paso adicional ni segunda confirmación por correo.
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('[INVITACION_CONFIRM] ' . $e->getCode() . ' — ' . $e->getMessage());
}
```

- Una invitación usada (`usado = 1`) o expirada responde siempre el mismo mensaje genérico ("Este enlace ya no es válido"), sin distinguir la causa — mismo principio anti-enumeración de la Sección 2.2.
- El envío de la plantilla de correo transaccional (SMTP, proveedor, diseño del email) es responsabilidad de infraestructura del proyecto consumidor — este módulo define el contrato del token y el flujo, no el transporte de correo.

### 9.3 Registro de Ingreso (Auditoría de Accesos Exitosos)

Subsección exclusiva de `super_admin` — quién entró, cuándo y desde dónde. A diferencia de los módulos que sí pasan por el Mapeo Dinámico de Permisos (Sección 6.1, donde un `super_admin` puede habilitar visibilidad para `admin`), este módulo de auditoría **nunca** es delegable — ningún rol distinto de `super_admin` puede verlo, ni siquiera si el `super_admin` lo intentara habilitar en la matriz. El blindaje es doble y redundante (defensa en profundidad):

- **Capa de presentación:** el bloque HTML completo del ledger vive detrás de un `if ($rolActor === 'super_admin')` explícito y separado del resto de condicionales de visibilidad de módulo — nunca depende de `esModuloVisible()`.
- **Capa de API:** todo endpoint que lee o muta este ledger (listado paginado, borrado) exige `requireAuth($pdo, ['super_admin'])` de forma literal — un intento de `admin` de consumir el endpoint directamente (sin pasar por la UI) recibe `403 Forbidden` igual que si intentara acceder sin sesión válida en absoluto. La UI oculta el enlace; el backend es quien realmente decide.

```sql
ALTER TABLE `{{TABLE_PREFIX}}log_actividad`

```sql
ALTER TABLE `{{TABLE_PREFIX}}log_actividad`
    ADD COLUMN `ip` VARCHAR(45) NULL AFTER `device_hash`,
    ADD COLUMN `ip_pais` VARCHAR(80) NULL AFTER `ip`,
    ADD COLUMN `ip_estado` VARCHAR(80) NULL AFTER `ip_pais`,
    ADD COLUMN `ip_ciudad` VARCHAR(80) NULL AFTER `ip_estado`;
```

- Estas columnas se pueblan **únicamente** en el evento `login_exitoso` — nunca en `login_fallido` ni en ningún otro evento. Resolver geolocalización en cada intento fallido abriría una vía de amplificación: un atacante de fuerza bruta podría agotar el límite de tasa del proveedor externo de geolocalización, o simplemente ralentizar el endpoint de login con latencia de red innecesaria en su ruta más sensible.
- El resto de eventos siguen usando exclusivamente `ip_hash` (Sección 1.2) para correlación sin exponer PII — este registro "en claro" es la excepción deliberada, limitada al único evento que el `super_admin` necesita auditar con detalle real.

**Anclaje de zona horaria (obligatorio, evita bugs latentes de negocio):**

- MySQL/MariaDB almacena `created_at` (`TIMESTAMP`/`DATETIME` con `CURRENT_TIMESTAMP`) en **UTC** por defecto. PHP, si no se le indica explícitamente una zona horaria, hereda la del sistema operativo del servidor — que puede no coincidir ni con UTC ni con la zona operativa real del proyecto. Esto es un bug silencioso: cualquier cálculo de fecha de negocio (recordatorios, "próximo martes/jueves a las 20:30", vigencias de token) queda ejecutándose en una zona horaria arbitraria e indocumentada.
- **Fix obligatorio:** anclar `date_default_timezone_set('{{TIMEZONE}}')` (ej. `America/Mexico_City`) una sola vez, en el archivo de arranque/helpers que se carga en (casi) todas las rutas — nunca repetido por archivo, nunca omitido.
- **Conversión de UTC a la zona de exhibición:** si la zona operativa de negocio difiere de la zona de exhibición al usuario final (ej. negocio anclado en `America/Mexico_City` pero el público objetivo está en `{{TIMEZONE_EXHIBICION}}`, ej. `America/Mazatlan`), construir un formateador dedicado que reciba el string UTC crudo de la BD y devuelva el string ya convertido — nunca convertir "a mano" con sumas/restas de horas (no contempla horario de verano ni offsets fraccionarios).
- **Formato regional estricto, configurable por el dueño del proyecto:** si el proyecto exige abreviaturas de mes en un idioma específico (ej. español: `Ene`, `Feb`, `Mar`...), no depender de `ext-intl` ni de la locale del servidor (impredecible entre entornos local/staging/producción) — mapear el mes numérico a un arreglo estático propio. El **orden** de los componentes (día-mes vs. mes-día) y el **padding** (día/hora con o sin ceros a la izquierda) son decisiones de formato regional que el dueño del proyecto define — no hay un único formato "correcto" universal. Dos formas de ejemplo igualmente válidas, según lo que pida el proyecto consumidor: `16 Ene 2026, 8:05 am` (día-mes, sin padding) o `Ene 16, 2026, 08:05 am` (mes-día, con padding de 2 dígitos en día y hora). Siempre `am`/`pm` en minúsculas salvo que el proyecto pida lo contrario.

### 9.4 Plantilla Premium del Correo de Invitación (Estética de Marca Configurable)

El correo transaccional de invitación **no** es un `<p>` suelto — es una pieza de marca. Reglas de construcción:

- **Campos 100% dinámicos, cero hardcodeo:** la función constructora recibe como parámetros el nombre y el `{{USER_EMAIL}}` **exactos** capturados en el formulario del operador — nunca un valor de ejemplo ni una cuenta de prueba escrita directamente en el cuerpo del mensaje. La única excepción documentada y deliberada es el botón de previsualización en staging (Sección de auditoría del Dashboard), que no tiene un destinatario real todavía y por eso usa un nombre de ejemplo explícitamente marcado como tal en el código — nunca se confunde con el flujo de producción. El transporte de red puede desviarse a una cuenta de auditoría mientras `{{APP_ENV}} != production` (Sección de staging, más abajo), pero el HTML interno del correo siempre refleja al destinatario real — mostrar el `{{USER_EMAIL}}` real dentro del propio cuerpo (no solo en la cabecera SMTP `To:`) es lo que permite auditar visualmente esa dinamización sin tener que inspeccionar cabeceras crudas.
- **Tabla HTML + estilos inline, siempre.** Ningún cliente de correo relevante (Outlook, Yahoo, Gmail en ciertos modos) respeta `<style>` externo ni la mayoría del CSS moderno (flexbox/grid) — el layout se resuelve con `<table role="presentation">` anidadas y cada regla visual va inline, `style="..."` directo en el tag. Esta es la **única** excepción documentada a la Regla de Oro de "cero estilos inline" (Sección 4.4) — esa regla gobierna el HTML de la aplicación web, no el HTML que se transmite como cuerpo de un correo.
- **Escala tipográfica ejecutiva:** el correo se lee en pantallas de celular con la app de correo en modo compacto — un cuerpo de texto por debajo de ~16-17px y un título por debajo de ~24px se perciben "pequeños y planos" en ese contexto, incluso si son legibles en desktop. El tamaño base del cuerpo, el título principal y el botón de acción se calibran deliberadamente por encima de los mínimos web habituales para lograr una sensación premium/ejecutiva, no solo "legible".
- **Fondo oscuro absoluto:** contenedor principal en `{{COLOR_FONDO_OSCURO}}` (ej. `#10141E`), envuelto en un fondo exterior aún más oscuro para el "letterboxing" en clientes que centran el contenido.
- **Acentos lumínicos:** bordes, `<hr>` y el botón de acción principal en `{{COLOR_ACENTO_NEON}}` (ej. `#E91E63`) — simulan la luz de neón de estudio de la identidad de marca.
- **Elementos de marca obligatorios:** logo con `src` de **URL absoluta** (`{{APP_URL}}/{{LOGO_PATH}}` — nunca una ruta relativa, los clientes de correo no tienen noción del dominio del sitio) y el eslogan/tagline oficial del proyecto, en cursiva, discreto pero presente.
- **Botón "bulletproof":** el CTA principal se construye como una `<table>` de una celda con `bgcolor` + `border-radius` en el `<td>`, no como un `<button>` ni un `<a>` con `display:inline-block` suelto — Outlook desktop (motor Word) ignora `border-radius` y buena parte del `padding` en enlaces sueltos, pero sí respeta celdas de tabla.
- **Ecosistema de enlaces:** además del CTA principal (acción central, ej. "Configurar Mi Contraseña y Acceder al Dashboard"), una fila secundaria de enlaces de texto hacia las rutas públicas principales del proyecto (`{{URL_HOME}}`, `{{URL_MODULO_DESTACADO}}`) — el correo es también una puerta de entrada al resto del ecosistema, no solo al flujo de activación.
- **Tono:** universal, empático, laico, protector — sin lenguaje corporativo genérico ni urgencia artificial ("¡Actúa ya!"). El proyecto consumidor adapta el copy exacto a su propia voz de marca, documentada en su Codex.
- **Reutilización:** la plantilla vive en **una sola función** (ej. `construirPlantillaInvitacion()`) consumida tanto por el flujo real de invitación como por cualquier botón de previsualización en staging (Sección de auditoría del Dashboard) — nunca se duplica el HTML entre ambos, evita que diverjan con el tiempo.
- **Blindaje anti-SPAM (entregabilidad):** para que proveedores como Gmail/Yahoo confíen en el remitente, todo correo transaccional masivo-potencial incluye, como bloque final del cuerpo (después del cierre de marca), un pie de cumplimiento con: (a) un bloque formal tipo dirección postal — nombre del proyecto, una línea descriptiva y una referencia geográfica general (`{{REGION_OPERATIVA}}`) — y (b) un enlace funcional de baja/unsubscribe — mínimo viable: `mailto:{{CORREO_CONTACTO}}` con `subject` prellenado, reutilizando la misma cuenta SMTP configurada en `.env` (`{{SMTP_USER}}`), nunca una dirección nueva hardcodeada. **Regla Anti-Alucinación aplicada aquí (Mandamiento 4):** si el proyecto consumidor no tiene todavía una dirección postal física real y verificable, el bloque nunca se rellena con una calle/número inventados — se usa el formato descriptivo de arriba (nombre + descripción + región) hasta que el dueño del proyecto provea la dirección real; una dirección falsa es peor para la entregabilidad a largo plazo que un formato genérico honesto. **Distinción clave:** una región operativa (ciudad/estado/país) que el dueño del proyecto **confirma explícitamente** (ej. en una instrucción directa) deja de ser una alucinación en cuanto se usa — sí puede incorporarse al `{{REGION_OPERATIVA}}` del bloque; lo que sigue prohibido es que el agente **invente** por su cuenta una calle, número o código postal que nadie le proporcionó. Este bloque va con la misma tipografía discreta (tamaño reducido, color atenuado) para no competir visualmente con el CTA principal.

### 9.5 Controlador Central de Usuarios (Panel Exclusivo `super_admin`)

Tabla responsiva en el Dashboard que lista **todos** los usuarios del sistema (Nombre, Correo, Rol, Estatus con badge de color por estado — `{{USER_STATUS}}` ∈ `activo`/`pendiente`/`suspendido`) con tres acciones por fila, cada una contra un endpoint independiente de 6 capas, exclusivo de `super_admin`:

- **Resetear Contraseña:** invalida el hash actual (`password_hash = NULL`), regresa el `estatus` a `pendiente`, revoca cualquier sesión activa del usuario (`token_acceso = NULL`) y genera una nueva fila de invitación de vigencia corta reutilizando el mismo flujo de invitación (Sección 9.2) — el usuario recibe un correo con la plantilla premium (Sección 9.4) para redefinir su contraseña desde cero. No existe un "cambio directo" de contraseña por un tercero: el propio usuario siempre la define.
- **Suspender Actividad:** marca `estatus = suspendido` y revoca la sesión activa (`token_acceso = NULL`) de inmediato. `login.php` debe rechazar con `403` cualquier intento de acceso mientras el `estatus` sea `suspendido`, con mensaje genérico ("Cuenta suspendida. Contacta a un administrador."), sin distinguir esta causa de otras en el tiempo de respuesta (mismo principio anti-enumeración de la Sección 2.2).
- **Eliminar:** borra el registro (`DELETE`) apoyándose en las relaciones `ON DELETE CASCADE` ya definidas (Sección 1) para limpiar filas dependientes (invitaciones, recuperación de contraseña) — la bitácora de actividad (Sección 1.2) **no** tiene FK hacia `usuarios` deliberadamente, por lo que el historial del usuario eliminado persiste con su id numérico, íntegro y sin romper la cascada.
- **Protección de último `super_admin`:** tanto "Suspender" como "Eliminar" verifican, antes de ejecutar, que exista al menos otro `super_admin` (activo, en el caso de suspender) además del objetivo — de lo contrario responden `422` sin tocar la fila. Evita un bloqueo total e irreversible del propio Dashboard.
- **Auto-protección del actor:** ningún `super_admin` puede suspenderse o eliminarse a sí mismo desde este panel (`422`) — esas acciones, si son necesarias, las ejecuta otro `super_admin` distinto.
- **Confirmación visual en línea (no `confirm()` de navegador):** las acciones destructivas (eliminar) no usan el diálogo nativo del navegador — se reemplaza el contenido de la celda de acciones por un texto de confirmación más un botón "Confirmar" de segundo paso, manteniendo la experiencia dentro del propio flujo visual del Dashboard (Regla de Oro, Sección 4.4 — cero estilos inline también aplica a este estado intermedio).

### 9.6 Ledgers de Auditoría Paginados (Búsqueda + Borrado Selectivo/Masivo)

Ningún ledger de auditoría (Registro de Ingreso, Sección 9.3, o cualquier tabla histórica equivalente) se renderiza completo server-side de una sola vez — crece sin límite y rompe el scroll vertical del panel. Patrón obligatorio:

- **Paginación server-side real** (no solo ocultar filas con CSS/JS): un endpoint de solo lectura (ej. `{{LEDGER_LISTAR_ENDPOINT}}`, `GET`, exclusivo del rol auditor) acepta `pagina` y `buscar` por querystring, calcula `LIMIT {{REGISTROS_POR_PAGINA}} OFFSET (pagina-1)*{{REGISTROS_POR_PAGINA}}` (ej. 15) contra un `COUNT(*)` previo con el mismo filtro, y devuelve `{ registros, pagina, total_paginas, total }`. El frontend renderiza filas vía DOM (`textContent`, nunca `innerHTML` con datos del servidor) y controles "← Anterior / Siguiente →" que solo cambian `pagina` y vuelven a pedir.
- **Buscador con debounce:** un input de texto filtra por las columnas relevantes (usuario, ubicación, etc.) vía `LIKE` parametrizado — nunca concatenado directo al SQL. Se dispara con un `debounce` (ej. 300-400ms) para no golpear el endpoint en cada tecla.
- **Borrado selectivo vía checkboxes + borrado masivo por umbral:** un segundo endpoint de mutación (ej. `{{LEDGER_ELIMINAR_ENDPOINT}}`, `POST`, mismo rol exclusivo) acepta tres modos explícitos — `seleccionados` (array de ids marcados por checkbox), `cantidad` (elimina los N registros más antiguos primero, preservando la auditoría más reciente) y `todos`. Cada modo es una rama de validación separada, nunca un único campo ambiguo que intente adivinar la intención.
- Ambos endpoints siguen el patrón de 6 capas estándar (Sección 2) — el de borrado, por ser destructivo e irreversible, además registra su propia entrada en la bitácora de actividad (Sección 1.2) con el modo y la cantidad eliminada, para que la purga del ledger quede ella misma auditada.

---

## 10. 🧠 NÚCLEO COGNITIVO DE BIENVENIDA (BLOQUE DINÁMICO)

Bloque en la cima del Dashboard, **después** del guard de autenticación (Sección 5.5) — nunca antes, nunca en una ruta pública.

### 10.1 Encabezado personalizado y saludo contextual

- `"Bienvenido(a), {{USER_NAME}}"` + `"Rol: {{ROL_USUARIO}}"` — ambos ya disponibles en la sesión validada (Sección 2 Capa 2), **cero** llamada adicional a la API solo para esto.
- Saludo por franja horaria ("Buenos días" / "Buenas tardes" / "Buenas noches") calculado en el **cliente**, con la hora local del dispositivo (`new Date().getHours()`) — es puramente cosmético, no una decisión de seguridad, así que no necesita ida y vuelta al backend.

### 10.2 Metadata de geolocalización (Municipio / Estado / País)

- Se solicita **explícitamente** el permiso del navegador (`navigator.geolocation`) — nunca se asume ni se simula. Si el usuario lo niega o el dispositivo no lo soporta, el bloque se degrada limpiamente (oculta la línea de ubicación, **no** rompe el resto del componente).
- La resolución de coordenadas → Municipio/Estado/País (reverse geocoding) requiere un proveedor externo con su propia política de uso y, en la mayoría de los casos, credenciales — `{{GEOCODING_PROVIDER}}` / `{{GEOCODING_API_KEY}}` en `.env` (Bóveda de Secretos, Mandamiento 12). Un proyecto consumidor sin ese proveedor contratado usa un servicio gratuito sin llave documentado explícitamente en su Codex, o deja el bloque sin esta línea — **nunca se inventa o hardcodea una respuesta falsa de ubicación.**

### 10.3 Cápsula motivacional — proveedor de IA conectable

- El texto motivacional lo genera `{{AI_PROVIDER_NAME}}` (el proveedor de IA real del proyecto consumidor) **solo si** existe una integración configurada (endpoint + API key en `.env`, nunca hardcodeada — Mandamiento 12). Sin esa integración, el módulo **no debe fabricar una llamada a un servicio inexistente** (Mandamiento 4, Anti-Alucinación) — usa en su lugar un banco curado de frases (contenido estático versionado por el proyecto consumidor, no generado en runtime) con selección determinística (Sección 10.4). Es una degradación explícita y documentada, no un engaño silencioso sobre "hay IA generándolo".
- Tono de las frases (con o sin proveedor de IA real): motivador, reflexivo, empático, enfocado en crecimiento personal — el proyecto consumidor define el banco/prompt real según su propia marca; este módulo no fija contenido de copywriting.

### 10.4 Persistencia diaria (eficiencia de tokens de IA)

```sql
CREATE TABLE `{{TABLE_PREFIX}}frase_bienvenida_diaria` (
    `fecha`   DATE NOT NULL,
    `frase`   VARCHAR(280) NOT NULL,
    `origen`  ENUM('ia','banco_estatico') NOT NULL DEFAULT 'banco_estatico',
    PRIMARY KEY (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```php
function obtenerFraseBienvenidaDelDia(PDO $pdo): string
{
    $hoy = (new DateTimeImmutable())->format('Y-m-d');

    $stmt = $pdo->prepare('SELECT frase FROM {{TABLE_PREFIX}}frase_bienvenida_diaria WHERE fecha = :fecha LIMIT 1');
    $stmt->execute([':fecha' => $hoy]);
    $fila = $stmt->fetch();

    if ($fila !== false) {
        return $fila['frase']; // ya generada hoy — cero llamada nueva a IA/banco
    }

    // {{AI_PROVIDER_NAME}} si existe integración real; si no, banco estático determinístico.
    $fraseNueva = generarOSeleccionarFraseDelDia();

    $stmt = $pdo->prepare(
        'INSERT INTO {{TABLE_PREFIX}}frase_bienvenida_diaria (fecha, frase, origen) VALUES (:fecha, :frase, :origen)'
    );
    $stmt->execute([':fecha' => $hoy, ':frase' => $fraseNueva, ':origen' => '{{ORIGEN}}']);

    return $fraseNueva;
}
```

- `PRIMARY KEY (fecha)` es, en sí mismo, el mecanismo de "una sola generación por día" — no depende de un `CHECK` en PHP que podría saltarse por una condición de carrera; un segundo intento de `INSERT` en el mismo día falla por duplicado y el código simplemente relee la fila ya existente.
- Este endpoint se sirve **una vez por carga de Dashboard**, cacheado en el cliente durante esa sesión de página — no se re-consulta en cada re-render de componentes.

---

## 11. ✅ VALIDACIÓN DE CONSISTENCIA DEL FLUJO

| # | Verificación | Cubierto en |
| :--- | :--- | :---: |
| 1 | Prevención de SQLi (prepared statements + `ATTR_EMULATE_PREPARES=false`) | §2 Capa 5 |
| 2 | Anti-enumeración de usuarios (tiempo de respuesta uniforme) | §2.2 |
| 3 | Anti-fuerza bruta (tarpitting + rate limiting multivectorial) | §2.2 |
| 4 | Revocación inmediata de sesión (token opaco vs. JWT) | §3.1 |
| 5 | Mitigación de secuestro de sesión (Device Binding) | §3.3 |
| 6 | Protección de cookies contra XSS/CSRF (`HttpOnly`, `SameSite`, `Secure`) | §3.2 |
| 7 | Bitácora inmutable a nivel de motor (triggers `SIGNAL SQLSTATE`) | §1.2 |
| 8 | Cero fugas de información técnica al frontend (Capa 6 + Contrato de Respuesta) | §2 Capa 6, §2.1 |
| 9 | UI sin reflow en estados interactivos (ARF-Grid + transform/opacity) | §4.3 |
| 10 | UI resiliente ante fallo crítico (estado irreversible + contenedor persistente) | §4.5 |
| 11 | Fricción Cero Gate no manipulable desde el cliente | §3.4 |
| 12 | Política de contraseña única fuente de verdad (backend y frontend leen la misma configuración) | §7 |
| 13 | Genesis auto-desactivado de forma determinista (403 en toda mutación posterior) | §8.4 |
| 14 | Visibility toggle sin fugas de estado (no dispara submit, no altera el valor) | §4.6 |
| 15 | "Recordarme" extiende TTL sin relajar cookies/device binding | §3.5 |
| 16 | Password Reset anti-enumeración + revoca sesión previa al confirmar | §3.6 |
| 17 | Asignación de rol siempre recortada por la jerarquía del actor (nunca confía en el payload) | §6, §9.1, §9.2 |
| 18 | `super_admin` con visibilidad absoluta no configurable; matriz de módulos solo restringe a roles inferiores | §6.1 |
| 19 | Módulo deshabilitado se omite server-side (no se envía al HTML) — nunca solo ocultado con CSS | §6.1 |
| 20 | Cápsula de IA nunca fabrica una llamada a un proveedor inexistente — degrada a banco estático documentado | §10.3 |
| 21 | Frase diaria persistida con `PRIMARY KEY (fecha)` — inmune a condiciones de carrera, sin llamadas redundantes | §10.4 |

**Declaración de blindaje:** el flujo descrito no presenta lagunas lógicas conocidas entre capas — cada capa del patrón de 6 capas asume que la anterior ya validó su responsabilidad y no repite validaciones ya cubiertas, pero tampoco confía en el frontend para ninguna decisión de seguridad (el Fricción Cero Gate, el device binding, el tarpitting y la política de contraseña activa se resuelven siempre server-side).

---

## 📎 ANEXO — Checklist Operativo Heredado (v2.0)

> Se conserva como capa de gobernanza y madurez enterprise. Úsalo para auditar, en un proyecto consumidor concreto, qué tan cerca está de las Fases 1-7 y del checklist de cierre original. No sustituye las Secciones 1-7 de este documento, que son el blueprint técnico obligatorio.

### Fases de referencia (resumen)
1. **Estructuración y Capa de Datos** — múltiples métodos de auth, gestión de sesiones, Codex actualizado antes de codificar.
2. **Interfaz y Accesibilidad** — autocompletado nativo, indicador de carga in-button, a11y WCAG ≥ 4.5:1.
3. **Autenticación Adaptativa** — evaluación de riesgo, step-up auth condicional, verificación de contraseñas comprometidas.
4. **Sesiones y Continuidad** — device binding, autenticación continua, renovación silenciosa, invalidación remota.
5. **Seguridad Perimetral** — rate limiting multivectorial, tarpitting, anti-enumeración, mensajes de error estandarizados.
6. **Recuperación de Cuenta** — TTL corto en tokens de recuperación, invalidación de enlaces antiguos, notificaciones de seguridad.
7. **Telemetría y Auditoría** — registro de todo intento de acceso, nunca contraseñas en logs, metadata no intrusiva.

### Regla de aplicación
Cada ítem `[~]`/`[ ]` de este anexo que implique una tabla nueva o cambio de arquitectura de sesión **debe pasar primero por la Regla de Oro de Base de Datos y por confirmación explícita del Arquitecto** del proyecto consumidor — este módulo es una guía de nivel enterprise a la que aspirar, no una orden de ejecución automática completa.
