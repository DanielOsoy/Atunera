<?php
// Solo entra quien tiene sesión; sin ella se va a la bienvenida, y con contraseña temporal, a cambiarla.
require_once __DIR__ . '/seguridad/sesion.php';
$usuario  = requerirSesionPagina();
$permisos = permisosDeRol($usuario['rol']);
$sesionCliente = [
    'nombre'    => $usuario['nombre'],
    'usuario'   => $usuario['usuario'],
    'rol'       => $usuario['rol'],
    'rolNombre' => ROLES[$usuario['rol']],
    'permisos'  => $permisos,
    'csrf'      => tokenCsrf(),
];
$verAdministracion = array_intersect(['ver_sincronizacion', 'ver_bitacora', 'administrar_usuarios'], $permisos) !== [];
?>
<!DOCTYPE html>
<!--
    index.php — SPA principal del sistema Atunera
    ================================================
    Arquitectura: Single Page Application (SPA) de una sola pantalla PHP+JS.
    No hay router del lado del servidor; la navegación entre secciones se hace
    ocultando/mostrando divs con la clase "screen-content" via JavaScript.

    Pantallas disponibles:
      - dashboard  : KPIs de compra, formulario de consulta/registro, monitor en vivo
      - history    : Matriz de trazabilidad, gráfica histórica, modelo predictivo
      - report     : Resumen ejecutivo de una compra y mejores/peores compras

    Dependencias externas (CDN):
      - Tailwind CSS     : utilidades de estilos
      - Chart.js         : gráficas de línea
      - chartjs-plugin-annotation : líneas de referencia dentro de las gráficas
      - Font Awesome     : íconos
      - Plus Jakarta Sans: tipografía
