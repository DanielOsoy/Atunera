<?php

/**
 * Cambio de contraseña. Obligatorio en el primer ingreso (contraseña temporal);
 * también disponible desde la app para cambiarla cuando se quiera.
 */

require_once __DIR__ . '/seguridad/sesion.php';
require_once __DIR__ . '/seguridad/plantilla.php';

noGuardarEnCache();

try {
    $usuario = usuarioActual();
} catch (Throwable $e) {
    error_log('cambiar-clave: ' . $e->getMessage());
    http_response_code(503);
    exit('El servicio de acceso no está disponible. Intenta de nuevo en unos minutos.');
}
if ($usuario === null) {
    header('Location: login.php?expirada=1');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual    = (string)($_POST['actual'] ?? '');
    $nueva     = (string)($_POST['nueva'] ?? '');
    $confirmar = (string)($_POST['confirmar'] ?? '');

    $db   = BaseSeguridad::conexion();
    $stmt = $db->prepare('SELECT clave_hash FROM usuarios WHERE id = ?');
    $stmt->execute([$usuario['id']]);
    $hash = (string)$stmt->fetchColumn();

    if (!verificarCsrf($_POST['csrf'] ?? null)) {
        $error = 'La página expiró. Intenta de nuevo.';
    } elseif (!password_verify($actual, $hash)) {
        registrarAcceso('cambio_clave_fallido', $usuario['id'], $usuario['usuario'], 'contraseña actual incorrecta');
        $error = 'La contraseña actual no es correcta.';
    } elseif ($nueva !== $confirmar) {
        $error = 'La nueva contraseña y su confirmación no coinciden.';
    } elseif ($nueva === $actual) {
        $error = 'La nueva contraseña debe ser distinta de la actual.';
    } elseif (($motivo = validarClave($nueva, $usuario['usuario'])) !== null) {
        $error = $motivo;
    } else {
        $db->prepare('UPDATE usuarios SET clave_hash = ?, debe_cambiar_clave = 0 WHERE id = ?')
           ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuario['id']]);
        registrarAcceso('cambio_clave', $usuario['id'], $usuario['usuario']);
        session_regenerate_id(true);
        $_SESSION['usuario']['debe_cambiar_clave'] = false;
        header('Location: index.php');
        exit;
    }
}

abrirPagina('Cambiar contraseña');
?>
<div class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md space-y-6">
        <div class="flex justify-center"><?php logoAtunera('text-3xl'); ?></div>

        <form method="post" action="cambiar-clave.php" class="card-pro p-8 space-y-5">
            <div>
                <h1 class="text-xl font-extrabold text-slate-900">Cambiar contraseña</h1>
                <?php if ($usuario['debe_cambiar_clave']): ?>
                    <p class="text-sm text-slate-500">Hola, <?= h($usuario['nombre']) ?>. Antes de continuar, reemplaza la contraseña temporal que te asignaron.</p>
                <?php else: ?>
                    <p class="text-sm text-slate-500"><?= h($usuario['nombre']) ?> · <?= h(ROLES[$usuario['rol']]) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
                <p role="alert" class="bg-rose-50 border border-rose-200 text-rose-700 text-sm font-bold rounded-lg px-4 py-3"><?= h($error) ?></p>
            <?php endif; ?>

            <input type="hidden" name="csrf" value="<?= h(tokenCsrf()) ?>">

            <div>
                <label for="actual" class="block text-[11px] font-bold text-slate-500 mb-1">CONTRASEÑA ACTUAL</label>
                <input id="actual" name="actual" type="password" required maxlength="200" autocomplete="current-password"
                       class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold">
            </div>
            <div>
                <label for="nueva" class="block text-[11px] font-bold text-slate-500 mb-1">NUEVA CONTRASEÑA</label>
                <input id="nueva" name="nueva" type="password" required maxlength="200" autocomplete="new-password"
                       class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold">
            </div>
            <div>
                <label for="confirmar" class="block text-[11px] font-bold text-slate-500 mb-1">CONFIRMAR NUEVA CONTRASEÑA</label>
                <input id="confirmar" name="confirmar" type="password" required maxlength="200" autocomplete="new-password"
                       class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold">
            </div>

            <ul class="text-xs text-slate-500 space-y-1 bg-slate-50 rounded-lg p-4">
                <li>· Al menos 10 caracteres</li>
                <li>· Una mayúscula, una minúscula y un número</li>
                <li>· Sin tu nombre de usuario</li>
            </ul>

            <button type="submit" class="w-full bg-primary hover:bg-sky-600 text-white font-bold py-3.5 rounded-xl transition-colors">
                <i class="fas fa-key"></i> Guardar contraseña
            </button>
        </form>

        <form method="post" action="salir.php" class="text-center">
            <input type="hidden" name="csrf" value="<?= h(tokenCsrf()) ?>">
            <button type="submit" class="text-sm font-bold text-slate-500 hover:text-primary"><i class="fas fa-sign-out-alt"></i> Cerrar sesión</button>
        </form>
    </div>
</div>
<?php
cerrarPagina();
