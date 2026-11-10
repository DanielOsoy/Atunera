<?php

/**
 * Crea las cuentas iniciales (solo las que aún no existen) con contraseñas temporales.
 * Cada persona debe cambiar la suya en el primer ingreso.
 *
 * Las contraseñas se escriben en .devcontainer/credenciales-temporales.txt, que git ignora
 * y Apache no sirve. Repártelas y borra el archivo.
 *
 * Uso (dentro del contenedor app): php seguridad/crear-usuarios.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/sesion.php';

// Nohemy, Freddy y Daniel son reales; el resto son nombres provisionales que el desarrollador
// puede cambiar desde Administración cuando se conozcan las personas.
const CUENTAS_INICIALES = [
    ['nohemy',  'Nohemy',          'trazabilidad'],
    ['karla',   'Karla Morales',   'trazabilidad'],
    ['freddy',  'Freddy',          'gerente'],
    ['mario',   'Mario Castillo',  'gerente'],
    ['lucia',   'Lucía Herrera',   'gerente'],
    ['andrea',  'Andrea Pineda',   'gerente'],
    ['roberto', 'Roberto Salazar', 'gerente'],
    ['sofia',   'Sofía Ramírez',   'gerente'],
    ['daniel',  'Daniel',          'desarrollador'],
    ['jorge',   'Jorge Estrada',   'desarrollador'],
];

$db      = BaseSeguridad::conexion();
$existe  = $db->prepare('SELECT 1 FROM usuarios WHERE usuario = ?');
$insertar = $db->prepare(
    'INSERT INTO usuarios (usuario, nombre, rol, clave_hash, debe_cambiar_clave) VALUES (?, ?, ?, ?, 1)'
);

$lineas = [];
foreach (CUENTAS_INICIALES as [$usuario, $nombre, $rol]) {
    $existe->execute([$usuario]);
    if ($existe->fetchColumn()) {
        echo "Ya existe: $usuario (no se modifica)\n";
        continue;
    }
    $clave = generarClaveTemporal();
    $insertar->execute([$usuario, $nombre, $rol, password_hash($clave, PASSWORD_DEFAULT)]);
    registrarAcceso('usuario_creado', null, 'sistema', "$usuario ($rol)");
    $lineas[] = sprintf('%-10s %-18s %-14s %s', $usuario, $nombre, ROLES[$rol], $clave);
    echo "Creado: $usuario ($rol)\n";
}

if ($lineas) {
    $archivo = __DIR__ . '/../.devcontainer/credenciales-temporales.txt';
    $texto = "Contraseñas temporales (" . date('Y-m-d H:i') . "). Cada persona debe cambiarla al ingresar.\n"
           . "Reparte cada una en privado y BORRA este archivo.\n\n"
           . sprintf('%-10s %-18s %-14s %s', 'USUARIO', 'NOMBRE', 'ROL', 'CONTRASEÑA') . "\n"
           . implode("\n", $lineas) . "\n";
    file_put_contents($archivo, $texto, FILE_APPEND);
    chmod($archivo, 0600);
    echo count($lineas) . " contraseñas escritas en .devcontainer/credenciales-temporales.txt\n";
}
