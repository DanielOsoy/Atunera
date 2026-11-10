<?php

/**
 * Imagen del captcha del inicio de sesión.
 * Cada petición genera un código nuevo de 5 caracteres, válido por 5 minutos y para un solo intento.
 * El código queda solo en la sesión del servidor; nunca viaja como texto al navegador.
 */

require_once __DIR__ . '/seguridad/sesion.php';

iniciarSesion();

$alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$codigo = '';
for ($i = 0; $i < 5; $i++) {
    $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
}
$_SESSION['captcha'] = ['codigo' => $codigo, 'expira' => time() + 300];
session_write_close();

$ancho = 190;
$alto  = 60;
$img   = imagecreatetruecolor($ancho, $alto);
imagefill($img, 0, 0, imagecolorallocate($img, 241, 245, 249));

// Ruido de fondo: líneas y puntos que dificultan leer el código de forma automática
for ($i = 0; $i < 7; $i++) {
    $color = imagecolorallocate($img, random_int(140, 200), random_int(150, 210), random_int(170, 220));
    imagesetthickness($img, random_int(1, 2));
    imageline($img, random_int(0, $ancho), random_int(0, $alto), random_int(0, $ancho), random_int(0, $alto), $color);
}
for ($i = 0; $i < 220; $i++) {
    $color = imagecolorallocate($img, random_int(120, 210), random_int(120, 210), random_int(120, 210));
    imagesetpixel($img, random_int(0, $ancho - 1), random_int(0, $alto - 1), $color);
}

$fuente = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
for ($i = 0; $i < strlen($codigo); $i++) {
    $color = imagecolorallocate($img, random_int(15, 70), random_int(30, 80), random_int(60, 110));
    $x = 16 + $i * 34 + random_int(-3, 3);
    if (is_readable($fuente)) {
        imagettftext($img, random_int(22, 27), random_int(-22, 22), $x, random_int(38, 48), $color, $fuente, $codigo[$i]);
    } else {
        imagestring($img, 5, $x, random_int(18, 28), $codigo[$i], $color);
    }
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
imagepng($img);
imagedestroy($img);
