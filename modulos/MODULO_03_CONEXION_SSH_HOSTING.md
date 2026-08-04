# 📄 MODULO_03 — CONEXIÓN SSH Y AUDITORÍA DE HOSTING

**Clasificación:** Módulo Genérico de Arquitectura y Diseño Técnico | **Versión:** 2.0
**Alcance:** Documento agnóstico, reutilizable por cualquier proyecto — ningún nombre de cliente,
host o credencial real debe aparecer aquí.
**Compatibilidad:** cPanel, VPS, CloudLinux, cualquier entorno Linux con SSH.

---

## 1. 🎯 OBJETIVO DEL MÓDULO

Establecer un protocolo seguro y repetible para que un desarrollador (asistido o no por un agente
de IA) se conecte por SSH a un hosting remoto para diagnóstico y despliegue, **sin degradar la
seguridad del acceso a producción** a cambio de conveniencia.

> **Corrección respecto a la v1.0 de este documento:** la versión anterior recomendaba remover la
> passphrase de la llave privada para permitir automatización sin fricción. Se revierte
> explícitamente esa recomendación (ver Sección 4) — una llave sin passphrase es un secreto de
> un solo factor: quien obtenga el archivo tiene acceso completo e inmediato al servidor, sin
> ninguna verificación adicional. La conveniencia de automatización no justifica ese riesgo en un
> acceso de producción.

---

## 2. 🏗️ ARQUITECTURA / ESPECIFICACIÓN TÉCNICA

**Modelo de credenciales:** llave RSA 2048+ o Ed25519, generada en el panel del hosting (cPanel
→ SSH Access) o localmente con `ssh-keygen`. La llave privada nunca sale del equipo del
desarrollador ni se transmite por un canal no cifrado (chat, email, ticket de soporte).

**Ubicación de la llave privada:** una carpeta del proyecto explícitamente excluida de control de
versiones (ej. `knowledge/id_rsa_PROYECTO`, con `/knowledge/` en `.gitignore`) — nunca en la raíz
del repo ni en una carpeta que pueda subirse por accidente vía `git add -A` o el pipeline de
deploy.

**Flujo de conexión estándar:**
```
ssh -i "RUTA_A_LA_LLAVE" -p PUERTO usuario@host "<comando>"
```
El host, puerto y usuario reales de conexión SSH deben pedirse explícitamente al panel del
hosting (cPanel → SSH Access suele mostrarlos, o soporte los confirma) — no se asumen puertos
"comunes" sin verificar, y sí se prueban con timeout corto para no bloquear el flujo si el puerto
es incorrecto.

---

## 3. 🛠️ CHECKLIST PASO A PASO (TODO LIST)

### Fase 1 — Generación y autorización de llaves
- [ ] Generar el par de llaves desde el panel del hosting (cPanel → SSH Access → *Generate a New
      Key*) o localmente con `ssh-keygen -t ed25519`.
- [ ] **Autorizar explícitamente la llave pública** (cPanel → *Manage SSH Keys* → botón
      **Authorize** junto a la llave) — generarla no basta, sin este paso el servidor rechaza la
      conexión con `Permission denied (publickey)`.

### Fase 2 — Almacenamiento seguro y permisos
- [ ] Guardar la llave privada en una ruta fuera de control de versión (ver Sección 2).
- [ ] En Windows, retirar la herencia de permisos y dejar solo al usuario activo con acceso:
  ```powershell
  icacls "RUTA_A_LA_LLAVE" /inheritance:r
  icacls "RUTA_A_LA_LLAVE" /grant:r "$($env:USERNAME):F"
  ```
- [ ] En Linux/macOS: `chmod 600 RUTA_A_LA_LLAVE`.

### Fase 3 — Validación de la conexión
- [ ] Probar con timeout corto y sin bloquear en caso de que el puerto sea incorrecto:
  ```bash
  ssh -i "RUTA_A_LA_LLAVE" -o BatchMode=yes -o ConnectTimeout=8 -p PUERTO usuario@host "echo CONEXION_EXITOSA"
  ```
- [ ] Si la llave tiene passphrase, usar un **agente SSH de sesión** en vez de eliminarla del
      archivo (ver Sección 4) — el agente pide la passphrase una sola vez por sesión de trabajo y
      la mantiene solo en memoria, sin dejar la llave permanentemente desprotegida en disco.

### Fase 4 — Matriz de reconocimiento del hosting (primera conexión a un servidor nuevo)

