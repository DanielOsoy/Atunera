<?php

/**
 * Pantalla de bienvenida: lo primero que ve quien abre el sistema.
 * Es pública, pero no muestra datos del negocio; para entrar hay que iniciar sesión.
 */

require_once __DIR__ . '/seguridad/sesion.php';
require_once __DIR__ . '/seguridad/plantilla.php';

noGuardarEnCache();
try {
    $usuario = usuarioActual();
} catch (Throwable $e) {
    error_log('bienvenida: ' . $e->getMessage());
    $usuario = null;
}

abrirPagina('Bienvenida');
?>
<div class="min-h-screen flex flex-col">
    <header class="bg-white border-b border-slate-100 h-20 px-6 md:px-10 flex items-center justify-between">
        <?php logoAtunera(); ?>
        <p class="hidden md:block text-xs font-bold text-slate-400 uppercase tracking-widest">Sistema de toma de decisiones</p>
    </header>

    <main class="flex-1 flex items-center justify-center px-4 py-10">
        <div class="max-w-4xl w-full space-y-8">
            <div class="text-center space-y-4">
                <p class="text-xs font-bold text-primary uppercase tracking-widest">Industria atunera</p>
                <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900 tracking-tight">Bienvenido al sistema de trazabilidad y rendimiento</h1>
                <p class="text-slate-500 max-w-2xl mx-auto">
                    Consulta el rendimiento de cada compra frente a su estándar, sigue la trazabilidad de las partidas
                    y proyecta el rendimiento de las próximas compras.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="card-pro p-6">
                    <i class="fas fa-chart-line text-2xl text-primary"></i>
                    <h2 class="font-bold text-slate-800 mt-3">Dashboard</h2>
                    <p class="text-sm text-slate-500 mt-1">Indicadores generales y consulta rápida de una compra.</p>
                </div>
                <div class="card-pro p-6">
                    <i class="fas fa-project-diagram text-2xl text-primary"></i>
                    <h2 class="font-bold text-slate-800 mt-3">Trazabilidad y Futuro</h2>
                    <p class="text-sm text-slate-500 mt-1">Historial de partidas, gráficas por barco y proyección de rendimiento.</p>
                </div>
                <div class="card-pro p-6">
                    <i class="fas fa-file-alt text-2xl text-primary"></i>
                    <h2 class="font-bold text-slate-800 mt-3">Reporte Automatizado</h2>
                    <p class="text-sm text-slate-500 mt-1">Resumen ejecutivo de una compra, listo para imprimir.</p>
                </div>
            </div>

            <div class="card-pro p-8 text-center space-y-4">
                <?php if ($usuario): ?>
                    <p class="text-slate-600">Ya iniciaste sesión como <strong><?= h($usuario['nombre']) ?></strong>.</p>
                    <a href="index.php" class="inline-block bg-primary hover:bg-sky-600 text-white font-bold px-8 py-3.5 rounded-xl transition-colors">
                        <i class="fas fa-arrow-right"></i> Entrar al sistema
                    </a>
                <?php else: ?>
                    <a href="login.php" class="inline-block bg-primary hover:bg-sky-600 text-white font-bold px-8 py-3.5 rounded-xl transition-colors">
                        <i class="fas fa-sign-in-alt"></i> Ingresar
                    </a>
                <?php endif; ?>
                <p class="text-xs text-slate-400 font-bold">
                    <i class="fas fa-lock"></i> Acceso exclusivo para personal autorizado de la organización.
                </p>
            </div>
        </div>
    </main>
</div>
<?php
cerrarPagina();
