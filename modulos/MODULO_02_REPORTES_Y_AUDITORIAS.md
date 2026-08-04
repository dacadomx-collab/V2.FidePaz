# 📄 MODULO_02 — REPORTES Y AUDITORÍAS

**Clasificación:** Módulo Genérico de Arquitectura y Diseño Técnico | **Versión:** 2.0
**Alcance:** Documento agnóstico, reutilizable por cualquier proyecto — ningún nombre de cliente,
dominio o credencial real debe aparecer aquí.
**Compatibilidad:** Cualquier lenguaje/stack (PHP, Go, Node, Python, C#, etc.)

---

## 1. 🎯 OBJETIVO DEL MÓDULO

Proporcionar una arquitectura dual de reportes que documente el estado del sistema, el
rendimiento, las auditorías de seguridad y las métricas clave de un proyecto, adaptando lenguaje
y presentación según la audiencia: técnica (equipo interno) o ejecutiva (cliente/directivos).

---

## 2. 🏗️ ARQUITECTURA / ESPECIFICACIÓN TÉCNICA

### 🔴 Tipo 1 — Reporte técnico interno
**Audiencia:** desarrolladores, administradores de sistema, ciberseguridad.
**Contenido:** auditoría de seguridad (malware, webshells, usuarios no autorizados, puertos/
endpoints expuestos), métricas de bajo nivel (tiempos de query, RAM, CPU), logs de error
(excepciones, HTTP 4xx/5xx), esquema de BD (tablas, índices, FKs), estado real de integraciones.

### 🟢 Tipo 2 — Reporte ejecutivo/infográfico
**Audiencia:** clientes, dirección, inversionistas.
**Contenido:** KPIs en tarjetas, badges de estado, comparativa antes/después, resumen de
correcciones en lenguaje humano, próximos pasos con fecha.

### ⚠️ Regla de oro — Integridad de datos (no negociable)
**Ninguna cifra se publica en un reporte, de ningún tipo, sin una medición real detrás.** Si un
dato de rendimiento (ms de respuesta, uso de RAM, throughput, "% más rápido") no se benchmarkeó
contra el sistema real, se marca explícitamente como **"⏳ Pendiente de benchmark"** en la misma
fila/celda donde iría la cifra — nunca se estima, redondea "a ojo" ni se extrapola de proyectos
anteriores para rellenar una tabla. Esta regla aplica igual a reportes técnicos y ejecutivos: un
reporte ejecutivo con cifras infladas no es "más persuasivo", es un pasivo de credibilidad y
puede derivar en decisiones de negocio mal fundamentadas.

### Casos borde a cubrir siempre
- **Auditoría de seguridad con hallazgos activos (ej. cuentas backdoor, credenciales expuestas):**
  el reporte técnico lista el hallazgo con evidencia (IDs, patrones, fecha); el reporte ejecutivo
  resume el impacto de negocio y la decisión tomada, sin exponer detalles explotables
  (contraseñas, rutas exactas de exploit, tokens) que un lector no autorizado del documento
  pudiera reutilizar.
- **Migración de datos:** todo reporte de migración declara explícitamente qué se excluyó
  (tablas, columnas, registros) y por qué — "migración completa" solo se afirma si es literal.
- **Bloqueadores de infraestructura sin resolver:** se documentan como tales, con la causa raíz
  técnica confirmada (no una suposición) y las opciones reales de resolución — no se maquilla un
  bloqueador activo como "en progreso" si no hay ninguna acción en curso.

---

## 3. 🛠️ CHECKLIST PASO A PASO (TODO LIST)

- [ ] Definir la audiencia del reporte (técnica vs ejecutiva) antes de escribir una sola línea.
- [ ] Recolectar evidencia verificable para cada afirmación (logs, `curl` en vivo, `EXPLAIN`,
      resultado real de comando) — nunca redactar primero y "buscar el dato después".
- [ ] Para cada métrica de rendimiento: ¿se midió en vivo? Si no, marcar "pendiente de benchmark".
- [ ] Redactar el reporte técnico completo primero (es la fuente de verdad).
- [ ] Derivar el reporte ejecutivo del técnico, traduciendo a lenguaje de negocio — nunca al revés.
- [ ] Revisar que ningún secreto (contraseña, llave, token, connection string) quede en el texto.
- [ ] Si se publica en HTML, pasar el checklist de UI/UX (Sección 4).
- [ ] Fechar el documento y registrar el hito en la bitácora de avance del proyecto.

---

## 4. 🎨 REQUISITOS DE UI/UX Y SEGURIDAD (ZERO TRUST)

Aplica cuando el reporte se publica como página HTML (no a los `.md` internos).

**Estructura y navegación**
- [ ] Favicon institucional enlazado.
- [ ] Botón flotante "ir arriba" (aparece tras >300px de scroll, scroll suave).
- [ ] 100% responsivo (móvil, tablet, escritorio).
- [ ] Menú de navegación colapsa a hamburguesa en <768px.

**Recursos visuales**
- [ ] Imágenes en WebP/AVIF o PNG/JPG optimizado, límite 100–200 KB por imagen sin perder nitidez.

**Calidad cero-defecto**
- [ ] Sin elementos encimados, texto ilegible ni desbordamiento horizontal.
- [ ] Cero advertencias/excepciones en consola del navegador.
- [ ] HTML5 semántico válido, CSS estructurado (sin `!important`, sin estilos inline).

**Seguridad Zero Trust del propio reporte**
- [ ] El documento nunca contiene contraseñas, llaves privadas, tokens ni connection strings
      completos — ni siquiera parcialmente enmascarados si el resto es reconstruible.
- [ ] Si el reporte se publica en un sitio público/HTML servido por el mismo hosting, se bloquea
      su acceso directo por URL (ej. regla de `.htaccess` para `.md`/rutas internas) salvo que su
      contenido esté explícitamente aprobado para audiencia pública.
- [ ] Un reporte técnico con hallazgos de seguridad activos no se distribuye más allá de quien
      necesita actuar sobre ellos, hasta que el hallazgo esté remediado o formalmente aceptado.

---

## 5. 📋 DEFINICIÓN DE HECHO (DEFINITION OF DONE - DOD)

1. El reporte técnico interno documenta causa raíz y evidencia verificable, no supuestos.
2. El reporte ejecutivo es comprensible por un lector no técnico en menos de 2 minutos.
3. Ninguna cifra de rendimiento se publica sin una medición real detrás (Sección 2, regla de oro).
4. Si se publica en HTML, pasa el checklist de UI/UX de la Sección 4 con 0 errores de consola.
5. Ningún secreto queda expuesto en el documento ni en su ruta de publicación.