| Criterio | Comando SSH | Propósito |
| :--- | :--- | :--- |
| Identidad y ruta home | `whoami && pwd` | Confirmar usuario y directorio home real. |
| Kernel / arquitectura | `uname -a` | Arquitectura (x86_64/aarch64) y pista de la versión de glibc. |
| Versión de glibc | `ldd --version \| head -1` | Crítico si se van a subir binarios compilados (Go, Rust) — un binario enlazado dinámicamente contra una glibc más nueva que la del servidor falla en runtime. |
| Runtimes disponibles | `which php go node python3 2>&1` | Qué lenguajes corren nativamente en el servidor. |
| Procesos persistentes | `which nohup screen tmux supervisord 2>&1` | Qué mecanismos hay para mantener un proceso vivo en background. |
| Gestor de apps (cPanel/CloudLinux) | `/usr/sbin/cloudlinux-selector get --json --interpreter nodejs --get-selector-status` (repetir con `php`/`python`) | Si existe Passenger vía Application Manager y qué intérpretes soporta — **no incluye binarios arbitrarios**, solo los intérpretes habilitados. |
| Módulos de Apache | `httpd -M 2>&1 \| grep -i proxy` | Si hay `mod_proxy` disponible (normalmente sin permiso en hosting compartido). |
| Puertos en escucha | `ss -tlnp 2>&1 \|\| netstat -tlnp 2>&1` | Qué está corriendo y en qué puertos (puede no estar instalado en hosting compartido). |
| Cron existente | `crontab -l` | Tareas programadas ya configuradas, para no pisar nada al agregar una propia. |
| Logs de error HTTP | `tail -n 50 public_html/error_log` | Diagnóstico directo de fallos recientes del servidor web. |

- [ ] Documentar los resultados de esta matriz en el reporte técnico del proyecto (ver
      `MODULO_02_REPORTES_Y_AUDITORIAS.md`) antes de tomar decisiones de arquitectura de
      despliegue basadas en supuestos.

---

## 4. 🔐 REQUISITOS DE SEGURIDAD (ZERO TRUST)

- [ ] **La llave privada nunca pierde su passphrase como estándar de conveniencia.** Si un flujo
      de automatización necesita ejecutar SSH varias veces sin repetir la passphrase, usar un
      agente SSH de sesión (`ssh-agent` + `ssh-add`, o el equivalente del sistema operativo) que
      la mantiene solo en memoria durante la sesión activa — no editar el archivo de la llave con
      `ssh-keygen -p` para dejarla en blanco permanentemente.
  - Excepción documentada y consciente: llaves de un solo propósito, con permisos mínimos
    forzados en el servidor (`command=` en `authorized_keys`, IP allowlist), usadas solo para una
    tarea acotada (ej. un webhook de CI/CD) — nunca para una llave de acceso shell completo como
    la de un desarrollador humano.
- [ ] **Nunca pegar la llave privada (ni su passphrase) en un chat, ticket de soporte o cualquier
      canal que quede registrado en texto plano.** Si un panel web mostró el contenido de la
      llave privada en pantalla (ej. "View/Open Key" de un Key Manager), tratarla como
      potencialmente expuesta y evaluar regenerarla.
- [ ] **Rotar la llave inmediatamente si su material (público o privado) quedó expuesto** en un
      historial de conversación, log, o cualquier sistema fuera del control exclusivo del
      desarrollador — generar un nuevo par, autorizar el nuevo, revocar el anterior en el panel.
- [ ] Acciones de alto impacto contra el servidor de producción (reiniciar servicios, modificar
      `.htaccess`, escribir en el document root público) deben pasar por confirmación explícita
      del responsable del proyecto antes de ejecutarse, incluso si la conexión SSH ya está
      autenticada — la autenticación no sustituye la autorización explícita para cada acción.
- [ ] El reconocimiento inicial (Fase 4) es de solo lectura por defecto — ningún comando de
      escritura/ejecución se corre contra el servidor hasta terminar el diagnóstico.

---

## 5. 📋 DEFINICIÓN DE HECHO (DEFINITION OF DONE - DOD)

1. La llave privada SSH reside fuera de control de versión (`.gitignore` verificado) y conserva
   su passphrase salvo la excepción documentada de la Sección 4.
2. Los permisos del sistema operativo están restringidos al usuario único (`icacls`/`chmod`
   verificados).
3. La conexión SSH responde `CONEXION_EXITOSA` sin exponer secretos en el proceso.
4. Se cuenta con el reporte de reconocimiento del entorno remoto (Fase 4) documentado antes de
   tomar decisiones de arquitectura de despliegue.
5. Ninguna llave, passphrase o credencial usada en el proceso quedó pegada en un chat, log o
   documento versionado.
