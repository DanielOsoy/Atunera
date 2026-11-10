<?php

/**
 * Encabezado y pie HTML de las páginas públicas (bienvenida, ingreso, cambio de contraseña),
 * con el mismo estilo que la app.
 */

function abrirPagina(string $titulo): void
{
    ?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($titulo) ?> · Atunera</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background-color: #F8FAFC; font-family: 'Plus Jakarta Sans', sans-serif; color: #334155; }
        .card-pro { background: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.04); border: 1px solid #F1F5F9; }
        .bg-primary { background-color: #0EA5E9; } .text-primary { color: #0EA5E9; }
    </style>
</head>
<body class="min-h-screen antialiased">
<?php
}

function cerrarPagina(): void
{
    ?>
</body>
</html>
<?php
}

/** Logo y nombre del sistema, igual que en la barra lateral de la app. */
function logoAtunera(string $tamano = 'text-2xl'): void
{
    ?>
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-primary text-white rounded-xl flex items-center justify-center text-xl shadow-md"><i class="fas fa-fish"></i></div>
        <span class="<?= $tamano ?> font-extrabold tracking-tight text-slate-900">Atunera</span>
    </div>
    <?php
}