-->
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atunera</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.0.1/dist/chartjs-plugin-annotation.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* Fondo global y fuente base */
        body { background-color: #F8FAFC; font-family: 'Plus Jakarta Sans', sans-serif; color: #334155; }

        /* Tarjeta estándar del sistema: fondo blanco, sombra sutil, borde gris claro */
        .card-pro { background: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); border: 1px solid #F1F5F9; }

        /* Color primario (#0EA5E9 = sky-500 de Tailwind) */
        .text-primary { color: #0EA5E9; } .bg-primary { background-color: #0EA5E9; }

        /* Ítem del menú lateral: resaltado con borde derecho azul al pasar el mouse o estar activo */
        .sidebar-item:hover, .sidebar-item.active { background-color: #F0F9FF; color: #0EA5E9; border-right: 4px solid #0EA5E9; }

        /* Control de visibilidad de pantallas — solo la pantalla activa se muestra */
        .screen-content { display: none; } .screen-content.active { display: block; }

        /* Contenedor de gráficas Chart.js — alto fijo requerido para que Chart.js calcule dimensiones */
        .chart-container { position: relative; height: 350px; width: 100%; }

        /* Spinner del loader del modelo predictivo (animación de rotación) */
        .loader-ai { border-top-color: #8B5CF6; animation: spinner 1.5s linear infinite; }
        @keyframes spinner { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Modo impresión: oculta navegación y controles, expande todas las pantallas */
        @media print {
            /* Resetear el layout para que el contenido fluya normalmente */
            html, body {
                height: auto !important;
                overflow: visible !important;
                background-color: white !important;
            }
            /* Ocultar elementos de UI que no van en el informe */
            aside, header, #predictionForm, .no-print,
            button, input, select, label { display: none !important; }

            /* El main deja de ser flex+scroll y ocupa el ancho completo */
            main, .flex.h-screen, .flex-1, .overflow-y-auto {
                display: block !important;
                height: auto !important;
                overflow: visible !important;
                width: 100% !important;
            }
            /* Mostrar la pantalla activa y expandirla */
            .screen-content { display: block !important; height: auto !important; }

            /* El reporte automatizado se imprime solo, sin las otras pantallas */
            body.report-active .screen-content:not(#report-screen) { display: none !important; }

            /* Cards sin sombras ni bordes para impresión limpia */
            .card-pro {
                border: 1px solid #e2e8f0 !important;
                box-shadow: none !important;
                margin-bottom: 24px;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            /* La tabla de trazabilidad se expande en lugar de usar scroll */
            .overflow-x-auto.h-80 {
                height: auto !important;
                overflow: visible !important;
                max-height: none !important;
            }
            /* Los contenedores de gráficas se expanden para mostrar el canvas completo */
            .chart-container {
                height: auto !important;
                min-height: 300px;
                overflow: visible !important;
                page-break-inside: avoid;
            }
            /* Mostrar contenedores que están ocultos con hidden si tienen contenido */
            #temporalContainer, #chartContainer {
                display: block !important;
            }
        }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased">

    <!-- =====================================================================
         SIDEBAR — Menú de navegación lateral
         Solo visible en pantallas medianas/grandes (hidden md:flex).
         Cada enlace llama a loadScreen() para cambiar de pantalla.
    ====================================================================== -->
    <aside class="w-72 bg-white border-r border-slate-100 flex flex-col hidden md:flex h-full shrink-0">
        <!-- Logo / encabezado de la app -->
        <div class="h-24 flex items-center px-8 border-b border-slate-50">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-primary text-white rounded-xl flex items-center justify-center text-xl shadow-md"><i class="fas fa-fish"></i></div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">Atunera</h1>
            </div>
        </div>
        <!-- Ítems de navegación -->
        <nav class="flex-1 overflow-y-auto py-6 px-4 space-y-1">
            <p class="px-4 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest mb-3 mt-2">Analítica Gerencial</p>
            <a href="#" onclick="loadScreen('dashboard')" class="sidebar-item active flex items-center gap-3 px-4 py-3.5 rounded-xl text-sm font-bold text-slate-600">
                <i class="fas fa-chart-line w-5 text-lg"></i> Dashboard
            </a>
            <a href="#" onclick="loadScreen('history')" class="sidebar-item flex items-center gap-3 px-4 py-3.5 rounded-xl text-sm font-bold text-slate-600">
                <i class="fas fa-project-diagram w-5 text-lg"></i> Trazabilidad y Futuro
            </a>
            <a href="#" onclick="loadScreen('report')" class="sidebar-item flex items-center gap-3 px-4 py-3.5 rounded-xl text-sm font-bold text-slate-600">
                <i class="fas fa-file-alt w-5 text-lg"></i> Reporte Automatizado
            </a>
            <?php if ($verAdministracion): ?>
            <p class="px-4 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest mb-3 mt-6">Supervisión</p>
            <a href="#" onclick="loadScreen('admin')" class="sidebar-item flex items-center gap-3 px-4 py-3.5 rounded-xl text-sm font-bold text-slate-600">
                <i class="fas fa-user-shield w-5 text-lg"></i> Administración
            </a>
            <?php endif; ?>
        </nav>
    </aside>

    <!-- =====================================================================
         ÁREA PRINCIPAL — Header + contenido de pantallas
    ====================================================================== -->
    <div class="flex-1 flex flex-col overflow-hidden h-full">

        <!-- Header superior con título dinámico y botón de PDF -->
        <header class="bg-white border-b border-slate-100 h-24 px-10 flex justify-between items-center shrink-0">
            <div>
                <!-- screenTitle se actualiza en loadScreen() según la pantalla activa -->
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight" id="screenTitle">Dashboard</h2>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mt-1">SISTEMA TOMA DE DECISIONES</p>
            </div>
            <div class="flex items-center gap-4">
                <!-- Dispara window.print() — los estilos @media print ocultan navegación -->
                <button onclick="window.print()" class="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2.5 rounded-lg text-sm font-bold transition-all flex items-center gap-2 shadow-md">
                    <i class="fas fa-file-pdf"></i> Generar Informe PDF
                </button>
                <!-- Usuario de la sesión: nombre, rol, cambio de contraseña y cierre de sesión -->
                <div class="flex items-center gap-3 border-l border-slate-100 pl-4">
                    <div class="text-right">
                        <p id="sesionNombre" class="text-sm font-extrabold text-slate-800"><?= h($usuario['nombre']) ?></p>
                        <p id="sesionRol" class="text-[10px] font-bold text-primary uppercase tracking-widest"><?= h(ROLES[$usuario['rol']]) ?></p>
                    </div>
                    <a href="cambiar-clave.php" title="Cambiar contraseña" class="text-slate-400 hover:text-primary"><i class="fas fa-key"></i></a>
                    <form method="post" action="salir.php">
                        <input type="hidden" name="csrf" value="<?= h($sesionCliente['csrf']) ?>">
                        <button type="submit" title="Cerrar sesión" class="text-slate-400 hover:text-rose-600"><i class="fas fa-sign-out-alt"></i></button>
                    </form>
                </div>
            </div>
        </header>

        <!-- Aviso visible cuando falla una carga de datos (ver showAppError) -->
        <div id="appError" role="alert" class="hidden no-print bg-rose-50 border-b border-rose-200 px-10 py-3 flex items-center justify-between gap-4 shrink-0">
            <p id="appErrorText" class="text-sm font-bold text-rose-700"></p>
            <button onclick="retryLoad()" class="bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded-lg text-xs font-bold whitespace-nowrap">Reintentar</button>
        </div>

        <main class="flex-1 overflow-y-auto p-8 bg-slate-50 h-full">
            <div class="max-w-[1500px] mx-auto space-y-8">

                <!-- =============================================================
                     PANTALLA: DASHBOARD
                     Visible al inicio. Contiene:
                       - Fila de KPIs (rendimiento, alertas, total registros)
                       - Formulario de consulta/registro de compra
                       - Panel de proyección de recuperación
                       - Monitor en vivo (últimos registros)
                ============================================================= -->
                <div id="dashboard-screen" class="screen-content active space-y-8">

                    <!-- --- KPIs: tarjetas de resumen --- -->
                    <!-- Arrancan vacíos y se llenan al buscar una compra (lookupCompra)
                         o al navegar a Trazabilidad y Futuro (fetchFullHistory).
                         Al borrar el código de compra vuelven a los globales (showGlobalKPIs). -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                        <!-- KPI 1: Rendimiento Promedio + Ponderado Estándar
                             kpiRendimiento = promedio simple de rendimientos de la compra buscada
                             kpiPonderado   = estándar ponderado por distribución de tallas -->
                        <div class="card-pro p-6 border-l-4 border-emerald-500 flex justify-between items-center">
                            <div>
                                <p class="text-xs font-bold text-slate-400 uppercase mb-1">Rendimiento Promedio</p>
                                <div class="flex items-end gap-3">
                                    <h3 class="text-3xl font-extrabold" id="kpiRendimiento"></h3>
                                    <span class="text-slate-300 text-2xl font-light mb-0.5">/</span>
                                    <div>
                                        <p class="text-[9px] font-bold text-slate-400 uppercase leading-none mb-0.5">Pond. Est.</p>
                                        <h3 class="text-2xl font-extrabold text-slate-500" id="kpiPonderado"></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center text-xl"><i class="fas fa-check"></i></div>
                        </div>

                        <!-- KPI 2: Alertas históricas — lotes con rendimiento por debajo del estándar -->
                        <div class="card-pro p-6 border-l-4 border-rose-500 bg-rose-50 flex justify-between items-center">
                            <div><p class="text-xs font-bold text-rose-400 uppercase mb-1">Alertas Históricas</p><h3 class="text-3xl font-extrabold text-rose-600" id="kpiAlertas"></h3></div>
                            <div class="w-12 h-12 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center text-xl"><i class="fas fa-exclamation-triangle"></i></div>
                        </div>

                        <!-- KPI 3: Total de registros (partidas) en la compra o en todo el historial -->
                        <div class="card-pro p-6 border-l-4 border-primary flex justify-between items-center">
                            <div><p class="text-xs font-bold text-slate-400 uppercase mb-1">Total de Registros</p><h3 class="text-3xl font-extrabold" id="kpiVolumen"></h3></div>
                            <div class="w-12 h-12 bg-sky-50 text-primary rounded-full flex items-center justify-center text-xl"><i class="fas fa-list"></i></div>
                        </div>
                    </div>

                    <!-- --- Formulario + Proyección + Monitor en vivo --- -->
                    <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">

                        <!-- Formulario de Consulta de Compra (col-span-5) -->
                        <div class="xl:col-span-5 card-pro p-8 border-t-4 border-slate-800 no-print h-fit">
                            <div class="flex items-center gap-3 mb-6 border-b border-slate-100 pb-4">
                                <i class="fas fa-search text-2xl text-slate-800"></i>
                                <h3 class="text-lg font-bold">Consulta de Compra</h3>
                            </div>
                            <div id="predictionForm" class="space-y-4">
                                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">1. Variables Predictivas</p>
                                <div class="grid grid-cols-2 gap-3">
                                    <!-- Campo COMPRA: casi siempre 9 caracteres (hay de 12), se convierte a mayúsculas al escribir.
                                         Al alcanzar 5 chars auto-detecta el barco (chars 2-4 = abreviatura).
                                         Al alcanzar 7+ chars dispara lookupCompra() con debounce de 300ms. -->
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 mb-1">COMPRA</label>
                                        <input type="text" id="compra" maxlength="20" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold uppercase" placeholder="Ej: TISY12455" required>
                                    </div>
                                    <!-- Campo BARCO: solo lectura, se llena automáticamente desde barcosData -->
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 mb-1">BARCO</label>
                                        <input type="text" id="barco" class="w-full p-2.5 bg-slate-100 border border-slate-200 rounded-lg text-sm font-bold text-slate-500 cursor-not-allowed" readonly placeholder="Auto-detectado">
                                    </div>
                                </div>
                                <!-- Resultado de la búsqueda: el barco se reconoce por el código aunque la compra no exista -->
                                <p id="compraStatus" class="hidden text-xs font-bold"></p>
                                <div class="grid grid-cols-2 gap-3">
                                    <!-- Selectores dinámicos cargados desde la API (leer.php).
                                         Al cambiar cualquiera se recalcula la proyección instantáneamente. -->
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 mb-1">ESPECIE</label>
                                        <select id="especie" onchange="updateProjection()" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold"></select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 mb-1">TALLA</label>
                                        <select id="talla" onchange="updateProjection()" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold"></select>
                                    </div>
                                </div>

                                <!-- Botón primario: lleva a la pantalla Trazabilidad y Futuro
                                     y aplica el código de compra como filtro automáticamente -->
                                <button type="button" id="btnTraceability" onclick="goToTraceability()" class="w-full mt-4 bg-primary hover:bg-sky-600 text-white font-bold py-3.5 rounded-xl transition-colors">
                                    <i class="fas fa-chart-line"></i> VER TRAZABILIDAD Y FUTURO
                                </button>

                                <!-- Abre el Reporte Automatizado de la compra escrita arriba -->
                                <button type="button" id="btnReport" onclick="goToReport()" class="w-full bg-slate-800 hover:bg-slate-900 text-white font-bold py-3.5 rounded-xl transition-colors">
                                    <i class="fas fa-file-alt"></i> GENERAR REPORTE AUTOMATIZADO
                                </button>

                            </div>
                        </div>

                        <!-- Panel derecho: Proyección de Recuperación (col-span-7) -->
                        <div class="xl:col-span-7 space-y-6">

                            <!-- Proyección de Recuperación:
                                 Se actualiza en tiempo real al cambiar especie/talla (updateProjection).
                                 - yieldResult:     rendimiento promedio histórico para esa especie+talla
                                 - yieldStandard:   estándar industrial de la talla seleccionada
                                 - yieldCompliance: porcentaje de cumplimiento (rendimiento / estándar × 100)
                                 Arranca con "--%" hasta que el usuario seleccione especie y talla. -->
                            <div class="card-pro p-8 bg-slate-50" id="resultPanel">
                                <p class="text-sm font-bold text-slate-500 uppercase mb-4">Proyección de Recuperación</p>
                                <div class="flex items-end gap-8 flex-wrap">
                                    <div>
                                        <h2 id="yieldResult" class="text-6xl font-black text-slate-300">--%</h2>
                                    </div>
                                    <div class="flex gap-6 pb-1">
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Estándar Talla</p>
                                            <p id="yieldStandard" class="text-6xl font-black text-slate-300">--%</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Cumplimiento</p>
                                            <p id="yieldCompliance" class="text-6xl font-black text-slate-300">--%</p>
                                        </div>
                                    </div>
                                    <!-- Alerta crítica: aparece cuando rendimiento < estándar -->
                                    <div id="alertBox" class="hidden ml-auto">
                                        <div class="bg-white border-2 border-rose-500 px-6 py-4 rounded-xl shadow-lg">
                                            <p class="font-black text-rose-600"><i class="fas fa-exclamation-triangle"></i> ALERTA CRÍTICA</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- =============================================================
                     PANTALLA: REPORTE AUTOMATIZADO
                     Contiene (en orden):
                       1. Controles: compra (opcional), periodo y botón de generar
                       2. Resumen ejecutivo de la compra seleccionada
                       3. Resumen del periodo y mejores/peores compras
                ============================================================= -->
                <div id="report-screen" class="screen-content space-y-8">

                    <div class="card-pro p-8 no-print">
                        <div class="flex flex-wrap items-end gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1">COMPRA (OPCIONAL)</label>
                                <input type="text" id="reportCompra" maxlength="20" class="p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold uppercase w-44" placeholder="Ej: TISY12455">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1">PERIODO</label>
                                <select id="reportPeriodo" class="p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold w-44">
                                    <option value="">Todo el historial</option>
                                </select>
                            </div>
                            <button type="button" id="btnGenerateReport" onclick="requestReport()" class="bg-primary hover:bg-sky-600 text-white font-bold px-6 py-2.5 rounded-lg transition-colors">
                                <i class="fas fa-magic"></i> Generar reporte automatizado
                            </button>
                        </div>
                    </div>

                    <div id="reportEmpty" class="card-pro p-12 text-center text-slate-400 font-bold">
                        Escribe una compra, o déjala vacía para ver solo el ranking, y genera el reporte.
                    </div>

                    <div id="reportContent" class="hidden space-y-8">
                        <div id="reportCompraSection"></div>
                        <div id="reportPeriodoSection"></div>
                        <p id="reportMeta" class="text-xs text-slate-400 font-bold"></p>
                    </div>
                </div>

                <!-- =============================================================
                     PANTALLA: ADMINISTRACIÓN (solo roles con permisos de supervisión)
                     Cada sección aparece solo si el rol tiene el permiso; la API
                     vuelve a comprobarlo en cada petición.
                ============================================================= -->
                <?php if ($verAdministracion): ?>
                <div id="admin-screen" class="screen-content space-y-8">

                    <?php if (in_array('ver_sincronizacion', $permisos, true)): ?>
                    <div class="card-pro p-8">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-bold text-slate-800"><i class="fas fa-sync-alt text-primary"></i> Sincronización con la empresa</h3>
                            <button type="button" id="btnReactivar" onclick="reactivarSincronizacion()" class="hidden bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded-lg text-xs font-bold">
                                <i class="fas fa-play"></i> Reactivar sincronización
                            </button>
                        </div>
                        <div id="adminSync" class="text-sm text-slate-500">Cargando…</div>
                    </div>
                    <?php endif; ?>

                    <?php if (in_array('administrar_usuarios', $permisos, true)): ?>
                    <div class="card-pro p-8 space-y-6">
                        <h3 class="text-lg font-bold text-slate-800"><i class="fas fa-users text-primary"></i> Usuarios autorizados</h3>

                        <div id="adminClave" class="hidden bg-amber-50 border border-amber-300 text-amber-800 rounded-lg px-6 py-4 text-sm"></div>

                        <form id="formUsuario" class="flex flex-wrap items-end gap-3 bg-slate-50 rounded-lg p-4" onsubmit="crearUsuario(event)">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1">USUARIO</label>
                                <input id="nuevoUsuario" type="text" required maxlength="40" class="p-2.5 bg-white border border-slate-200 rounded-lg text-sm font-bold w-40" placeholder="ej: mlopez">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1">NOMBRE</label>
                                <input id="nuevoNombre" type="text" required maxlength="100" class="p-2.5 bg-white border border-slate-200 rounded-lg text-sm font-bold w-56" placeholder="Nombre y apellido">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1">ROL</label>
                                <select id="nuevoRol" class="p-2.5 bg-white border border-slate-200 rounded-lg text-sm font-bold">
                                    <?php foreach (ROLES as $valor => $nombre): ?>
                                    <option value="<?= h($valor) ?>"><?= h($nombre) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="bg-primary hover:bg-sky-600 text-white font-bold px-5 py-2.5 rounded-lg text-sm"><i class="fas fa-user-plus"></i> Crear cuenta</button>
                        </form>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse min-w-[860px]">
                                <thead class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase">
                                    <tr>
                                        <th class="py-3 px-4">Usuario</th>
                                        <th class="py-3 px-4">Nombre</th>
                                        <th class="py-3 px-4">Rol</th>
                                        <th class="py-3 px-4">Estado</th>
                                        <th class="py-3 px-4">Último acceso</th>
                                        <th class="py-3 px-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="adminUsuarios" class="text-sm"></tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (in_array('ver_bitacora', $permisos, true)): ?>
                    <div class="card-pro p-8">
                        <h3 class="text-lg font-bold text-slate-800 mb-4"><i class="fas fa-clipboard-list text-primary"></i> Bitácora de accesos <span class="text-xs font-bold text-slate-400">(últimos 200 eventos)</span></h3>
                        <div class="overflow-x-auto h-96 border border-slate-100 rounded-lg">
                            <table class="w-full text-left border-collapse min-w-[760px]">
                                <thead class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase sticky top-0">
                                    <tr>
                                        <th class="py-3 px-4">Fecha</th>
                                        <th class="py-3 px-4">Usuario</th>
                                        <th class="py-3 px-4">Evento</th>
                                        <th class="py-3 px-4">Detalle</th>
                                        <th class="py-3 px-4">IP</th>
                                    </tr>
                                </thead>
                                <tbody id="adminBitacora" class="text-sm"></tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- =============================================================
                     PANTALLA: TRAZABILIDAD Y FUTURO
                     Contiene (en orden):
                       1. Matriz de Trazabilidad — tabla completa de registros filtrable
                       2. Rendimiento Histórico por Compra — gráfica de línea agregada
                       3. Modelo Predictivo — regresión lineal con proyección F+1 a F+5
                ============================================================= -->
                <div id="history-screen" class="screen-content space-y-8">

                    <!-- 1. Matriz de Trazabilidad -->
                    <div class="card-pro p-8">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-xl font-bold"><i class="fas fa-table text-primary"></i> Matriz de Trazabilidad</h3>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-search text-slate-400 text-sm"></i>
                                <input type="text" id="filterCompra" maxlength="20"
                                    oninput="onMatrixFilterInput(this.value)"
                                    class="p-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold uppercase w-44"
                                    placeholder="Filtrar compra...">
                                <span id="matrixCount" class="text-xs text-slate-400 font-bold whitespace-nowrap"></span>
                            </div>
                        </div>
                        <!-- Tabla con scroll vertical fijo (h-80) — encabezados pegajosos (sticky) -->
                        <div id="matrixScroll" class="overflow-x-auto h-80 border border-slate-100 rounded-lg">
                            <table class="w-full text-left border-collapse min-w-[800px]">
                                <thead class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase sticky top-0 shadow-sm z-10">
                                    <tr>
                                        <th class="py-3 px-4">Fecha</th>
                                        <th class="py-3 px-4">Compra</th>
                                        <th class="py-3 px-4">Especie / Talla</th>
                                        <th class="py-3 px-4 text-right">Rendimiento</th>
                                        <th class="py-3 px-4 text-right">Cumplimiento</th>
                                        <th class="py-3 px-4 text-center">Status</th>
                                    </tr>
                                </thead>
                                <!-- Filas generadas por bloques desde appendMatrixRows() -->

                                <tbody id="tableBody" class="text-sm"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 2. Gráfica: Rendimiento Histórico por Compra -->
                    <div class="card-pro p-8">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-bold text-slate-800"><i class="fas fa-chart-area text-primary"></i> Rendimiento Histórico por Compra</h3>
                            <!-- Badge de modo: "Histórico Completo" (gris) o "Filtrado: XXXX" (azul) -->
                            <span id="chartModeLabel" class="bg-slate-100 text-slate-500 px-3 py-1 rounded text-xs font-bold">Histórico Completo</span>
                        </div>
                        <!-- Filtros de especie y talla para la gráfica histórica -->
                        <div class="flex items-center gap-3 mb-6">
                            <div>
                                <label class="block text-[10px] font-bold text-emerald-500 uppercase mb-1">Especie</label>
                                <select id="chartEspecie"
                                    class="p-2 bg-white border border-emerald-200 rounded-lg text-sm font-bold text-slate-600">
                                    <option value="">Todas</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-emerald-500 uppercase mb-1">Talla</label>
                                <select id="chartTalla"
                                    class="p-2 bg-white border border-emerald-200 rounded-lg text-sm font-bold text-slate-600">
                                    <option value="">Todas</option>
                                </select>
                            </div>
                        </div>
                        <div id="chartNoData" class="hidden flex items-center justify-center py-16 text-center">
                            <div>
                                <i class="fas fa-database text-slate-300 text-4xl mb-3"></i>
                                <p class="text-slate-500 font-bold text-sm">No existe una compra para esta especie y talla en la Base de datos.</p>
                            </div>
                        </div>
                        <div id="chartContainer" class="chart-container"><canvas id="traceabilityChart"></canvas></div>
                    </div>

                    <!-- 3. Trazabilidad Temporal: rendimiento de un barco en un período específico -->
                    <div class="card-pro p-8 border-t-4 border-amber-400 bg-gradient-to-br from-white to-amber-50/20">
                        <div class="flex justify-between items-center mb-2">
                            <h3 class="text-lg font-bold text-slate-800">
                                <i class="fas fa-calendar-alt text-amber-500"></i> Trazabilidad Temporal
                            </h3>
                            <span id="temporalLabel" class="bg-slate-100 text-slate-500 px-3 py-1 rounded text-xs font-bold">Sin filtro aplicado</span>
                        </div>
                        <p class="text-sm text-slate-500 mb-5 font-medium">Selecciona un barco, año y mes para visualizar el rendimiento de sus compras en ese período específico.</p>

                        <!-- Filtros: Barco + Año + Mes -->
                        <div class="flex flex-wrap items-end gap-3 mb-6">
                            <div>
                                <label class="block text-[10px] font-bold text-amber-600 uppercase mb-1">Barco</label>
                                <select id="temporalBarco" class="p-2 bg-white border border-amber-200 rounded-lg text-sm font-bold text-slate-600 min-w-[180px]">
                                    <option value="">Seleccionar barco...</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-amber-600 uppercase mb-1">Año</label>
                                <select id="temporalAnio" class="p-2 bg-white border border-amber-200 rounded-lg text-sm font-bold text-slate-600">
                                    <option value="">Año</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-amber-600 uppercase mb-1">Mes</label>
                                <select id="temporalMes" class="p-2 bg-white border border-amber-200 rounded-lg text-sm font-bold text-slate-600">
                                    <option value="">Todos los meses</option>
                                    <option value="1">Enero</option>
                                    <option value="2">Febrero</option>
                                    <option value="3">Marzo</option>
                                    <option value="4">Abril</option>
                                    <option value="5">Mayo</option>
                                    <option value="6">Junio</option>
                                    <option value="7">Julio</option>
                                    <option value="8">Agosto</option>
                                    <option value="9">Septiembre</option>
                                    <option value="10">Octubre</option>
                                    <option value="11">Noviembre</option>
                                    <option value="12">Diciembre</option>
                                </select>
                            </div>
                        </div>

                        <!-- Mensaje sin datos -->
                        <div id="temporalNoData" class="hidden flex items-center justify-center py-16 text-center">
                            <div>
                                <i class="fas fa-calendar-times text-slate-300 text-4xl mb-3"></i>
                                <p class="text-slate-500 font-bold text-sm">No hay registros para el barco y período seleccionado.</p>
                            </div>
                        </div>

                        <!-- Gráfica temporal -->
                        <div id="temporalContainer" class="hidden chart-container">
                            <canvas id="temporalChart"></canvas>
                        </div>
                    </div>

                    <!-- 4. Modelo Predictivo (Regresión Lineal por Mínimos Cuadrados) -->
                    <div class="card-pro p-8 border-t-4 border-indigo-500 bg-gradient-to-br from-white to-indigo-50/30">
                        <div class="flex justify-between items-center mb-2">
                            <h3 class="text-xl font-black text-indigo-900"><i class="fas fa-brain text-indigo-500"></i> Modelo predictivo</h3>
                            <!-- El botón cambia de apariencia tras ejecutar el modelo (azul → oscuro)
                                 y su texto cambia a "Recalcular Proyección" -->
                            <button id="btnRunForecast" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-6 rounded-lg transition-colors shadow-md flex items-center gap-2">
                                <i class="fas fa-magic"></i> Ejecutar Modelo Predictivo
                            </button>
                        </div>
                        <p class="text-sm text-slate-600 mb-4 font-medium max-w-3xl">Al ejecutar el algoritmo, el sistema analiza matemáticamente la tendencia histórica del rendimiento utilizando regresión lineal por mínimos cuadrados y proyecta el comportamiento esperado para los próximos 5 lotes de producción.</p>

                        <!-- Filtros opcionales: acotan los datos del modelo a una especie, talla y/o barco específico -->
                        <div class="flex items-center gap-3 mb-6">
                            <div>
                                <label class="block text-[10px] font-bold text-indigo-400 uppercase mb-1">Especie</label>
                                <select id="forecastEspecie"
                                    class="p-2 bg-white border border-indigo-200 rounded-lg text-sm font-bold text-slate-600">
                                    <option value="">Todas</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-indigo-400 uppercase mb-1">Talla</label>
                                <select id="forecastTalla"
                                    class="p-2 bg-white border border-indigo-200 rounded-lg text-sm font-bold text-slate-600">
                                    <option value="">Todas</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-indigo-400 uppercase mb-1">Barco</label>
                                <select id="forecastBarco"
                                    class="p-2 bg-white border border-indigo-200 rounded-lg text-sm font-bold text-slate-600">
                                    <option value="">Todos</option>
                                </select>
                            </div>
                        </div>

                        <!-- Mensaje cuando los filtros no arrojan datos suficientes -->
                        <div id="forecastNoData" class="hidden text-center text-rose-500 font-bold py-8">
                            <i class="fas fa-exclamation-circle mr-2"></i>Sin datos suficientes para proyectar con los filtros seleccionados.
                        </div>

                        <!-- Contenedor de la gráfica de forecast — oculto hasta ejecutar el modelo -->
                        <div id="forecastWrapper" class="hidden">
                            <div class="chart-container border border-indigo-100 rounded-xl bg-white p-4" style="height: 400px;">
                                <canvas id="forecastingChart"></canvas>
                            </div>
                        </div>

                        <!-- Spinner de carga simulado (1.2s) para dar sensación de procesamiento -->
                        <div id="forecastLoader" class="hidden flex flex-col items-center justify-center py-12">
                            <div class="w-12 h-12 border-4 border-slate-200 rounded-full loader-ai mb-4"></div>
                            <p class="text-sm font-bold text-indigo-800">Procesando vectores históricos y calculando proyecciones...</p>
                        </div>
                    </div>

                </div>
            </div>
        </main>
    </div>

    <script>
        // =====================================================================
        // ESTADO GLOBAL
        // =====================================================================

        // Historial completo de predicciones cargado desde la BD (todos los registros)
        let globalHistoryData = [];

        // Conteo de registros por talla — usado para calcular el rendimiento ponderado estándar
        let globalTallaCounts = [];

        // Catálogo de barcos — usado para auto-detectar el barco desde el código de compra
        let barcosData = [];

        // Instancias de Chart.js — se destruyen y recrean al actualizar datos
        let traceabilityChart = null;
        let forecastingChart  = null;

        // Mapa de estándares por talla_id: { 1: 41, 2: 41.5, 3: 43, ... }
        // Se construye dinámicamente desde la BD al cargar catálogos.
        // El valor es el porcentaje (ej. 44.0), no decimal (0.44).
        let STANDARDS = {};

        // Registros de la compra actualmente buscada (null = sin compra activa).
        // Cuando hay una compra buscada, updateProjection() filtra por talla dentro
        // de estos registros en lugar de usar el historial global.
        let compraHistoryData = null;

        // Pantalla activa y origen del aviso visible, para que "Reintentar" repita lo que falló
        let activeScreen   = 'dashboard';
        let appErrorOrigin = null;

        // =====================================================================
        // FUNCIÓN: showAppError / hideAppError / retryLoad
        // Aviso visible cuando una carga falla, para que nadie decida con una
        // pantalla vacía o desactualizada creyendo que está bien.
        //
        // @param origin  'datos' (leer.php) o 'compra' (buscar.php)
        // =====================================================================
        function showAppError(message, err, origin) {
            console.error(message, err);
            appErrorOrigin = origin;
            document.getElementById('appErrorText').innerText = message;
            document.getElementById('appError').classList.remove('hidden');
        }

        // Quita el aviso solo si lo generó este mismo origen
        function hideAppError(origin) {
            if (appErrorOrigin !== origin) return;
            appErrorOrigin = null;
            document.getElementById('appError').classList.add('hidden');
        }

        function retryLoad() {
            const origin = appErrorOrigin;
            hideAppError(origin);
            if (origin === 'compra') {
                lookupCompra(document.getElementById('compra').value.trim().toUpperCase());
            } else {
                loadScreen(activeScreen);
            }
        }

        // =====================================================================
        // FUNCIÓN: fetchJson
        // Consulta la API y lanza un error si la respuesta no es válida: código
        // HTTP de fallo, JSON incompleto o status distinto de 'success'.
        // =====================================================================
        async function fetchJson(url, opciones = {}) {
            const res = await fetch(url, opciones);
            // Sesión vencida o contraseña temporal: la página de ingreso o de cambio decide qué sigue
            if (res.status === 401) { location.href = 'login.php?expirada=1'; throw new Error('Sesión terminada'); }
            if (res.status === 403) {
                const r = await res.json().catch(() => ({}));
                if (r.codigo === 'cambio_clave') { location.href = 'cambiar-clave.php'; }
                throw new Error(r.message || `${url}: HTTP 403`);
            }
            const result = await res.json().catch(() => null);
            if (!res.ok || !result) throw new Error(result?.message || `${url}: HTTP ${res.status}`);
            if (result.status !== 'success') throw new Error(`${url}: ${result.message}`);
            return result;
        }

        // Datos de la sesión que entrega el servidor: nombre, rol, permisos y token CSRF
        const APP_SESION = <?= json_encode($sesionCliente, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

        function tienePermiso(permiso) {
            return APP_SESION.permisos.includes(permiso);
        }

        // Envía una acción a la API con el token CSRF en la cabecera
        function postJson(url, datos) {
            return fetchJson(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': APP_SESION.csrf },
                body: JSON.stringify(datos)
            });
        }

        // =====================================================================
        // FUNCIÓN: fetchAppData
        // Devuelve catálogos e historial. Las pantallas comparten la misma respuesta
        // durante APP_DATA_TTL_MS para no descargar todo el historial en cada cambio.
        // =====================================================================
        const APP_DATA_TTL_MS = 60000;
        let appDataPromise = null;
        let appDataTime    = 0;

        function fetchAppData() {
            if (appDataPromise && Date.now() - appDataTime < APP_DATA_TTL_MS) return appDataPromise;
            appDataTime    = Date.now();
            appDataPromise = fetchJson('api/leer.php').catch(err => {
                appDataPromise = null; // un fallo no se guarda: el siguiente intento vuelve a consultar
                throw err;
            });
            return appDataPromise;
        }

        // =====================================================================
        // FUNCIÓN: calcWeightedStandard
        // Calcula el estándar de rendimiento ponderado por distribución de tallas.
        //
        // Lógica: cada talla tiene un estándar distinto. Si el 60% de los registros
        // son talla 10-20 (estándar 47%) y el 40% son talla 20-30 (estándar 47%),
        // el ponderado = (47 × 0.6) + (47 × 0.4) = 47%.
        // Esto da el "estándar esperado real" considerando la mezcla de tallas reales.
        //
        // @param tallaCounts  Array de { talla_id, cantidad } desde la API
        // @returns  string con 1 decimal (ej. "44.0") o null si no hay datos
        // =====================================================================
        function calcWeightedStandard(tallaCounts) {
            const total = tallaCounts.reduce((sum, t) => sum + parseInt(t.cantidad), 0);
            if (total === 0) return null;
            let weighted = 0;
            tallaCounts.forEach(t => {
                // Usa STANDARDS[id] o 44 como fallback si la talla no tiene estándar cargado
                const std = STANDARDS[parseInt(t.talla_id)] ?? 44;
                weighted += std * (parseInt(t.cantidad) / total);
            });
            return weighted.toFixed(1);
        }

        // =====================================================================
        // FUNCIÓN: loadScreen
        // Cambia la pantalla activa y actualiza el estado visual del sidebar.
        // Dispara la carga de datos específica de cada pantalla.
        // =====================================================================
        function loadScreen(screenId) {
            activeScreen = screenId;
            document.body.classList.toggle('report-active', screenId === 'report');

            // Oculta todas las pantallas y desactiva todos los ítems del sidebar
            document.querySelectorAll('.screen-content').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));

            // Activa la pantalla solicitada
            document.getElementById(`${screenId}-screen`).classList.add('active');

            // Marca el ítem del sidebar y actualiza el título del header
            const activeItem = Array.from(document.querySelectorAll('.sidebar-item'))
                .find(i => i.getAttribute('onclick').includes(screenId));
            if (activeItem) {
                activeItem.classList.add('active');
                document.getElementById('screenTitle').innerText = activeItem.innerText.trim();
            }

            // Carga datos según la pantalla
            if (screenId === 'dashboard') loadCatalogs();
            if (screenId === 'history')   fetchFullHistory();
            if (screenId === 'report')    loadReportScreen();
            if (screenId === 'admin')     loadAdminScreen();
        }

        // =====================================================================
        // FUNCIÓN: loadCatalogs
        // Carga catálogos (especies, tallas, barcos) y el historial completo.
        // Llena los selectores del formulario, construye el mapa STANDARDS,
        // y construye el mapa de estándares por talla.
        // =====================================================================
        async function loadCatalogs() {
            try {
                const result = await fetchAppData();
                hideAppError('datos');

                // Llena los <select> con los catálogos
                populateSelect('especie', result.data.species, 'nombre');
                populateSelect('talla',   result.data.tallas,  'rango');
                barcosData    = result.data.barcos;

                // Construye el mapa STANDARDS: { talla_id: porcentaje }
                // estandar en BD está en decimal (0.44), se convierte a porcentaje (44.0)
                STANDARDS = {};
                result.data.tallas.forEach(t => {
                    STANDARDS[t.id] = parseFloat((t.estandar * 100).toFixed(2));
                });

                // Guarda el historial y conteos globalmente para otras funciones
                globalHistoryData = result.data.history;
                globalTallaCounts = result.data.tallaCounts ?? [];


                // Sincroniza la Proyección de Recuperación con los selectores actuales.
                // Necesario porque populateSelect resetea los selects a la primera opción,
                // y updateProjection() depende de STANDARDS y globalHistoryData que
                // se acaban de construir en este mismo bloque.
                updateProjection();
            } catch (err) {
                showAppError('No se pudieron cargar los datos. Lo que ves puede estar vacío o desactualizado.', err, 'datos');
            }
        }

        // =====================================================================
        // FUNCIÓN: populateSelect
        // Llena un elemento <select> con opciones desde un array de objetos.
        //
        // Usa createDocumentFragment en lugar de innerHTML += para evitar que
        // el navegador reconstruya el DOM del select en cada iteración, lo cual
        // podría disparar el evento 'change' prematuramente con STANDARDS vacío.
        //
        // @param selectId   ID del elemento <select>
        // @param data       Array de objetos del catálogo
        // @param labelField Nombre de la propiedad a mostrar como texto de la opción
        // =====================================================================
        function populateSelect(selectId, data, labelField = 'nombre') {
            const select   = document.getElementById(selectId);
            const fragment = document.createDocumentFragment();
            data.forEach(item => {
                const opt = document.createElement('option');
                opt.value       = item.id;
                opt.textContent = item[labelField];
                fragment.appendChild(opt);
            });
            // Reemplaza todas las opciones en una sola operación de DOM
            select.innerHTML = '';
            select.appendChild(fragment);
        }

        // Timer para el debounce del input de compra — evita requests en cada tecla
        let compraSearchTimer = null;

        // Muestra bajo el campo si la compra existe en esta base; sin texto lo oculta
        function setCompraStatus(text, found) {
            const el = document.getElementById('compraStatus');
            el.innerText = text;
            el.className = !text ? 'hidden' : `text-xs font-bold ${found ? 'text-emerald-600' : 'text-rose-600'}`;
        }

        // =====================================================================
        // EVENTO: input en campo "compra"
        // Al escribir el código de compra:
        //   1. Fuerza mayúsculas
        //   2. Con 5+ chars detecta el barco (chars 2-4 = abreviatura, ej. "SY1")
        //   3. Con 7+ chars dispara lookupCompra() tras 300ms de inactividad
        //   4. Al borrar, restaura los KPIs globales
        //   5. Siempre recalcula la proyección de recuperación
        // =====================================================================
        document.getElementById('compra').addEventListener('input', function () {
            const val   = this.value.toUpperCase();
            this.value  = val;
            const barcoInput = document.getElementById('barco');

            if (val.length >= 5) {
                // Extrae la abreviatura del barco desde la posición 2 (índice 0-based)
                // Ej: "TISY12455" → abrev = "SY1"
                const abrev = val.substring(2, 5);
                const barco = barcosData.find(b => b.abreviatura === abrev);
                barcoInput.value = barco ? barco.nombre : 'Barco no reconocido';
                // Verde si se reconoce el barco, rojo si no
                barcoInput.className = barco
                    ? 'w-full p-2.5 bg-emerald-50 border border-emerald-200 rounded-lg text-sm font-bold text-emerald-700 cursor-not-allowed'
                    : 'w-full p-2.5 bg-rose-50 border border-rose-200 rounded-lg text-sm font-bold text-rose-500 cursor-not-allowed';
            } else {
                barcoInput.value     = '';
                barcoInput.className = 'w-full p-2.5 bg-slate-100 border border-slate-200 rounded-lg text-sm font-bold text-slate-500 cursor-not-allowed';
            }

            // Debounce: espera 300ms sin escribir antes de consultar la API
            clearTimeout(compraSearchTimer);
            setCompraStatus('');
            if (val.length >= 7) {
                compraSearchTimer = setTimeout(() => lookupCompra(val), 300);
            } else {
                // Al borrar el código, los KPIs vuelven a mostrar totales globales
                showGlobalKPIs();
            }

            // Proyección se recalcula instantáneamente con cada cambio
            updateProjection();
        });

        // La proyección se actualiza via onchange="updateProjection()" en el HTML de los selects

        // =====================================================================
        // FUNCIÓN: goToTraceability
        // Navega a la pantalla Trazabilidad y Futuro y aplica el código de compra
        // del formulario como filtro inicial en la matriz (si hay uno ingresado).
        // El timeout de 300ms da tiempo a que renderMatrixRows() termine de
        // generar las filas antes de aplicar el filtro.
        // =====================================================================
        function goToTraceability() {
            const compra = document.getElementById('compra').value.trim().toUpperCase();
            loadScreen('history');
            if (compra) {
                setTimeout(() => {
                    const filterInput = document.getElementById('filterCompra');
                    filterInput.value = compra;
                    filterMatrix(compra);
                }, 300);
            }
        }

        // =====================================================================
        // FUNCIÓN: updateProjection
        // Recalcula y muestra en tiempo real la proyección de recuperación.
        //
        // Dos modos según si hay compra activa (compraHistoryData !== null):
        //
        //   CON compra activa:
        //     Filtra los registros de ESA compra por la talla seleccionada.
        //     Si la compra no tiene registros para esa talla, usa el estándar como base.
        //     Esto da coherencia con los KPIs que también muestran datos de la compra.
        //
        //   SIN compra activa:
        //     Muestra placeholders "--%" igual que las tarjetas de KPIs.
        // =====================================================================
        function updateProjection() {
            // Sin compra activa: muestra placeholders igual que las tarjetas de KPIs
            if (compraHistoryData === null) {
                document.getElementById('yieldResult').innerText    = '--%';
                document.getElementById('yieldResult').className    = 'text-6xl font-black text-slate-300';
                document.getElementById('yieldStandard').innerText  = '--%';
                document.getElementById('yieldStandard').className  = 'text-6xl font-black text-slate-300';
                document.getElementById('yieldCompliance').innerText = '--%';
                document.getElementById('yieldCompliance').className = 'text-6xl font-black text-slate-300';
                document.getElementById('alertBox').classList.add('hidden');
                return;
            }

            const especieId = parseInt(document.getElementById('especie').value);
            const tallaId   = parseInt(document.getElementById('talla').value);
            const standard  = STANDARDS[tallaId] ?? 44; // porcentaje (ej. 44.0)

            // Filtra los registros de la compra activa por especie + talla seleccionadas
            const matching = compraHistoryData.filter(r =>
                parseInt(r.especie_id) === especieId && parseInt(r.talla_id) === tallaId
            );

            const estandarDecimal = standard / 100;
            // Si no hay registros para la combinación, usa el estándar como estimación
            const rendimiento = matching.length > 0
                ? matching.reduce((s, r) => s + parseFloat(r.rendimiento_esperado), 0) / matching.length
                : estandarDecimal;

            const percent    = (rendimiento * 100).toFixed(2);
            const compliance = ((rendimiento / estandarDecimal) * 100).toFixed(1);
            const isAlert    = rendimiento < estandarDecimal;

            document.getElementById('yieldResult').innerText   = percent + '%';
            document.getElementById('yieldResult').className   = isAlert ? 'text-6xl font-black text-rose-600' : 'text-6xl font-black text-emerald-500';
            document.getElementById('yieldStandard').innerText  = standard + '%';
            document.getElementById('yieldStandard').className  = 'text-6xl font-black text-slate-500';
            document.getElementById('yieldCompliance').innerText = compliance + '%';
            document.getElementById('yieldCompliance').className = parseFloat(compliance) >= 100 ? 'text-6xl font-black text-emerald-500' : 'text-6xl font-black text-rose-500';
            document.getElementById('alertBox').classList.toggle('hidden', !isAlert);
        }

        // =====================================================================
        // FUNCIÓN: lookupCompra
        // Consulta la API buscar.php para obtener los registros de una compra
        // específica y actualiza los KPIs con datos contextuales de esa compra.
        //
        // KPIs que actualiza:
        //   - kpiRendimiento: promedio de rendimiento de todas las partidas de la compra
        //   - kpiPonderado:   estándar ponderado por distribución de tallas de esa compra
        //   - kpiAlertas:     cantidad de partidas con alerta en esa compra
        //   - kpiVolumen:     total de partidas de la compra
        // =====================================================================
        async function lookupCompra(compra) {
            try {
                const result = await fetchJson(`api/buscar.php?compra=${encodeURIComponent(compra)}`);
                hideAppError('compra');

                if (result.total > 0) {
                    const rows   = result.data;

                    // Guarda los registros de la compra para que updateProjection()
                    // los use al filtrar por talla (en lugar del historial global)
                    compraHistoryData = rows;

                    const sumY   = rows.reduce((s, r) => s + parseFloat(r.rendimiento_esperado), 0);
                    const avg    = ((sumY / rows.length) * 100).toFixed(1);
                    const alerts = rows.filter(r => r.alerta_status == 1).length;

                    // Agrupa por talla para calcular el ponderado estándar de esta compra
                    const tallaMap = {};
                    rows.forEach(r => {
                        const tid = r.talla_rango;
                        if (!tallaMap[tid]) tallaMap[tid] = { count: 0, estandar: parseFloat(r.estandar) };
                        tallaMap[tid].count++;
                    });
                    const totalR = rows.length;
                    const pond   = Object.values(tallaMap).reduce((s, t) => s + (t.estandar * 100 * t.count / totalR), 0).toFixed(1);

                    document.getElementById('kpiRendimiento').innerText = avg + '%';
                    document.getElementById('kpiPonderado').innerText   = pond + '%';
                    document.getElementById('kpiAlertas').innerText     = alerts;
                    document.getElementById('kpiVolumen').innerText     = totalR;
                    setCompraStatus(`${totalR} ${totalR === 1 ? 'partida encontrada' : 'partidas encontradas'} en esta base de datos.`, true);

                    // Actualiza la proyección con contexto de compra (usa compraHistoryData)
                    updateProjection();
                } else {
                    // Compra no encontrada: limpia contexto y muestra datos globales
                    compraHistoryData = null;
                    showGlobalKPIs();
                    updateProjection();
                    setCompraStatus('Esta compra no existe en esta base de datos. Los indicadores muestran los totales globales.', false);
                }
            } catch (err) {
                compraHistoryData = null;
                showGlobalKPIs();
                updateProjection();
                showAppError(`No se pudo consultar la compra ${compra}. Los indicadores muestran los totales globales.`, err, 'compra');
            }
        }

        // =====================================================================
        // FUNCIÓN: showGlobalKPIs
        // Muestra los KPIs calculados sobre todo el historial (no filtrado por compra).
        // Se llama cuando se borra el campo de compra o no se encuentra la compra buscada.
        // =====================================================================
        function showGlobalKPIs() {
            // Al mostrar KPIs globales, también limpia el contexto de compra
            // para que updateProjection() vuelva al modo historial global
            compraHistoryData = null;

            const hist = globalHistoryData;
            if (hist.length === 0) return;
            const sumY   = hist.reduce((s, r) => s + parseFloat(r.rendimiento_esperado), 0);
            const alerts = hist.filter(r => r.alerta_status == 1).length;
            document.getElementById('kpiRendimiento').innerText = ((sumY / hist.length) * 100).toFixed(1) + '%';
            document.getElementById('kpiAlertas').innerText     = alerts;
            document.getElementById('kpiVolumen').innerText     = hist.length;

            const ponderado = calcWeightedStandard(globalTallaCounts);
            document.getElementById('kpiPonderado').innerText = ponderado !== null ? ponderado + '%' : '--%';
        }


        // =====================================================================
        // FUNCIÓN: aggregateByCompra
        // Agrupa el historial por código de compra para reducir la cantidad de
        // puntos en las gráficas. Con 1,200+ registros individuales, la gráfica
        // se satura; al agrupar se obtienen ~100-200 puntos únicos.
        //
        // Resultado: un punto por compra con el rendimiento promedio de todas
        // sus partidas, ordenado cronológicamente por fecha del primer registro.
        //
        // @param historyData  Array de registros del historial
        // @returns Array de { compra, rendimiento, count } ordenado por fecha
        // =====================================================================
        function aggregateByCompra(historyData) {
            const map = {};
            historyData.forEach(row => {
                const key = row.compra;
                if (!map[key]) map[key] = { compra: key, fecha: row.fecha_registro, sum: 0, estandarSum: 0, count: 0 };
                map[key].sum        += parseFloat(row.rendimiento_esperado);
                map[key].estandarSum += parseFloat(row.estandar);
                map[key].count++;
            });
            return Object.values(map)
                .sort((a, b) => new Date(a.fecha) - new Date(b.fecha))
                .map(d => ({ compra: d.compra, rendimiento: d.sum / d.count, estandar: d.estandarSum / d.count, count: d.count }));
        }

        // =====================================================================
        // FUNCIÓN: initTraceabilityChart
        // Renderiza la gráfica "Rendimiento Histórico por Compra".
        //
        // Dos modos:
        //   showRaw = false (defecto): agrega por compra — vista general del historial
        //   showRaw = true: muestra cada partida individual — usado al filtrar por compra
        //
        // El eje Y se ajusta dinámicamente al rango real de los datos (±2 puntos)
        // para maximizar el detalle visual sin espacio desperdiciado.
        // El tooltip muestra "Partidas: N" en modo agregado.
        // =====================================================================
        function initTraceabilityChart(historyData, showRaw = false) {
            const ctx = document.getElementById('traceabilityChart').getContext('2d');

            let labels, yields, standards, counts;
            if (showRaw) {
                // Vista detallada: etiqueta = especie + rango de talla de cada partida
                labels    = historyData.map(d => d.especie_nombre + ' ' + d.talla_rango);
                yields    = historyData.map(d => parseFloat((d.rendimiento_esperado * 100).toFixed(2)));
                standards = historyData.map(d => parseFloat((d.estandar * 100).toFixed(2)));
                counts    = null;
            } else {
                // Vista agregada: un punto por compra con el promedio de sus partidas
                const aggregated = aggregateByCompra(historyData);
                labels    = aggregated.map(d => d.compra);
                yields    = aggregated.map(d => parseFloat((d.rendimiento * 100).toFixed(2)));
                standards = aggregated.map(d => parseFloat((d.estandar * 100).toFixed(2)));
                counts    = aggregated.map(d => d.count);
            }

            // Destruye la instancia anterior para evitar memory leaks y canvas reutilizado
            if (traceabilityChart) { traceabilityChart.destroy(); }

            // Rango del eje Y calculado sobre ambas líneas para que ninguna quede cortada
            const allValues = [...yields, ...standards];

            traceabilityChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Rendimiento promedio (%)',
                            data: yields,
                            borderColor: '#10B981',
                            backgroundColor: 'rgba(16,185,129,0.08)',
                            borderWidth: 2,
                            tension: 0.3,
                            pointRadius: 3,
                            pointBackgroundColor: '#10B981',
                            fill: true
                        },
                        {
                            // Estándar dinámico: varía según la talla de cada punto
                            label: 'Estándar',
                            data: standards,
                            borderColor: '#F43F5E',
                            backgroundColor: 'rgba(244,63,94,0.07)',
                            borderWidth: 2,
                            tension: 0.3,
                            pointRadius: 3,
                            pointBackgroundColor: '#F43F5E',
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                // En modo agregado muestra "Partidas: N" solo en el dataset de rendimiento
                                afterLabel: counts
                                    ? (ctx) => ctx.datasetIndex === 0 ? `Partidas: ${counts[ctx.dataIndex]}` : null
                                    : undefined
                            }
                        }
                    },
                    scales: {
                        // Eje Y dinámico ajustado al rango real de ambas líneas ±2
                        y: { min: Math.floor(Math.min(...allValues) - 2), max: Math.ceil(Math.max(...allValues) + 2), title: { display: true, text: 'Rendimiento (%)' } },
                        x: { ticks: { maxRotation: 45, minRotation: 45, font: { size: 9 } } }
                    }
                }
            });
        }

        // =====================================================================
        // EVENTO: clic en "Ejecutar Modelo Predictivo"
        // Muestra el spinner 1.2s (simulación de procesamiento) y luego genera
        // la gráfica de forecast con los datos del filtro activo o el historial completo.
        // Tras ejecutar, el botón cambia a "Recalcular Proyección".
        // =====================================================================
        document.getElementById('btnRunForecast').addEventListener('click', function () {
            const btn     = this;
            const loader  = document.getElementById('forecastLoader');
            const wrapper = document.getElementById('forecastWrapper');

            btn.classList.add('hidden');
            wrapper.classList.add('hidden');
            loader.classList.remove('hidden');

            setTimeout(() => {
                loader.classList.add('hidden');
                wrapper.classList.remove('hidden');
                runForecastWithFilters();
                btn.classList.remove('hidden');
                btn.innerHTML = '<i class="fas fa-sync-alt"></i> Recalcular Proyección';
                btn.classList.replace('bg-indigo-600', 'bg-slate-800');
                btn.classList.replace('hover:bg-indigo-700', 'hover:bg-slate-900');
            }, 1200);
        });

        // =====================================================================
        // FUNCIÓN: generateForecastChart
        // Aplica regresión lineal por mínimos cuadrados al historial y proyecta
        // los rendimientos F+1 a F+5 (los 5 próximos lotes).
        //
        // Algoritmo (mínimos cuadrados):
        //   m = (n·ΣXY - ΣX·ΣY) / (n·ΣX² - (ΣX)²)
        //   b = (ΣY - m·ΣX) / n
        //   Y_pred = m·X + b
        //
        // Manejo de casos especiales:
        //   - Si hay ≥2 compras únicas: usa datos agregados por compra (un punto por compra)
        //   - Si hay solo 1 compra: usa cada partida individual como punto de datos
        //     (caso de compra filtrada con pocas entradas)
        //
        // La gráfica muestra 3 datasets:
        //   1. Línea oscura: rendimiento histórico real
        //   2. Línea azul punteada: línea de tendencia ajustada (regresión)
        //   3. Línea morada: pronóstico F+1 a F+5 con área sombreada
        //
        // Una línea vertical gris separa el historial del pronóstico.
        // =====================================================================
        function generateForecastChart(historyData, filterLabel = '') {
            const ctx        = document.getElementById('forecastingChart').getContext('2d');
            const aggregated = aggregateByCompra(historyData);

            let xValues = [], yValues = [], labels = [];

            if (aggregated.length >= 2) {
                // Caso normal: usa promedios por compra como puntos de regresión
                aggregated.forEach((d, i) => {
                    xValues.push(i);
                    yValues.push(parseFloat((d.rendimiento * 100).toFixed(2)));
                    labels.push(d.compra);
                });
            } else {
                // Caso filtrado (1 compra): usa cada partida individual
                historyData.forEach((row, i) => {
                    xValues.push(i);
                    yValues.push(parseFloat((row.rendimiento_esperado * 100).toFixed(2)));
                    labels.push(row.especie_nombre + ' ' + row.talla_rango);
                });
            }

            const n = yValues.length;
            if (n < 2) return; // Necesita al menos 2 puntos para trazar una línea

            // Cálculo de mínimos cuadrados
            let sumX = 0, sumY = 0, sumXY = 0, sumXX = 0;
            for (let i = 0; i < n; i++) {
                sumX += xValues[i]; sumY += yValues[i];
                sumXY += xValues[i] * yValues[i]; sumXX += xValues[i] * xValues[i];
            }
            const m = (n * sumXY - sumX * sumY) / (n * sumXX - sumX * sumX); // pendiente
            const b = (sumY - m * sumX) / n;                                   // intersección Y

            // Línea de tendencia sobre los datos históricos
            const trendLine    = xValues.map(x => parseFloat((m * x + b).toFixed(2)));

            // Proyección futura: empieza con null hasta el último punto histórico
            // para que la línea morada arranque exactamente donde termina la histórica
            const futureLabels = [...labels];
            const futureTrend  = Array(n).fill(null);
            futureTrend[n - 1] = trendLine[n - 1]; // ancla visual de la proyección

            // Extiende 5 puntos hacia el futuro (F+1 a F+5)
            for (let i = 1; i <= 5; i++) {
                futureLabels.push(`Compra F+${i}`);
                trendLine.push(null);
                futureTrend.push(parseFloat(((m * ((n - 1) + i)) + b).toFixed(2)));
                yValues.push(null); // relleno para mantener longitud de arrays igual
            }

            // Eje Y dinámico que incluye tanto histórico como proyectado
            const allValues = [...yValues.filter(v => v !== null), ...futureTrend.filter(v => v !== null)];
            const yMin = Math.floor(Math.min(...allValues) - 2);
            const yMax = Math.ceil(Math.max(...allValues) + 2);

            if (forecastingChart) forecastingChart.destroy();

            forecastingChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: futureLabels,
                    datasets: [
                        // Dataset 1: puntos históricos reales (gris oscuro)
                        { label: 'Rendimiento Histórico Real', data: yValues, borderColor: '#334155', backgroundColor: '#334155', borderWidth: 2, tension: 0.3, pointRadius: 4 },
                        // Dataset 2: línea de tendencia ajustada (azul punteado)
                        { label: 'Tendencia (Ajuste)', data: trendLine, borderColor: '#0EA5E9', borderWidth: 2, borderDash: [5, 5], pointRadius: 0 },
                        // Dataset 3: proyección futura (morado, área sombreada)
                        {
                            label: 'Pronóstico (Futuro)', data: futureTrend, borderColor: '#8B5CF6', backgroundColor: 'rgba(139,92,246,0.15)',
                            borderWidth: 4, fill: true, tension: 0.1, pointRadius: 6, pointBackgroundColor: '#8B5CF6',
                            datalabels: { display: true }
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: { mode: 'index', intersect: false },
                        // Muestra el filtro activo como título del gráfico
                        title: {
                            display: !!filterLabel,
                            text: `Filtro activo: ${filterLabel}`,
                            color: '#6366F1',
                            font: { size: 12, weight: 'bold' },
                            padding: { bottom: 8 }
                        },
                        annotation: {
                            annotations: {
                                lineAlerta: { type: 'line', yMin: 44, yMax: 44, borderColor: '#F43F5E', borderWidth: 2, borderDash: [3, 3] },
                                lineCorte:  { type: 'line', scaleID: 'x', value: labels[n - 1], borderColor: '#94A3B8', borderWidth: 2 }
                            }
                        }
                    },
                    scales: {
                        y: { title: { display: true, text: 'Rendimiento (%)' }, min: yMin, max: yMax },
                        x: { ticks: { maxRotation: 45, minRotation: 45, font: { size: 10 } } }
                    }
                }
            });
        }


        // =====================================================================
        // FUNCIÓN: fetchFullHistory
        // Carga y renderiza la pantalla Trazabilidad y Futuro completa.
        // Se llama al navegar a esa pantalla.
        //
        // Acciones:
        //   1. Obtiene todo el historial de la API
        //   2. Renderiza las filas de la Matriz en orden inverso (más reciente primero)
        //   3. Actualiza los KPIs con totales globales
        //   4. Inicializa la gráfica histórica respetando el filtro activo
        // =====================================================================
        async function fetchFullHistory() {
            try {
                const result = await fetchAppData();
                hideAppError('datos');

                if (result.data.history.length === 0) {
                    renderMatrixRows([]);
                } else {
                    globalHistoryData = result.data.history;

                    // La tabla muestra del más reciente al más antiguo (reverse)
                    // El historial original está ordenado ASC por fecha
                    renderMatrixRows([...result.data.history].reverse());

                    // Actualiza los KPIs globales mientras se visualiza esta pantalla
                    const hist = result.data.history;
                    const sumY   = hist.reduce((s, r) => s + parseFloat(r.rendimiento_esperado), 0);
                    const alerts = hist.filter(r => r.alerta_status == 1).length;
                    document.getElementById('kpiRendimiento').innerText = ((sumY / hist.length) * 100).toFixed(1) + '%';
                    document.getElementById('kpiAlertas').innerText     = alerts;
                    document.getElementById('kpiVolumen').innerText     = hist.length;

                    const ponderado = calcWeightedStandard(result.data.tallaCounts ?? []);
                    document.getElementById('kpiPonderado').innerText = ponderado !== null ? ponderado + '%' : '--%';

                    // Rellena los selects de especie, talla e historial temporal
                    populateFilters(globalHistoryData);
                    populateTemporalFilters(globalHistoryData);

                    // Vista inicial: historial agregado por compra, o el filtro que ya esté escrito
                    applyChartFilters();
                }
            } catch (err) {
                showAppError('No se pudo cargar el historial. Lo que ves puede estar vacío o desactualizado.', err, 'datos');
            }
        }

        // =====================================================================
        // FUNCIÓN: renderMatrixRows / refreshMatrix / appendMatrixRows
        // Genera las filas <tr> de la Matriz de Trazabilidad por bloques: la página
        // solo contiene las filas que se han visto, y al bajar con el scroll se
        // agregan más. El filtro trabaja sobre los datos, no sobre la página.
        //
        // Campos mostrados:
        //   - Fecha (solo la parte de fecha, sin hora)
        //   - Código de compra
        //   - Especie + rango de talla
        //   - Rendimiento en % (rojo si alerta, verde si óptimo)
        //   - Cumplimiento: rendimiento / estándar de la talla × 100
        //   - Badge de status: "Alerta" (rojo) u "Óptimo" (verde)
        // =====================================================================
        const MATRIX_PAGE_SIZE = 100;
        let matrixRows     = []; // todas las filas, de la más reciente a la más antigua
        let matrixFiltered = []; // las que cumplen el filtro activo
        let matrixShown    = 0;  // cuántas de matrixFiltered ya están en la página

        function renderMatrixRows(rows) {
            matrixRows = rows;
            refreshMatrix(document.getElementById('filterCompra').value);
        }

        function refreshMatrix(text) {
            const filter = text.trim().toUpperCase();
            matrixFiltered = filter ? matrixRows.filter(r => r.compra.includes(filter)) : matrixRows;
            matrixShown = 0;
            document.getElementById('matrixScroll').scrollTop = 0;

            const tableBody = document.getElementById('tableBody');
            tableBody.innerHTML = '';
            if (matrixFiltered.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="6" class="py-8 text-center text-slate-400 font-bold">Sin registros para mostrar</td></tr>';
            } else {
                appendMatrixRows(MATRIX_PAGE_SIZE);
            }
            updateMatrixCount();
        }

        function appendMatrixRows(count) {
            const fragment = document.createDocumentFragment();
            matrixFiltered.slice(matrixShown, matrixShown + count).forEach(row => {
                const rend   = (row.rendimiento_esperado * 100).toFixed(1);
                // estandar puede ser null si la talla no tiene estándar definido
                const cumpl  = row.estandar
                    ? ((row.rendimiento_esperado / row.estandar) * 100).toFixed(1)
                    : '--';
                const isAlert = row.alerta_status == 1;
                const tr = document.createElement('tr');
                tr.className = "border-b border-slate-100 hover:bg-slate-50";
                tr.innerHTML = `
                    <td class="py-2 px-4 text-slate-500 font-medium">${escapeHtml(row.fecha_registro.split(' ')[0])}</td>
                    <td class="py-2 px-4 font-bold text-slate-800">${escapeHtml(row.compra)}</td>
                    <td class="py-2 px-4 font-medium text-slate-700">
                        ${escapeHtml(row.especie_nombre)}
                        <span class="ml-1 text-xs font-bold text-slate-400">${escapeHtml(row.talla_rango)}</span>
                    </td>
                    <td class="py-2 px-4 text-right font-black" style="color:${isAlert ? '#E11D48' : '#10B981'}">${rend}%</td>
                    <td class="py-2 px-4 text-right text-slate-500 font-bold">${cumpl}%</td>
                    <td class="py-2 px-4 text-center">
                        <span class="px-2 py-1 rounded text-[10px] font-bold uppercase ${isAlert ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600'}">
                            ${isAlert ? 'Alerta' : 'Óptimo'}
                        </span>
                    </td>
                `;
                fragment.appendChild(tr);
            });
            document.getElementById('tableBody').appendChild(fragment);
            matrixShown = Math.min(matrixShown + count, matrixFiltered.length);
        }

        // Agrega el siguiente bloque al acercarse al final de la tabla
        document.getElementById('matrixScroll').addEventListener('scroll', function () {
            const nearEnd = this.scrollTop + this.clientHeight >= this.scrollHeight - 200;
            if (nearEnd && matrixShown < matrixFiltered.length) appendMatrixRows(MATRIX_PAGE_SIZE);
        });

        // Al imprimir, la tabla sale completa aunque no se haya recorrido con el scroll
        window.addEventListener('beforeprint', () => {
            if (activeScreen !== 'report') appendMatrixRows(matrixFiltered.length - matrixShown);
        });

        // =====================================================================
        // FUNCIÓN: populateFilters
        // Rellena los <select> de Especie y Talla del Modelo Predictivo y de la
        // gráfica histórica con los valores únicos presentes en el historial.
        // Tallas ordenadas numéricamente.
        // =====================================================================
        function populateFilters(data) {
            const especies = [...new Set(data.map(r => r.especie_nombre))].sort();
            const tallas   = [...new Set(data.map(r => r.talla_rango))].sort((a, b) => {
                const lower = t => t.startsWith('-') ? parseFloat(t) : parseFloat(t.split('-')[0]);
                return lower(a) - lower(b);
            });

            // Rellenar selects del Modelo Predictivo
            const forecastEspecieSelect = document.getElementById('forecastEspecie');
            const forecastTallaSelect   = document.getElementById('forecastTalla');
            const forecastBarcoSelect   = document.getElementById('forecastBarco');
            forecastEspecieSelect.innerHTML = '<option value="">Todas</option>';
            especies.forEach(e => {
                const o = document.createElement('option'); o.value = e; o.textContent = e;
                forecastEspecieSelect.appendChild(o);
            });
            forecastTallaSelect.innerHTML = '<option value="">Todas</option>';
            tallas.forEach(t => {
                const o = document.createElement('option'); o.value = t; o.textContent = t;
                forecastTallaSelect.appendChild(o);
            });
            forecastBarcoSelect.innerHTML = '<option value="">Todos</option>';
            barcosData.forEach(b => {
                const o = document.createElement('option'); o.value = b.abreviatura; o.textContent = b.nombre;
                forecastBarcoSelect.appendChild(o);
            });

            // Rellenar selects de la gráfica Rendimiento Histórico
            const chartEspecieSelect = document.getElementById('chartEspecie');
            const chartTallaSelect   = document.getElementById('chartTalla');
            chartEspecieSelect.innerHTML = '<option value="">Todas</option>';
            especies.forEach(e => {
                const o = document.createElement('option'); o.value = e; o.textContent = e;
                chartEspecieSelect.appendChild(o);
            });
            chartTallaSelect.innerHTML = '<option value="">Todas</option>';
            tallas.forEach(t => {
                const o = document.createElement('option'); o.value = t; o.textContent = t;
                chartTallaSelect.appendChild(o);
            });
        }

        // =====================================================================
        // FUNCIÓN: applyChartFilters
        // Combina el filtro de compra (texto) con los selects de especie y talla
        // de la gráfica histórica y vuelve a renderizar initTraceabilityChart.
        // =====================================================================
        function applyChartFilters() {
            const compraFilter  = document.getElementById('filterCompra').value.trim().toUpperCase();
            const especieFilter = document.getElementById('chartEspecie').value;
            const tallaFilter   = document.getElementById('chartTalla').value;

            let filtered = globalHistoryData;
            if (compraFilter)  filtered = filtered.filter(r => r.compra.includes(compraFilter));
            if (especieFilter) filtered = filtered.filter(r => r.especie_nombre === especieFilter);
            if (tallaFilter)   filtered = filtered.filter(r => r.talla_rango    === tallaFilter);

            const label      = document.getElementById('chartModeLabel');
            const noData     = document.getElementById('chartNoData');
            const container  = document.getElementById('chartContainer');
            const hasAnyFilter = compraFilter || especieFilter || tallaFilter;

            if (filtered.length === 0) {
                noData.classList.remove('hidden');
                container.classList.add('hidden');
                label.innerText = hasAnyFilter
                    ? `Filtrado: ${[compraFilter, especieFilter, tallaFilter].filter(Boolean).join(' · ')}`
                    : 'Histórico Completo';
                label.className = hasAnyFilter
                    ? 'bg-primary text-white px-3 py-1 rounded text-xs font-bold'
                    : 'bg-slate-100 text-slate-500 px-3 py-1 rounded text-xs font-bold';
                return;
            }

            noData.classList.add('hidden');
            container.classList.remove('hidden');

            if (hasAnyFilter) {
                const parts = [compraFilter, especieFilter, tallaFilter].filter(Boolean);
                label.innerText = `Filtrado: ${parts.join(' · ')}`;
                label.className = 'bg-primary text-white px-3 py-1 rounded text-xs font-bold';
                initTraceabilityChart(filtered, !!compraFilter);
            } else {
                label.innerText = 'Histórico Completo';
                label.className = 'bg-slate-100 text-slate-500 px-3 py-1 rounded text-xs font-bold';
                initTraceabilityChart(globalHistoryData, false);
            }
        }

        // =====================================================================
        // FUNCIÓN: filterMatrix
        // Filtra las filas de la Matriz por código de compra en tiempo real.
        // =====================================================================
        let matrixFilterTimer = null;

        // Espera a que se deje de escribir: cada filtrado también redibuja la gráfica
        function onMatrixFilterInput(text) {
            clearTimeout(matrixFilterTimer);
            matrixFilterTimer = setTimeout(() => filterMatrix(text), 200);
        }

        function filterMatrix(text) {
            refreshMatrix(text);
            applyChartFilters();

            document.getElementById('forecastWrapper').classList.add('hidden');
            document.getElementById('forecastNoData').classList.add('hidden');
            const btn = document.getElementById('btnRunForecast');
            btn.classList.remove('hidden');
            btn.innerHTML = '<i class="fas fa-magic"></i> Ejecutar Modelo Predictivo';
            btn.classList.replace('bg-slate-800', 'bg-indigo-600');
            btn.classList.replace('hover:bg-slate-900', 'hover:bg-indigo-700');
        }

        // =====================================================================
        // FUNCIÓN: updateMatrixCount
        // Actualiza el contador de registros de la matriz.
        // Muestra "N registros" si no hay filtro, o "X / N" si hay filtro activo.
        // =====================================================================
        function updateMatrixCount() {
            const total   = matrixRows.length;
            const visible = matrixFiltered.length;
            const el = document.getElementById('matrixCount');
            if (el) el.innerText = visible === total ? `${total} registros` : `${visible} / ${total}`;
        }

        // =====================================================================
        // FUNCIÓN: runForecastWithFilters
        // Lee los selectores de Especie, Talla y Barco del Modelo Predictivo,
        // filtra el historial y ejecuta generateForecastChart.
        // Compartida entre el botón y el auto-recalculo al cambiar selects.
        // =====================================================================
        function runForecastWithFilters() {
            const fEspecie = document.getElementById('forecastEspecie').value;
            const fTalla   = document.getElementById('forecastTalla').value;
            const fBarco   = document.getElementById('forecastBarco').value; // abreviatura del barco

            const forecastData = globalHistoryData.filter(r =>
                (!fEspecie || r.especie_nombre === fEspecie)
             && (!fTalla   || r.talla_rango    === fTalla)
             && (!fBarco   || r.compra.substring(2, 5) === fBarco)
            );

            const barcoNombre = fBarco ? (barcosData.find(b => b.abreviatura === fBarco)?.nombre ?? fBarco) : '';
            const filterLabel = [fEspecie, fTalla, barcoNombre].filter(Boolean).join(' · ');
            const wrapper = document.getElementById('forecastWrapper');
            const noData  = document.getElementById('forecastNoData');

            if (forecastData.length < 2) {
                noData.classList.remove('hidden');
                wrapper.classList.add('hidden');
            } else {
                noData.classList.add('hidden');
                wrapper.classList.remove('hidden');
                generateForecastChart(forecastData, filterLabel);
            }
        }

        // Auto-recalcula el modelo al cambiar especie, talla o barco, solo si ya fue ejecutado
        ['forecastEspecie', 'forecastTalla', 'forecastBarco'].forEach(id => {
            document.getElementById(id).addEventListener('change', () => {
                const wrapper = document.getElementById('forecastWrapper');
                const noData  = document.getElementById('forecastNoData');
                const alreadyRun = !wrapper.classList.contains('hidden')
                                || !noData.classList.contains('hidden');
                if (alreadyRun) runForecastWithFilters();
            });
        });

        // Actualiza la gráfica histórica al cambiar especie o talla de ese filtro
        ['chartEspecie', 'chartTalla'].forEach(id => {
            document.getElementById(id).addEventListener('change', applyChartFilters);
        });

        // =====================================================================
        // TRAZABILIDAD TEMPORAL
        // =====================================================================

        // Rellena el selector de barcos y años de la Trazabilidad Temporal
        function populateTemporalFilters(data) {
            // Barcos presentes en el historial (extraídos del código de compra)
            const abrevSet = new Set(data.map(r => r.compra.substring(2, 5)));
            const barcoSelect = document.getElementById('temporalBarco');
            barcoSelect.innerHTML = '<option value="">Seleccionar barco...</option>';
            barcosData
                .filter(b => abrevSet.has(b.abreviatura))
                .sort((a, b) => a.nombre.localeCompare(b.nombre))
                .forEach(b => {
                    const o = document.createElement('option');
                    o.value = b.abreviatura;
                    o.textContent = b.nombre;
                    barcoSelect.appendChild(o);
                });

            // Años disponibles en el historial
            const years = [...new Set(data.map(r => new Date(r.fecha_registro).getFullYear()))].sort();
            const anioSelect = document.getElementById('temporalAnio');
            anioSelect.innerHTML = '<option value="">Año</option>';
            years.forEach(y => {
                const o = document.createElement('option'); o.value = y; o.textContent = y;
                anioSelect.appendChild(o);
            });
        }

        // Aplica los filtros de la Trazabilidad Temporal y renderiza la gráfica
        function applyTemporalFilters() {
            const abrev = document.getElementById('temporalBarco').value;
            const anio  = document.getElementById('temporalAnio').value;
            const mes   = document.getElementById('temporalMes').value;

            if (!abrev || !anio) {
                document.getElementById('temporalNoData').classList.add('hidden');
                document.getElementById('temporalContainer').classList.add('hidden');
                document.getElementById('temporalLabel').innerText = 'Sin filtro aplicado';
                document.getElementById('temporalLabel').className = 'bg-slate-100 text-slate-500 px-3 py-1 rounded text-xs font-bold';
                return;
            }

            let filtered = globalHistoryData.filter(r => r.compra.substring(2, 5) === abrev);
            filtered = filtered.filter(r => new Date(r.fecha_registro).getFullYear() === parseInt(anio));
            if (mes) filtered = filtered.filter(r => new Date(r.fecha_registro).getMonth() + 1 === parseInt(mes));

            const barcoNombre = barcosData.find(b => b.abreviatura === abrev)?.nombre ?? abrev;
            const MESES = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
            const labelParts = [barcoNombre, anio, mes ? MESES[parseInt(mes)] : null].filter(Boolean);
            const labelText = labelParts.join(' · ');

            const noData    = document.getElementById('temporalNoData');
            const container = document.getElementById('temporalContainer');
            const badge     = document.getElementById('temporalLabel');

            badge.innerText   = labelText;
            badge.className   = 'bg-amber-500 text-white px-3 py-1 rounded text-xs font-bold';

            if (filtered.length === 0) {
                noData.classList.remove('hidden');
                container.classList.add('hidden');
                return;
            }

            noData.classList.add('hidden');
            container.classList.remove('hidden');
            initTemporalChart(filtered, labelText);
        }

        // Renderiza la gráfica de la Trazabilidad Temporal
        function initTemporalChart(data, labelText) {
            // Agregar por compra: rendimiento promedio + estándar promedio
            const map = {};
            data.forEach(r => {
                const k = r.compra;
                if (!map[k]) map[k] = { compra: k, fecha: r.fecha_registro, sum: 0, estandarSum: 0, count: 0 };
                map[k].sum        += parseFloat(r.rendimiento_esperado);
                map[k].estandarSum += parseFloat(r.estandar);
                map[k].count++;
            });
            const aggregated = Object.values(map)
                .sort((a, b) => new Date(a.fecha) - new Date(b.fecha));

            const labels    = aggregated.map(d => d.compra);
            const yields    = aggregated.map(d => parseFloat((d.sum / d.count * 100).toFixed(2)));
            const standards = aggregated.map(d => parseFloat((d.estandarSum / d.count * 100).toFixed(2)));
            const allValues = [...yields, ...standards];
            const minY = Math.max(0, Math.min(...allValues) - 2);
            const maxY = Math.max(...allValues) + 2;

            const ctx = document.getElementById('temporalChart').getContext('2d');
            if (window._temporalChart instanceof Chart) window._temporalChart.destroy();

            window._temporalChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Rendimiento promedio (%)',
                            data: yields,
                            borderColor: '#10B981',
                            backgroundColor: 'rgba(16,185,129,0.08)',
                            borderWidth: 2,
                            tension: 0.3,
                            pointRadius: 4,
                            pointBackgroundColor: '#10B981',
                            fill: true
                        },
                        {
                            label: 'Estándar',
                            data: standards,
                            borderColor: '#F43F5E',
                            backgroundColor: 'rgba(244,63,94,0.07)',
                            borderWidth: 2,
                            tension: 0.3,
                            pointRadius: 4,
                            pointBackgroundColor: '#F43F5E',
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: labelText,
                            color: '#D97706',
                            font: { size: 12, weight: 'bold' },
                            padding: { bottom: 8 }
                        },
                        legend: { position: 'top', labels: { font: { size: 11 } } },
                        tooltip: {
                            callbacks: {
                                label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y}%`
                            }
                        }
                    },
                    scales: {
                        x: { ticks: { font: { size: 10 }, maxRotation: 45 }, grid: { display: false } },
                        y: {
                            min: minY, max: maxY,
                            title: { display: true, text: 'Rendimiento (%)', font: { size: 11 } },
                            ticks: { callback: v => v + '%' }
                        }
                    }
                }
            });
        }

        // Listeners de la Trazabilidad Temporal
        ['temporalBarco', 'temporalAnio', 'temporalMes'].forEach(id => {
            document.getElementById(id).addEventListener('change', applyTemporalFilters);
        });

        // =====================================================================
        // REPORTE AUTOMATIZADO
        // Todo se calcula con reglas fijas sobre el historial: el mismo dato siempre
        // da el mismo resultado y se puede auditar.
        //
        // Criterio: cumplimiento = rendimiento promedio / estándar promedio × 100,
        // donde cada partida pesa lo mismo. No se pondera por kilos porque casi
        // ninguna partida tiene peso registrado.
        // =====================================================================
        const REPORT_MIN_PARTIDAS = 5; // menos partidas no compiten en el ranking
        const REPORT_TOP          = 5;

        // true desde el primer clic en generar: al volver a la pantalla se recalcula
        let reportRequested = false;

        function escapeHtml(value) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            return String(value ?? '').replace(/[&<>"']/g, c => map[c]);
        }

        // Solo se puede comparar una partida con rendimiento y con estándar definido
        function reportValidRows(history) {
            return history.filter(r => Number.isFinite(parseFloat(r.rendimiento_esperado)) && parseFloat(r.estandar) > 0);
        }

        function summarizeRows(rows) {
            let sumRend = 0, sumStd = 0, alertas = 0;
            rows.forEach(r => {
                sumRend += parseFloat(r.rendimiento_esperado);
                sumStd  += parseFloat(r.estandar);
                if (r.alerta_status == 1) alertas++;
            });
            const n = rows.length;
            return {
                partidas: n,
                rendimiento: sumRend / n * 100,
                estandar: sumStd / n * 100,
                cumplimiento: sumRend / sumStd * 100,
                diferencia: (sumRend - sumStd) / n * 100, // puntos porcentuales sobre el estándar
                alertas
            };
        }

        function groupRows(rows, keyOf) {
            const groups = new Map();
            rows.forEach(r => {
                const key = keyOf(r);
                if (!groups.has(key)) groups.set(key, []);
                groups.get(key).push(r);
            });
            return groups;
        }

        function barcoDeCompra(compra, barcos) {
            const abrev = compra.substring(2, 5);
            return barcos.find(b => b.abreviatura === abrev)?.nombre ?? 'Barco no reconocido';
        }

        // Una fila por compra, de mayor a menor cumplimiento
        function buildCompraSummaries(rows, barcos) {
            const list = [];
            groupRows(rows, r => r.compra).forEach((items, compra) => {
                const fechas = items.map(r => r.fecha_registro).sort();
                list.push({
                    compra,
                    barco: barcoDeCompra(compra, barcos),
                    desde: fechas[0].split(' ')[0],
                    hasta: fechas[fechas.length - 1].split(' ')[0],
                    ...summarizeRows(items)
                });
            });
            return list.sort((a, b) => b.cumplimiento - a.cumplimiento || a.compra.localeCompare(b.compra));
        }

        // "1.3 puntos por debajo de", "0.4 puntos arriba" o el texto de igualdad, según el signo
        function describeDiff(puntos, arriba, abajo, igual) {
            const valor = parseFloat(puntos.toFixed(1));
            if (valor === 0) return igual;
            return `${Math.abs(valor).toFixed(1)} puntos ${valor > 0 ? arriba : abajo}`;
        }

        function statusBadge(s) {
            return s.cumplimiento >= 100
                ? '<span class="px-2 py-1 rounded text-[10px] font-bold uppercase bg-emerald-100 text-emerald-600">Cumple</span>'
                : '<span class="px-2 py-1 rounded text-[10px] font-bold uppercase bg-rose-100 text-rose-600">Bajo estándar</span>';
        }

        function reportKpi(label, value) {
            return `<div class="bg-slate-50 rounded-lg p-4">
                        <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">${label}</p>
                        <p class="text-2xl font-extrabold text-slate-800">${value}</p>
                    </div>`;
        }

        // Resumen ejecutivo: conclusión en texto, indicadores y detalle por especie/talla
        function renderCompraReport(compra, valid, barcos) {
            const section = document.getElementById('reportCompraSection');
            const rows = valid.filter(r => r.compra === compra);
            if (rows.length === 0) {
                section.innerHTML = `<div class="card-pro p-8 text-center font-bold text-rose-500">No se encontró la compra ${escapeHtml(compra)} en el historial.</div>`;
                return;
            }

            const s = buildCompraSummaries(rows, barcos)[0];
            const groups = [];
            groupRows(rows, r => `${r.especie_nombre} ${r.talla_rango}`).forEach((items, nombre) => {
                groups.push({ nombre, ...summarizeRows(items) });
            });
            // Lo que más pesó sobre el resultado: diferencia con el estándar × partidas
            groups.sort((a, b) => a.diferencia * a.partidas - b.diferencia * b.partidas);
            const peor  = groups[0];
            const mejor = groups[groups.length - 1];

            const sentences = [
                `Rindió ${s.rendimiento.toFixed(1)}%, ${describeDiff(s.diferencia, 'por encima de', 'por debajo de', 'igual a')} su estándar de ${s.estandar.toFixed(1)}% (cumplimiento de ${s.cumplimiento.toFixed(1)}%).`,
                `${s.alertas} de ${s.partidas} ${s.partidas === 1 ? 'partida quedó' : 'partidas quedaron'} en alerta.`
            ];

            // El lugar en el ranking es información comparativa: solo para roles con ese permiso
            const ranking = buildCompraSummaries(valid, barcos).filter(c => c.partidas >= REPORT_MIN_PARTIDAS);
            const lugar   = ranking.findIndex(c => c.compra === compra);
            if (lugar < 0) {
                sentences.push(`Tiene menos de ${REPORT_MIN_PARTIDAS} partidas: su resultado debe leerse con cautela.`);
            } else if (tienePermiso('ver_ranking')) {
                sentences.push(`Ocupa el lugar ${lugar + 1} de ${ranking.length} compras comparables del historial.`);
            }

            const abrev  = compra.substring(2, 5);
            const otras  = valid.filter(r => r.compra !== compra);
            const barco  = otras.filter(r => r.compra.substring(2, 5) === abrev);
            if (barco.length > 0) {
                const diff = s.cumplimiento - summarizeRows(barco).cumplimiento;
                sentences.push(`Frente a las demás compras de ${s.barco}, su cumplimiento está ${describeDiff(diff, 'arriba', 'abajo', 'igual')}.`);
            }
            if (peor.diferencia < 0) sentences.push(`Lo que más la bajó: ${peor.nombre} (cumplimiento de ${peor.cumplimiento.toFixed(1)}%).`);
            if (mejor.diferencia > 0) sentences.push(`Lo que más la subió: ${mejor.nombre} (cumplimiento de ${mejor.cumplimiento.toFixed(1)}%).`);

            const diffGlobal = otras.length > 0 ? s.cumplimiento - summarizeRows(otras).cumplimiento : null;
            const fechas = s.desde === s.hasta ? s.desde : `${s.desde} a ${s.hasta}`;

            section.innerHTML = `
                <div class="card-pro p-8 space-y-6">
                    <div class="flex flex-wrap justify-between items-start gap-4 border-b border-slate-100 pb-4">
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase mb-1">Resumen ejecutivo de la compra</p>
                            <h3 id="reportTitulo" class="text-2xl font-extrabold text-slate-900">${escapeHtml(s.compra)}</h3>
                            <p class="text-sm font-bold text-slate-500">${escapeHtml(s.barco)} · ${fechas}</p>
                        </div>
                        ${statusBadge(s)}
                    </div>
                    <p id="reportConclusion" class="text-base text-slate-700 leading-relaxed">${sentences.map(escapeHtml).join(' ')}</p>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                        ${reportKpi('Rendimiento', s.rendimiento.toFixed(1) + '%')}
                        ${reportKpi('Estándar', s.estandar.toFixed(1) + '%')}
                        ${reportKpi('Cumplimiento', s.cumplimiento.toFixed(1) + '%')}
                        ${reportKpi('Alertas', `${s.alertas} / ${s.partidas}`)}
                        ${reportKpi('Frente al resto', diffGlobal === null ? '--' : `${diffGlobal >= 0 ? '+' : ''}${diffGlobal.toFixed(1)} pts`)}
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[600px]">
                            <thead class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase">
                                <tr>
                                    <th class="py-3 px-4">Especie / Talla</th>
                                    <th class="py-3 px-4 text-right">Partidas</th>
                                    <th class="py-3 px-4 text-right">Rendimiento</th>
                                    <th class="py-3 px-4 text-right">Estándar</th>
                                    <th class="py-3 px-4 text-right">Cumplimiento</th>
                                    <th class="py-3 px-4 text-right">Alertas</th>
                                </tr>
                            </thead>
                            <tbody id="reportDetalle" class="text-sm">
                                ${groups.map(g => `
                                <tr class="border-b border-slate-100">
                                    <td class="py-2 px-4 font-bold text-slate-700">${escapeHtml(g.nombre)}</td>
                                    <td class="py-2 px-4 text-right">${g.partidas}</td>
                                    <td class="py-2 px-4 text-right">${g.rendimiento.toFixed(1)}%</td>
                                    <td class="py-2 px-4 text-right">${g.estandar.toFixed(1)}%</td>
                                    <td class="py-2 px-4 text-right font-black" style="color:${g.cumplimiento >= 100 ? '#10B981' : '#E11D48'}">${g.cumplimiento.toFixed(1)}%</td>
                                    <td class="py-2 px-4 text-right">${g.alertas}</td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>`;
        }

        function rankingTable(id, title, icon, list) {
            return `
                <div class="card-pro p-8">
                    <h3 class="text-lg font-bold text-slate-800 mb-4"><i class="fas ${icon}"></i> ${title}</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[700px]">
                            <thead class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase">
                                <tr>
                                    <th class="py-3 px-4">Compra</th>
                                    <th class="py-3 px-4">Barco</th>
                                    <th class="py-3 px-4">Fecha</th>
                                    <th class="py-3 px-4 text-right">Partidas</th>
                                    <th class="py-3 px-4 text-right">Rendimiento</th>
                                    <th class="py-3 px-4 text-right">Estándar</th>
                                    <th class="py-3 px-4 text-right">Cumplimiento</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody id="${id}" class="text-sm">
                                ${list.map(c => `
                                <tr class="border-b border-slate-100">
                                    <td class="py-2 px-4 font-bold"><a href="#" class="text-primary hover:underline" data-report-compra="${escapeHtml(c.compra)}">${escapeHtml(c.compra)}</a></td>
                                    <td class="py-2 px-4 text-slate-600">${escapeHtml(c.barco)}</td>
                                    <td class="py-2 px-4 text-slate-500">${c.desde}</td>
                                    <td class="py-2 px-4 text-right">${c.partidas}</td>
                                    <td class="py-2 px-4 text-right">${c.rendimiento.toFixed(1)}%</td>
                                    <td class="py-2 px-4 text-right">${c.estandar.toFixed(1)}%</td>
                                    <td class="py-2 px-4 text-right font-black" style="color:${c.cumplimiento >= 100 ? '#10B981' : '#E11D48'}">${c.cumplimiento.toFixed(1)}%</td>
                                    <td class="py-2 px-4 text-center">${statusBadge(c)}</td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>`;
        }

        // Resumen del periodo y mejores/peores compras, sin repetir ninguna entre las dos listas
        function renderPeriodoReport(periodo, valid, barcos) {
            const section = document.getElementById('reportPeriodoSection');
            const rows = periodo ? valid.filter(r => r.fecha_registro.startsWith(periodo)) : valid;
            const label = periodo || 'todo el historial';
            if (rows.length === 0) {
                section.innerHTML = `<div class="card-pro p-8 text-center font-bold text-slate-400">No hay partidas en ${escapeHtml(label)}.</div>`;
                return;
            }

            const compras  = buildCompraSummaries(rows, barcos);
            const elegibles = compras.filter(c => c.partidas >= REPORT_MIN_PARTIDAS);
            const total    = summarizeRows(rows);
            const bajo     = compras.filter(c => c.cumplimiento < 100).length;
            const n        = Math.min(REPORT_TOP, Math.floor(elegibles.length / 2));

            // Mejores y peores compras: solo para roles con permiso de ver el ranking
            const verRanking = tienePermiso('ver_ranking');
            let rankings = !verRanking ? '' : `<div class="card-pro p-8 text-center font-bold text-slate-400">No hay suficientes compras con ${REPORT_MIN_PARTIDAS} partidas o más para comparar.</div>`;
            if (verRanking && n > 0) {
                rankings = rankingTable('reportMejores', 'Compras con mejor cumplimiento', 'fa-arrow-up text-emerald-500', elegibles.slice(0, n))
                         + rankingTable('reportPeores', 'Compras a revisar', 'fa-arrow-down text-rose-500', elegibles.slice(-n).reverse());
            }

            section.innerHTML = `
                <div class="space-y-8">
                    <div class="card-pro p-8">
                        <p class="text-xs font-bold text-slate-400 uppercase mb-4">Resumen de ${escapeHtml(label)}</p>
                        <div id="reportPeriodoKpis" class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            ${reportKpi('Compras', compras.length)}
                            ${reportKpi('Partidas', total.partidas)}
                            ${reportKpi('Cumplimiento', total.cumplimiento.toFixed(1) + '%')}
                            ${reportKpi('Compras bajo estándar', `${bajo} / ${compras.length}`)}
                        </div>
                        ${!verRanking ? '' : `<p class="text-xs text-slate-400 font-bold mt-4">
                            El ranking compara ${elegibles.length} compras con ${REPORT_MIN_PARTIDAS} partidas o más; ${compras.length - elegibles.length} quedan fuera por tener muy pocas.
                        </p>`}
                    </div>
                    ${rankings}
                </div>`;
        }

        // Llena el selector de periodo con los años del historial, conservando la selección
        function populateReportYears(history) {
            const select = document.getElementById('reportPeriodo');
            const actual = select.value;
            const years  = [...new Set(history.map(r => r.fecha_registro.slice(0, 4)))].sort().reverse();
            select.innerHTML = '<option value="">Todo el historial</option>';
            years.forEach(y => {
                const o = document.createElement('option'); o.value = y; o.textContent = y;
                select.appendChild(o);
            });
            select.value = years.includes(actual) ? actual : '';
        }

        // =====================================================================
        // FUNCIÓN: generateReport
        // Lee los controles y dibuja el reporte con los datos ya cargados.
        // =====================================================================
        function generateReport(result) {
            const history = result.data.history;
            const barcos  = result.data.barcos;
            const valid   = reportValidRows(history);
            const input   = document.getElementById('reportCompra');
            const compra  = input.value.trim().toUpperCase();
            input.value   = compra;

            if (compra) {
                renderCompraReport(compra, valid, barcos);
            } else {
                document.getElementById('reportCompraSection').innerHTML = '';
            }
            renderPeriodoReport(document.getElementById('reportPeriodo').value, valid, barcos);

            const omitidas = history.length - valid.length;
            document.getElementById('reportMeta').innerText =
                `Generado el ${new Date().toLocaleString('es-GT')} con ${valid.length} partidas. `
                + 'Cumplimiento calculado por partida, sin ponderar por kilos.'
                + (omitidas === 1 ? ' 1 partida sin estándar quedó fuera.' : '')
                + (omitidas > 1 ? ` ${omitidas} partidas sin estándar quedaron fuera.` : '');

            document.getElementById('reportEmpty').classList.add('hidden');
            document.getElementById('reportContent').classList.remove('hidden');
        }

        async function loadReportScreen() {
            try {
                const result = await fetchAppData();
                hideAppError('datos');
                populateReportYears(result.data.history);
                if (reportRequested) generateReport(result);
            } catch (err) {
                showAppError('No se pudieron cargar los datos del reporte. Lo que ves puede estar vacío o desactualizado.', err, 'datos');
            }
        }

        function requestReport() {
            reportRequested = true;
            loadReportScreen();
        }

        // Botón del Dashboard: abre el reporte de la compra que esté escrita
        function goToReport() {
            document.getElementById('reportCompra').value = document.getElementById('compra').value;
            reportRequested = true;
            loadScreen('report');
        }

        // Clic en una compra de los rankings: abre su resumen ejecutivo
        document.getElementById('reportContent').addEventListener('click', e => {
            const link = e.target.closest('[data-report-compra]');
            if (!link) return;
            e.preventDefault();
            document.getElementById('reportCompra').value = link.dataset.reportCompra;
            requestReport();
            document.querySelector('main').scrollTop = 0;
        });

        // =====================================================================
        // ADMINISTRACIÓN
        // Cada sección existe en la página solo si el rol tiene el permiso, y la
        // API lo vuelve a comprobar: ocultar un botón no es lo que protege.
        // =====================================================================
        const EVENTOS = {
            login_ok: 'Ingreso', login_fallido: 'Ingreso fallido', login_bloqueado: 'Intento con cuenta bloqueada',
            cuenta_bloqueada: 'Cuenta bloqueada', ip_bloqueada: 'IP bloqueada', captcha_fallido: 'Captcha incorrecto',
            logout: 'Cierre de sesión', sesion_expirada: 'Sesión expirada', sesion_revocada: 'Sesión revocada',
            cambio_clave: 'Cambio de contraseña', cambio_clave_fallido: 'Cambio de contraseña fallido',
            usuario_creado: 'Cuenta creada', usuario_editado: 'Cuenta editada', usuario_activado: 'Cuenta activada',
            usuario_desactivado: 'Cuenta desactivada', clave_restablecida: 'Contraseña restablecida',
            acceso_denegado: 'Acceso denegado', sync_reactivada: 'Sincronización reactivada'
        };

        function loadAdminScreen() {
            if (document.getElementById('adminSync'))     loadAdminSync();
            if (document.getElementById('adminUsuarios')) loadAdminUsuarios();
            if (document.getElementById('adminBitacora')) loadAdminBitacora();
        }

        function adminError(err) {
            showAppError(`No se pudo completar la acción: ${err.message}`, err, 'admin');
        }

        async function loadAdminSync() {
            const caja = document.getElementById('adminSync');
            const btn  = document.getElementById('btnReactivar');
            try {
                const r = await fetchJson('api/sincronizacion.php');
                if (!r.disponible) {
                    caja.innerHTML = 'Esta instancia todavía no recibe datos de la sincronización.';
                    btn.classList.add('hidden');
                    return;
                }
                const detenido = r.estado.some(e => e.detenido == 1);
                btn.classList.toggle('hidden', !(detenido && r.puedeReactivar));
                const estado = r.estado.map(e => `
                    <p class="font-bold ${e.detenido == 1 ? 'text-rose-600' : 'text-emerald-600'}">
                        Modo ${escapeHtml(e.modo)}: ${e.detenido == 1 ? 'DETENIDA · ' + escapeHtml(e.motivo) : 'activa'}
                        <span class="text-slate-400 font-medium">· copiado hasta ${escapeHtml(e.ultima_fecha ?? '-')}</span>
                    </p>`).join('');
                const filas = r.ejecuciones.map(e => `
                    <tr class="border-b border-slate-100">
                        <td class="py-2 px-4">${escapeHtml(e.inicio)}</td>
                        <td class="py-2 px-4 font-bold ${e.estado === 'ok' ? 'text-emerald-600' : 'text-rose-600'}">${escapeHtml(e.estado)}</td>
                        <td class="py-2 px-4 text-right">${escapeHtml(String(e.leidas ?? 0))}</td>
                        <td class="py-2 px-4 text-right">${escapeHtml(String(e.insertadas ?? 0))}</td>
                        <td class="py-2 px-4 text-right">${escapeHtml(String(e.actualizadas ?? 0))}</td>
                        <td class="py-2 px-4 text-right">${escapeHtml(String(e.segundos ?? '-'))}</td>
                        <td class="py-2 px-4 text-slate-500">${escapeHtml(e.mensaje ?? '')}</td>
                    </tr>`).join('');
                caja.innerHTML = `${estado || '<p>Sin estado registrado.</p>'}
                    <div class="overflow-x-auto mt-4">
                        <table class="w-full text-left border-collapse min-w-[700px]">
                            <thead class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase">
                                <tr><th class="py-3 px-4">Inicio</th><th class="py-3 px-4">Estado</th><th class="py-3 px-4 text-right">Leídas</th>
                                    <th class="py-3 px-4 text-right">Nuevas</th><th class="py-3 px-4 text-right">Actualizadas</th>
                                    <th class="py-3 px-4 text-right">Segundos</th><th class="py-3 px-4">Mensaje</th></tr>
                            </thead>
                            <tbody>${filas}</tbody>
                        </table>
                    </div>`;
            } catch (err) {
                caja.textContent = 'No se pudo consultar la sincronización.';
                adminError(err);
            }
        }

        async function reactivarSincronizacion() {
            if (!confirm('¿Reactivar la sincronización? Volverá a intentar en el próximo horario programado.')) return;
            try {
                await postJson('api/sincronizacion.php', { accion: 'reactivar' });
                loadAdminSync();
                if (document.getElementById('adminBitacora')) loadAdminBitacora();
            } catch (err) { adminError(err); }
        }

        async function loadAdminUsuarios() {
            try {
                const r = await fetchJson('api/usuarios.php');
                document.getElementById('adminUsuarios').innerHTML = r.data.map(u => {
                    const estado = u.activo != 1 ? '<span class="text-slate-400">Desactivada</span>'
                                 : u.bloqueado == 1 ? '<span class="text-rose-600">Bloqueada</span>'
                                 : u.debe_cambiar_clave == 1 ? '<span class="text-amber-600">Contraseña temporal</span>'
                                 : '<span class="text-emerald-600">Activa</span>';
                    const opciones = Object.entries(r.roles).map(([valor, nombre]) =>
                        `<option value="${escapeHtml(valor)}" ${valor === u.rol ? 'selected' : ''}>${escapeHtml(nombre)}</option>`).join('');
                    const esYo = u.usuario === APP_SESION.usuario;
                    return `
                    <tr class="border-b border-slate-100" data-id="${Number(u.id)}">
                        <td class="py-2 px-4 font-bold text-slate-800">${escapeHtml(u.usuario)}${esYo ? ' <span class="text-xs text-primary">(tú)</span>' : ''}</td>
                        <td class="py-2 px-4"><input class="u-nombre p-1.5 border border-slate-200 rounded text-sm w-48" maxlength="100" value="${escapeHtml(u.nombre)}"></td>
                        <td class="py-2 px-4"><select class="u-rol p-1.5 border border-slate-200 rounded text-sm">${opciones}</select></td>
                        <td class="py-2 px-4 font-bold text-xs">${estado}</td>
                        <td class="py-2 px-4 text-slate-500">${escapeHtml(u.ultimo_acceso ?? 'Nunca')}</td>
                        <td class="py-2 px-4 text-right whitespace-nowrap">
                            <button class="u-accion text-xs font-bold text-primary hover:underline" data-accion="editar">Guardar</button>
                            <button class="u-accion text-xs font-bold text-amber-600 hover:underline ml-3" data-accion="restablecer">Restablecer contraseña</button>
                            ${esYo ? '' : `<button class="u-accion text-xs font-bold ${u.activo == 1 ? 'text-rose-600' : 'text-emerald-600'} hover:underline ml-3"
                                data-accion="${u.activo == 1 ? 'desactivar' : 'activar'}">${u.activo == 1 ? 'Desactivar' : 'Activar'}</button>`}
                        </td>
                    </tr>`;
                }).join('');
            } catch (err) { adminError(err); }
        }

        // Muestra una contraseña temporal una sola vez, para entregarla en privado
        function mostrarClaveTemporal(usuario, clave) {
            const caja = document.getElementById('adminClave');
            caja.innerHTML = `<strong>Contraseña temporal de ${escapeHtml(usuario)}:</strong>
                <code class="ml-2 bg-white px-2 py-1 rounded font-bold">${escapeHtml(clave)}</code>
                <span class="block mt-2 text-xs">Entrégala en privado. No se volverá a mostrar; la persona deberá cambiarla al ingresar.</span>`;
            caja.classList.remove('hidden');
        }

        async function crearUsuario(e) {
            e.preventDefault();
            try {
                const r = await postJson('api/usuarios.php', {
                    accion: 'crear',
                    usuario: document.getElementById('nuevoUsuario').value,
                    nombre: document.getElementById('nuevoNombre').value,
                    rol: document.getElementById('nuevoRol').value
                });
                document.getElementById('formUsuario').reset();
                mostrarClaveTemporal(r.usuario, r.claveTemporal);
                loadAdminUsuarios();
                if (document.getElementById('adminBitacora')) loadAdminBitacora();
            } catch (err) { adminError(err); }
        }

        if (document.getElementById('adminUsuarios')) {
            document.getElementById('adminUsuarios').addEventListener('click', async e => {
                const btn = e.target.closest('.u-accion');
                if (!btn) return;
                const fila   = btn.closest('tr');
                const accion = btn.dataset.accion;
                const datos  = { accion, id: Number(fila.dataset.id) };
                if (accion === 'editar') {
                    datos.nombre = fila.querySelector('.u-nombre').value;
                    datos.rol    = fila.querySelector('.u-rol').value;
                }
                if (accion === 'desactivar' && !confirm('¿Desactivar esta cuenta? Su sesión se cerrará de inmediato.')) return;
                if (accion === 'restablecer' && !confirm('¿Generar una nueva contraseña temporal para esta cuenta?')) return;
                try {
                    const r = await postJson('api/usuarios.php', datos);
                    if (r.claveTemporal) mostrarClaveTemporal(r.usuario, r.claveTemporal);
                    loadAdminUsuarios();
                    if (document.getElementById('adminBitacora')) loadAdminBitacora();
                } catch (err) { adminError(err); }
            });
        }

        async function loadAdminBitacora() {
            try {
                const r = await fetchJson('api/bitacora.php?limite=200');
                document.getElementById('adminBitacora').innerHTML = r.data.map(a => `
                    <tr class="border-b border-slate-100">
                        <td class="py-2 px-4 text-slate-500 whitespace-nowrap">${escapeHtml(a.fecha.slice(0, 19))}</td>
                        <td class="py-2 px-4 font-bold text-slate-800">${escapeHtml(a.usuario)}</td>
                        <td class="py-2 px-4 ${/fallido|bloque|denegado|revocada/.test(a.evento) ? 'text-rose-600 font-bold' : ''}">${escapeHtml(EVENTOS[a.evento] ?? a.evento)}</td>
                        <td class="py-2 px-4 text-slate-500">${escapeHtml(a.detalle)}</td>
                        <td class="py-2 px-4 text-slate-400">${escapeHtml(a.ip)}</td>
                    </tr>`).join('');
            } catch (err) { adminError(err); }
        }

        // Punto de entrada: inicializa la gráfica en vivo y carga el Dashboard
        window.onload = () => { loadScreen('dashboard'); };
    </script>
</body>
</html>
