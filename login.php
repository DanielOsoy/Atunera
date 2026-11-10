<?php

/**
 * Inicio de sesión: usuario, rol, contraseña y captcha.
 *
 * Reglas:
 *   - Solo entran cuentas creadas por el desarrollador; no hay registro abierto.
 *   - El rol elegido debe coincidir con el de la cuenta.
 *   - Ante cualquier error el mensaje es el mismo, para no revelar qué usuarios existen.
 *   - 5 fallos seguidos bloquean la cuenta 15 minutos; 10 fallos desde una IP la bloquean igual tiempo.
 *   - Cada intento queda en la bitácora.
 */

require_once __DIR__ . '/seguridad/sesion.php';
require_once __DIR__ . '/seguridad/plantilla.php';

noGuardarEnCache();
iniciarSesion();

$error   = null;
$aviso   = null;
$usuario = '';
$rol     = '';

if (isset($_GET['expirada'])) $aviso = 'Tu sesión terminó por inactividad. Vuelve a ingresar.';
if (isset($_GET['salida']))   $aviso = 'Cerraste sesión correctamente.';

try {
    if (usuarioActual() !== null) {
        header('Location: index.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $usuario = strtolower(trim((string)($_POST['usuario'] ?? '')));
        $rol     = (string)($_POST['rol'] ?? '');
        $clave   = (string)($_POST['clave'] ?? '');
        $error   = procesarIngreso($usuario, $rol, $clave);
    }
} catch (Throwable $e) {
    error_log('login: ' . $e->getMessage());
    $error = 'El servicio de acceso no está disponible. Intenta de nuevo en unos minutos.';
}

/**
 * Valida el intento. Si es correcto abre la sesión y redirige; si no, devuelve el mensaje de error.
 */
function procesarIngreso(string $usuario, string $rol, string $clave): ?string
{
    $generico = 'Usuario, rol o contraseña incorrectos.';

    if (!verificarCsrf($_POST['csrf'] ?? null)) {
        return 'La página expiró. Intenta de nuevo.';
    }
    if (!verificarCaptcha($_POST['captcha'] ?? null)) {
        registrarAcceso('captcha_fallido', null, $usuario);
        return 'El código de verificación no coincide. Escribe el de la imagen nueva.';
    }
    if (!preg_match('/^[a-z][a-z0-9._]{2,39}$/', $usuario) || !isset(ROLES[$rol]) || $clave === '') {
        registrarAcceso('login_fallido', null, $usuario, 'datos con formato inválido');
        return $generico;
    }

    $db = BaseSeguridad::conexion();

    $fallosIp = $db->prepare(
        "SELECT COUNT(*) FROM accesos WHERE ip = ? AND evento = 'login_fallido' AND fecha > NOW(3) - INTERVAL ? MINUTE"
    );
    $fallosIp->execute([$_SERVER['REMOTE_ADDR'] ?? '', BLOQUEO_MINUTOS]);
    if ((int)$fallosIp->fetchColumn() >= FALLOS_MAXIMOS_IP) {
        registrarAcceso('ip_bloqueada', null, $usuario);
        return 'Demasiados intentos fallidos. Espera ' . BLOQUEO_MINUTOS . ' minutos.';
    }

    $stmt = $db->prepare(
        'SELECT id, usuario, nombre, rol, clave_hash, activo, debe_cambiar_clave, intentos_fallidos,
                bloqueado_hasta > NOW() AS bloqueado
         FROM usuarios WHERE usuario = ?'
    );
    $stmt->execute([$usuario]);
    $fila = $stmt->fetch();

    // Se verifica aunque el usuario no exista, para que el tiempo de respuesta no lo delate
    $claveOk = $fila
        ? password_verify($clave, $fila['clave_hash'])
        : password_verify($clave, password_hash(random_bytes(16), PASSWORD_DEFAULT)) && false;

    if ($fila && (int)$fila['bloqueado'] === 1) {
        registrarAcceso('login_bloqueado', (int)$fila['id'], $usuario);
        return 'Demasiados intentos fallidos. Espera ' . BLOQUEO_MINUTOS . ' minutos.';
    }

    if ($fila && $claveOk && (int)$fila['activo'] === 1 && $fila['rol'] === $rol) {
        $db->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?')
           ->execute([$fila['id']]);
        if (password_needs_rehash($fila['clave_hash'], PASSWORD_DEFAULT)) {
            $db->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')
               ->execute([password_hash($clave, PASSWORD_DEFAULT), $fila['id']]);
        }
        abrirSesionDe($fila);
        registrarAcceso('login_ok', (int)$fila['id'], $usuario);
        header('Location: ' . ((int)$fila['debe_cambiar_clave'] === 1 ? 'cambiar-clave.php' : 'index.php'));
        exit;
    }

    if ($fila) {
        $intentos = (int)$fila['intentos_fallidos'] + 1;
        $db->prepare(
            'UPDATE usuarios SET intentos_fallidos = ?,
                    bloqueado_hasta = IF(? >= ?, NOW() + INTERVAL ? MINUTE, bloqueado_hasta)
             WHERE id = ?'
        )->execute([$intentos, $intentos, INTENTOS_MAXIMOS, BLOQUEO_MINUTOS, $fila['id']]);
        $motivo = (int)$fila['activo'] !== 1 ? 'cuenta desactivada' : ($claveOk ? 'rol incorrecto' : 'contraseña incorrecta');
        registrarAcceso('login_fallido', (int)$fila['id'], $usuario, $motivo);
        if ($intentos >= INTENTOS_MAXIMOS) {
            registrarAcceso('cuenta_bloqueada', (int)$fila['id'], $usuario, "$intentos intentos fallidos");
        }
    } else {
        registrarAcceso('login_fallido', null, $usuario, 'usuario inexistente');
    }
    return $generico;
}

abrirPagina('Ingresar');
?>
<div class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md space-y-6">
        <div class="flex justify-center"><?php logoAtunera('text-3xl'); ?></div>

        <form method="post" action="login.php" class="card-pro p-8 space-y-5" autocomplete="off">
            <div>
                <h1 class="text-xl font-extrabold text-slate-900">Iniciar sesión</h1>
                <p class="text-sm text-slate-500">Ingresa con la cuenta que te asignó el área de TI.</p>
            </div>

            <?php if ($aviso && !$error): ?>
                <p class="bg-sky-50 border border-sky-200 text-sky-700 text-sm font-bold rounded-lg px-4 py-3"><?= h($aviso) ?></p>
            <?php endif; ?>
            <?php if ($error): ?>
                <p role="alert" class="bg-rose-50 border border-rose-200 text-rose-700 text-sm font-bold rounded-lg px-4 py-3"><?= h($error) ?></p>
            <?php endif; ?>

            <input type="hidden" name="csrf" value="<?= h(tokenCsrf()) ?>">

            <div>
                <label for="usuario" class="block text-[11px] font-bold text-slate-500 mb-1">USUARIO</label>
                <input id="usuario" name="usuario" type="text" required maxlength="40" value="<?= h($usuario) ?>"
                       autocapitalize="none" spellcheck="false" autofocus
                       class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold" placeholder="Ej: nohemy">
            </div>

            <div>
                <label for="rol" class="block text-[11px] font-bold text-slate-500 mb-1">ROL</label>
                <select id="rol" name="rol" required class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold">
                    <option value="">Selecciona tu rol</option>
                    <?php foreach (ROLES as $valor => $nombre): ?>
                        <option value="<?= h($valor) ?>" <?= $rol === $valor ? 'selected' : '' ?>><?= h($nombre) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="clave" class="block text-[11px] font-bold text-slate-500 mb-1">CONTRASEÑA</label>
                <input id="clave" name="clave" type="password" required maxlength="200" autocomplete="current-password"
                       class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold">
            </div>

            <div>
                <label for="captcha" class="block text-[11px] font-bold text-slate-500 mb-1">CÓDIGO DE VERIFICACIÓN</label>
                <div class="flex items-center gap-3 mb-2">
                    <img id="captchaImg" src="captcha.php" alt="Código de verificación" width="190" height="60" class="rounded-lg border border-slate-200">
                    <button type="button" id="captchaNuevo" class="text-slate-500 hover:text-primary text-sm font-bold" title="Mostrar otro código">
                        <i class="fas fa-sync-alt"></i> Otro código
                    </button>
                </div>
                <input id="captcha" name="captcha" type="text" required maxlength="5" autocomplete="off"
                       class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold uppercase tracking-widest" placeholder="Escribe los 5 caracteres">
            </div>

            <button type="submit" class="w-full bg-primary hover:bg-sky-600 text-white font-bold py-3.5 rounded-xl transition-colors">
                <i class="fas fa-sign-in-alt"></i> Ingresar
            </button>

            <p class="text-xs text-slate-400 font-bold text-center">
                <i class="fas fa-lock"></i> Acceso exclusivo para personal autorizado. Los intentos de ingreso quedan registrados.
            </p>
        </form>

        <p class="text-center"><a href="bienvenida.php" class="text-sm font-bold text-slate-500 hover:text-primary"><i class="fas fa-arrow-left"></i> Volver al inicio</a></p>
    </div>
</div>
<script>
    // La contraseña se escribe a mano: no se puede pegar, copiar, cortar ni arrastrar
    ['paste', 'copy', 'cut', 'drop'].forEach(evento =>
        document.getElementById('clave').addEventListener(evento, e => e.preventDefault()));

    document.getElementById('captchaNuevo').addEventListener('click', () => {
        document.getElementById('captchaImg').src = 'captcha.php?' + Date.now();
        document.getElementById('captcha').value = '';
    });
</script>
<?php
cerrarPagina();
