<?php

/**
 * API: sincronizacion.php — estado de la sincronización con la base de la empresa.
 *
 * GET  /api/sincronizacion.php                        Estado y últimas 10 ejecuciones (Gerente y Desarrollador).
 * POST /api/sincronizacion.php {accion: "reactivar"}  Quita la detención tras fallos (solo Desarrollador).
 *                                                     Requiere cabecera X-CSRF-Token.
 *
 * La lectura usa el usuario de solo lectura de la app. Reactivar usa `sync_control`, que solo puede
 * actualizar la tabla sync_estado; si la instancia no lo tiene configurado, la acción no está disponible.
 */

require_once __DIR__ . '/../seguridad/sesion.php';
require_once __DIR__ . '/../config/database.php';

$usuario = requerirSesionApi('ver_sincronizacion');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!puede($usuario, 'reactivar_sincronizacion')) {
            registrarAcceso('acceso_denegado', $usuario['id'], $usuario['usuario'], 'reactivar_sincronizacion');
            responderJson(403, ['status' => 'error', 'codigo' => 'sin_permiso', 'message' => 'Tu rol no tiene permiso para esta acción.']);
        }
        exigirCsrfApi();
        if ((leerJson()['accion'] ?? '') !== 'reactivar') {
            responderJson(400, ['status' => 'error', 'message' => 'Acción no válida.']);
        }
        if (!getenv('SYNC_CONTROL_USER')) {
            responderJson(409, ['status' => 'error', 'message' => 'Esta instancia no tiene permiso para reactivar la sincronización.']);
        }

        $control = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: ''),
            getenv('SYNC_CONTROL_USER'), getenv('SYNC_CONTROL_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $filas = $control->exec('UPDATE sync_estado SET detenido = 0, fallos_consecutivos = 0, motivo = NULL WHERE detenido = 1');
        registrarAcceso('sync_reactivada', $usuario['id'], $usuario['usuario'], "$filas modo(s)");
        responderJson(200, ['status' => 'success', 'reactivados' => $filas]);
    }

    $db = (new Database())->getConnection();
    if (!$db) {
        throw new PDOException('Sin conexión a la base de datos.');
    }

    try {
        $estado = $db->query(
            'SELECT modo, ultima_fecha, ultimo_id, fallos_consecutivos, detenido, motivo, actualizado_en FROM sync_estado'
        )->fetchAll();
        $ejecuciones = $db->query(
            'SELECT inicio, fin, modo, estado, leidas, insertadas, actualizadas, reintentos, segundos, mensaje
             FROM sync_log ORDER BY id DESC LIMIT 10'
        )->fetchAll();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') { // la base de esta instancia no tiene tablas de sincronización
            responderJson(200, ['status' => 'success', 'disponible' => false]);
        }
        throw $e;
    }

    responderJson(200, [
        'status'          => 'success',
        'disponible'      => true,
        'puedeReactivar'  => puede($usuario, 'reactivar_sincronizacion') && (bool)getenv('SYNC_CONTROL_USER'),
        'estado'          => $estado,
        'ejecuciones'     => $ejecuciones,
    ]);

} catch (PDOException $e) {
    error_log('sincronizacion.php: ' . $e->getMessage());
    responderJson(500, ['status' => 'error', 'message' => 'No se pudo consultar la sincronización.']);
}
