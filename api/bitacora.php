<?php

/**
 * API: bitacora.php — últimos eventos de acceso (roles Gerente y Desarrollador).
 *
 * GET /api/bitacora.php?limite=200   (máximo 500)
 */

require_once __DIR__ . '/../seguridad/sesion.php';

requerirSesionApi('ver_bitacora');

try {
    $limite = max(1, min(500, (int)($_GET['limite'] ?? 200)));
    $stmt = BaseSeguridad::conexion()->prepare(
        'SELECT fecha, usuario_texto AS usuario, evento, detalle, ip FROM accesos ORDER BY id DESC LIMIT ?'
    );
    $stmt->bindValue(1, $limite, PDO::PARAM_INT);
    $stmt->execute();
    responderJson(200, ['status' => 'success', 'data' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    error_log('bitacora.php: ' . $e->getMessage());
    responderJson(500, ['status' => 'error', 'message' => 'No se pudo leer la bitácora.']);
}
