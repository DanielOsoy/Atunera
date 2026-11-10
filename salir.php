<?php

/**
 * Cierre de sesión. Solo por POST con token CSRF, para que otro sitio no pueda cerrar la sesión del usuario.
 */

require_once __DIR__ . '/seguridad/sesion.php';

noGuardarEnCache();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verificarCsrf($_POST['csrf'] ?? null)) {
    header('Location: index.php');
    exit;
}

$usuario = $_SESSION['usuario'] ?? null;
if ($usuario) {
    registrarAcceso('logout', $usuario['id'], $usuario['usuario']);
}
cerrarSesion();
header('Location: login.php?salida=1');
