<?php

/**
 * API: usuarios.php — administración de cuentas (solo rol Desarrollador).
 *
 * GET  /api/usuarios.php                 Lista de cuentas (sin contraseñas).
 * POST /api/usuarios.php  {accion, ...}  Requiere cabecera X-CSRF-Token.
 *   crear       {usuario, nombre, rol}  → devuelve una contraseña temporal, que se muestra una sola vez
 *   editar      {id, nombre, rol}
 *   activar     {id}
 *   desactivar  {id}                    → su sesión termina en la siguiente petición
 *   restablecer {id}                    → nueva contraseña temporal; desbloquea la cuenta
 *
 * Las cuentas no se borran, se desactivan, para conservar la bitácora.
 */

require_once __DIR__ . '/../seguridad/sesion.php';

$admin = requerirSesionApi('administrar_usuarios');
$db    = BaseSeguridad::conexion();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $filas = $db->query(
            'SELECT id, usuario, nombre, rol, activo, debe_cambiar_clave,
                    bloqueado_hasta > NOW() AS bloqueado, ultimo_acceso, creado_en
             FROM usuarios ORDER BY FIELD(rol, "desarrollador", "gerente", "trazabilidad"), nombre'
        )->fetchAll();
        responderJson(200, ['status' => 'success', 'data' => $filas, 'roles' => ROLES]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responderJson(405, ['status' => 'error', 'message' => 'Método no permitido.']);
    }

    exigirCsrfApi();
    $datos  = leerJson();
    $accion = (string)($datos['accion'] ?? '');

    if ($accion === 'crear') {
        $usuario = strtolower(trim((string)($datos['usuario'] ?? '')));
        $nombre  = trim((string)($datos['nombre'] ?? ''));
        $rol     = (string)($datos['rol'] ?? '');
        validarDatos($usuario, $nombre, $rol);

        $existe = $db->prepare('SELECT 1 FROM usuarios WHERE usuario = ?');
        $existe->execute([$usuario]);
        if ($existe->fetchColumn()) {
            responderJson(409, ['status' => 'error', 'message' => "El usuario \"$usuario\" ya existe."]);
        }

        $clave = generarClaveTemporal();
        $db->prepare('INSERT INTO usuarios (usuario, nombre, rol, clave_hash, debe_cambiar_clave) VALUES (?, ?, ?, ?, 1)')
           ->execute([$usuario, $nombre, $rol, password_hash($clave, PASSWORD_DEFAULT)]);
        registrarAcceso('usuario_creado', $admin['id'], $admin['usuario'], "$usuario ($rol)");
        responderJson(200, ['status' => 'success', 'usuario' => $usuario, 'claveTemporal' => $clave]);
    }

    $id     = (int)($datos['id'] ?? 0);
    $cuenta = buscarCuenta($db, $id);

    switch ($accion) {
        case 'editar':
            $nombre = trim((string)($datos['nombre'] ?? ''));
            $rol    = (string)($datos['rol'] ?? '');
            validarDatos($cuenta['usuario'], $nombre, $rol);
            if ($cuenta['rol'] === 'desarrollador' && $rol !== 'desarrollador') {
                exigirOtroDesarrollador($db, $id);
            }
            $db->prepare('UPDATE usuarios SET nombre = ?, rol = ? WHERE id = ?')->execute([$nombre, $rol, $id]);
            registrarAcceso('usuario_editado', $admin['id'], $admin['usuario'], "{$cuenta['usuario']}: rol {$cuenta['rol']} → $rol");
            break;

        case 'activar':
            $db->prepare('UPDATE usuarios SET activo = 1 WHERE id = ?')->execute([$id]);
            registrarAcceso('usuario_activado', $admin['id'], $admin['usuario'], $cuenta['usuario']);
            break;

        case 'desactivar':
            if ($id === $admin['id']) {
                responderJson(409, ['status' => 'error', 'message' => 'No puedes desactivar tu propia cuenta.']);
            }
            if ($cuenta['rol'] === 'desarrollador') {
                exigirOtroDesarrollador($db, $id);
            }
            $db->prepare('UPDATE usuarios SET activo = 0 WHERE id = ?')->execute([$id]);
            registrarAcceso('usuario_desactivado', $admin['id'], $admin['usuario'], $cuenta['usuario']);
            break;

        case 'restablecer':
            $clave = generarClaveTemporal();
            $db->prepare('UPDATE usuarios SET clave_hash = ?, debe_cambiar_clave = 1, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?')
               ->execute([password_hash($clave, PASSWORD_DEFAULT), $id]);
            registrarAcceso('clave_restablecida', $admin['id'], $admin['usuario'], $cuenta['usuario']);
            responderJson(200, ['status' => 'success', 'usuario' => $cuenta['usuario'], 'claveTemporal' => $clave]);

        default:
            responderJson(400, ['status' => 'error', 'message' => 'Acción no válida.']);
    }

    responderJson(200, ['status' => 'success']);

} catch (PDOException $e) {
    error_log('usuarios.php: ' . $e->getMessage());
    responderJson(500, ['status' => 'error', 'message' => 'No se pudo completar la operación.']);
}

function validarDatos(string $usuario, string $nombre, string $rol): void
{
    if (!preg_match('/^[a-z][a-z0-9._]{2,39}$/', $usuario)) {
        responderJson(400, ['status' => 'error', 'message' => 'El usuario debe empezar con una letra y tener de 3 a 40 caracteres: letras minúsculas, números, punto o guion bajo.']);
    }
    if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
        responderJson(400, ['status' => 'error', 'message' => 'El nombre debe tener entre 2 y 100 caracteres.']);
    }
    if (!isset(ROLES[$rol])) {
        responderJson(400, ['status' => 'error', 'message' => 'Rol no válido.']);
    }
}

function buscarCuenta(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT id, usuario, rol FROM usuarios WHERE id = ?');
    $stmt->execute([$id]);
    $cuenta = $stmt->fetch();
    if (!$cuenta) {
        responderJson(404, ['status' => 'error', 'message' => 'La cuenta no existe.']);
    }
    return $cuenta;
}

/** Evita quedarse sin nadie que pueda administrar usuarios. */
function exigirOtroDesarrollador(PDO $db, int $id): void
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'desarrollador' AND activo = 1 AND id <> ?");
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() === 0) {
        responderJson(409, ['status' => 'error', 'message' => 'Debe quedar al menos un desarrollador activo.']);
    }
}
