# Migración de base de datos — FidePaz v2.0

Origen: `mercagee_colonoscore.sql` (verificada íntegra, sin evidencia de
infección — la contaminación con cuentas backdoor vive en `mercagee_colonos`
/ WordPress, una base completamente distinta que v2.0 **no** importa).

## Orden de ejecución

```bash
mysql -u root -p < schema.sql      # crea fidepaz_v2_db + las 5 tablas sanitizadas
mysql -u root -p < seed_data.sql   # inserta los datos reales (218 colonos, 198 casas, 10,758 cuotas)
```

O vía phpMyAdmin: Importar `schema.sql` primero, luego `seed_data.sql` sobre
la base `fidepaz_v2_db` ya creada.

## Qué cambió respecto a V1 y por qué

| Cambio | Motivo |
|---|---|
| `latin1` → `utf8mb4` real | El dump V1 declaraba `latin1` pero los bytes reales ya eran UTF-8 (verificado a nivel binario: "PEÑA" = `C3 91`, UTF-8 correcto). `seed_data.sql` usa `SET NAMES utf8mb4` para transcodificar correctamente — **no** confundir con una migración real desde latin1. |
| `DOUBLE` → `DECIMAL(10,2)` en `quota.cost` y `user_quotas.amount` | Los `DOUBLE` de coma flotante pueden introducir errores de redondeo en montos de dinero (ej. 0.1 + 0.2 ≠ 0.3 en binario). `DECIMAL` es exacto. |
| Nuevos índices en `user_quotas` | `idx_uq_property_duedate`, `idx_uq_user_duedate`, `idx_uq_due_date`, `idx_uq_pay_date`, `idx_uq_status` — cubren las consultas reales del panel (histórico de un colono, cuotas vencidas, reportes por rango de fecha) sin table scans sobre 10,758+ filas. |
| `FOREIGN KEY` con nombre y `ON DELETE` explícito | V1 los definía en un `ALTER TABLE` separado al final del dump sin política de borrado clara. |
| Tabla `extras` excluida | Fuera del alcance definido para v2.0 (solo `user`, `property`, `quota`, `street`, `user_quotas`). Se puede reincorporar después con el mismo patrón. |
| **Ninguna tabla de WordPress importada** | Los "7 administradores backdoor" detectados viven en `wp_users` (`mercagee_colonos`), no en `mercagee_colonoscore.user` (que son los 218 colonos reales). v2.0 no toca `mercagee_colonos` en absoluto. |

## Contraseñas de usuarios

Los hashes `password` se copian tal cual (`$2b$10$...`, bcrypt cost 10,
generados originalmente por Node/bcryptjs). `password_verify()` de PHP los
reconoce de forma nativa — **no requieren rehash** para funcionar con
`api/routes/auth.php`. Se recomienda, aun así, forzar un rehash progresivo
a `PASSWORD_BCRYPT` cost 12 en el primer login de cada usuario una vez en
producción (mejora marginal de seguridad, no urgente).
