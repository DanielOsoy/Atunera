<?php

/**
 * Sesión, autenticación y utilidades de seguridad compartidas por las páginas y la API.
 *
 * La sesión vive en el servidor; el navegador solo guarda un identificador en una cookie
 * HttpOnly (JavaScript no la puede leer) y SameSite=Strict (no viaja en peticiones de otros sitios).
 * Se cierra tras 30 minutos sin actividad y, como máximo, a las 8 horas de iniciada.
 */

require_once __DIR__ . '/BaseSeguridad.php';
require_once __DIR__ . '/permisos.php';

const SESION_INACTIVIDAD_S = 1800;
const SESION_MAXIMA_S      = 28800;
const INTENTOS_MAXIMOS     = 5;    // fallos seguidos antes de bloquear la cuenta
const BLOQUEO_MINUTOS      = 15;
const FALLOS_MAXIMOS_IP    = 10;   // fallos desde una misma IP en BLOQUEO_MINUTOS

/** Inicia la sesión con cookies seguras. Se puede llamar varias veces. */
function iniciarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Secure exige HTTPS; en el servidor publicado se fuerza con SESION_COOKIE_SEGURA=1
    $segura = getenv('SESION_COOKIE_SEGURA') === '1'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // Un nombre por instancia: en localhost los distintos puertos comparten las cookies
    session_name('ATUNERA_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', getenv('DB_NAME') ?: 'APP')));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $segura,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/**
 * Usuario de la sesión, o null si no hay sesión válida.
 * En cada petición confirma en la base que la cuenta sigue activa y toma su rol actual:
 * si el desarrollador la desactiva, la sesión termina de inmediato.
 */
function usuarioActual(): ?array
{
    iniciarSesion();
    $sesion = $_SESSION['usuario'] ?? null;
    if ($sesion === null) {
        return null;
    }

    $ahora = time();
    if ($ahora - ($_SESSION['ultima_actividad'] ?? 0) > SESION_INACTIVIDAD_S
        || $ahora - ($_SESSION['inicio'] ?? 0) > SESION_MAXIMA_S) {
        registrarAcceso('sesion_expirada', $sesion['id'], $sesion['usuario']);
        cerrarSesion();
        return null;
    }

    $stmt = BaseSeguridad::conexion()->prepare(
        'SELECT id, usuario, nombre, rol, activo, debe_cambiar_clave FROM usuarios WHERE id = ?'
    );
    $stmt->execute([$sesion['id']]);
    $fila = $stmt->fetch();
    if (!$fila || (int)$fila['activo'] !== 1) {
        registrarAcceso('sesion_revocada', $sesion['id'], $sesion['usuario'], 'cuenta desactivada');
        cerrarSesion();
        return null;
    }

    $_SESSION['ultima_actividad'] = $ahora;
    $_SESSION['usuario'] = [
        'id'                 => (int)$fila['id'],
        'usuario'            => $fila['usuario'],
        'nombre'             => $fila['nombre'],
        'rol'                => $fila['rol'],
        'debe_cambiar_clave' => (int)$fila['debe_cambiar_clave'] === 1,
    ];
    return $_SESSION['usuario'];
}

/** Guarda en la sesión al usuario que acaba de autenticarse. */
function abrirSesionDe(array $fila): void
{
    iniciarSesion();
    // Identificador nuevo: uno anterior (quizás conocido por un atacante) deja de servir
    session_regenerate_id(true);
    $_SESSION = [
        'inicio'           => time(),
        'ultima_actividad' => time(),
        'csrf'             => bin2hex(random_bytes(32)),
        'usuario'          => [
            'id'                 => (int)$fila['id'],
            'usuario'            => $fila['usuario'],
            'nombre'             => $fila['nombre'],
            'rol'                => $fila['rol'],
            'debe_cambiar_clave' => (int)$fila['debe_cambiar_clave'] === 1,
        ],
    ];
}

function cerrarSesion(): void
{
    iniciarSesion();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_destroy();
}

/** Para páginas: sin sesión lleva a la bienvenida; con contraseña temporal, a cambiarla. */
function requerirSesionPagina(): array
{
    noGuardarEnCache();
    try {
        $usuario = usuarioActual();
    } catch (Throwable $e) {
        error_log('sesion: ' . $e->getMessage());
        http_response_code(503);
        exit('El servicio de acceso no está disponible. Intenta de nuevo en unos minutos.');
    }
    if ($usuario === null) {
        header('Location: bienvenida.php');
        exit;
    }
    if ($usuario['debe_cambiar_clave']) {
        header('Location: cambiar-clave.php');
        exit;
    }
    return $usuario;
}

