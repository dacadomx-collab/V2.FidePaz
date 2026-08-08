<?php
declare(strict_types=1);

/**
 * Bitácora de auditoría — registra quién cambió qué y cuándo en las
 * entidades administrables del panel (colonos, propiedades, cuotas).
 * Tabla `audit_logs` (ver 02_CODEX_Y_SCHEMA_MAESTRO.md), autorizada
 * explícitamente 2026-08-07.
 */
final class Audit
{
    public static function log(string $entityType, int $entityId, string $action, ?int $changedBy, array $details): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (entity_type, entity_id, action, changed_by, details_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$entityType, $entityId, $action, $changedBy, json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }

    /** Últimos N cambios de una entidad específica, más reciente primero. */
    public static function history(string $entityType, int $entityId, int $limit = 20): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT al.id, al.action, al.details_json, al.created_at,
                    u.name AS changed_by_name
             FROM audit_logs al
             LEFT JOIN `user` u ON u.id = al.changed_by
             WHERE al.entity_type = ? AND al.entity_id = ?
             ORDER BY al.created_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([$entityType, $entityId]);

        return array_map(static function (array $r): array {
            return [
                'id' => $r['id'],
                'action' => $r['action'],
                'details' => json_decode($r['details_json'] ?? '{}', true),
                'changedByName' => $r['changed_by_name'],
                'createdAt' => Response::isoDate($r['created_at']),
            ];
        }, $stmt->fetchAll());
    }
}