/**
 * Para la API: responde 401 sin sesión y 403 sin permiso.
 * Libera el archivo de sesión al terminar, para que varias peticiones del mismo usuario no se esperen.
 */
function requerirSesionApi(?string $permiso = null): array
{
    noGuardarEnCache();
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $usuario = usuarioActual();
    } catch (Throwable $e) {
        error_log('sesion: ' . $e->getMessage());
        responderJson(503, ['status' => 'error', 'codigo' => 'sin_servicio', 'message' => 'El servicio de acceso no está disponible.']);
    }
    if ($usuario === null) {
        responderJson(401, ['status' => 'error', 'codigo' => 'sin_sesion', 'message' => 'Tu sesión terminó. Vuelve a ingresar.']);
    }
    if ($usuario['debe_cambiar_clave']) {
        responderJson(403, ['status' => 'error', 'codigo' => 'cambio_clave', 'message' => 'Debes cambiar tu contraseña temporal.']);
    }
    if ($permiso !== null && !puede($usuario, $permiso)) {
        registrarAcceso('acceso_denegado', $usuario['id'], $usuario['usuario'], $permiso);
        responderJson(403, ['status' => 'error', 'codigo' => 'sin_permiso', 'message' => 'Tu rol no tiene permiso para esta acción.']);
    }
    session_write_close();
    return $usuario;
}

function noGuardarEnCache(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

function responderJson(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Cuerpo JSON de una petición POST, o arreglo vacío si no es válido. */
function leerJson(): array
{
    $datos = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($datos) ? $datos : [];
}

// --- Protección CSRF: un token por sesión que otro sitio no puede conocer ---

function tokenCsrf(): string
{
    iniciarSesion();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verificarCsrf(?string $token): bool
{
    iniciarSesion();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Para la API: exige el token en la cabecera X-CSRF-Token. */
function exigirCsrfApi(): void
{
    if (!verificarCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        responderJson(403, ['status' => 'error', 'codigo' => 'csrf', 'message' => 'La página expiró. Recárgala e intenta de nuevo.']);
    }
}

// --- Contraseñas ---

/** Devuelve el motivo por el que la contraseña no es aceptable, o null si cumple. */
function validarClave(string $clave, string $usuario): ?string
{
    if (mb_strlen($clave) < 10)                        return 'Debe tener al menos 10 caracteres.';
    if (!preg_match('/[a-z]/', $clave))                return 'Debe tener al menos una letra minúscula.';
    if (!preg_match('/[A-Z]/', $clave))                return 'Debe tener al menos una letra mayúscula.';
    if (!preg_match('/[0-9]/', $clave))                return 'Debe tener al menos un número.';
    if (stripos($clave, $usuario) !== false)           return 'No puede contener tu nombre de usuario.';
    return null;
}

/** Contraseña temporal de 12 caracteres, sin letras que se confundan (O/0, l/1). */
function generarClaveTemporal(): string
{
    $grupos = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
    $todos  = implode('', $grupos);
    $clave  = [];
    foreach ($grupos as $g) {
        $clave[] = $g[random_int(0, strlen($g) - 1)];
    }
    while (count($clave) < 12) {
        $clave[] = $todos[random_int(0, strlen($todos) - 1)];
    }
    // Mezcla con random_int (shuffle no es criptográficamente seguro)
    for ($i = count($clave) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$clave[$i], $clave[$j]] = [$clave[$j], $clave[$i]];
    }
    return implode('', $clave);
}

// --- Captcha propio (sin servicios externos) ---

function verificarCaptcha(?string $respuesta): bool
{
    iniciarSesion();
    $captcha = $_SESSION['captcha'] ?? null;
    unset($_SESSION['captcha']); // un solo intento por imagen
    return $captcha !== null
        && time() <= $captcha['expira']
        && is_string($respuesta)
        && hash_equals($captcha['codigo'], strtoupper(trim($respuesta)));
}

// --- Bitácora ---

/** Registra un evento de acceso. Si la bitácora falla, lo deja en el log de PHP sin interrumpir. */
function registrarAcceso(string $evento, ?int $usuarioId, string $usuarioTexto, string $detalle = ''): void
{
    try {
        BaseSeguridad::conexion()->prepare(
            'INSERT INTO accesos (usuario_id, usuario_texto, evento, detalle, ip, agente) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $usuarioId,
            mb_substr($usuarioTexto, 0, 40),
            $evento,
            mb_substr($detalle, 0, 255),
            $_SERVER['REMOTE_ADDR'] ?? 'cli',
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log("bitácora ($evento): " . $e->getMessage());
    }
}

/** Escapa texto para insertarlo en HTML. */
function h(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
