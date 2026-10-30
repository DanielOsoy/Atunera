<?php

/**
 * Sincronizador empresa -> copia local.
 *
 * Comandos:
 *   ahora      Corre una sincronización.  --modo=fecha|id  --max-lotes=N (carga inicial por tramos)
 *   programar  Queda activo y corre en los horarios de SYNC_HORAS.
 *   estado     Muestra los puntos de control y las últimas corridas.
 *   reactivar  Vuelve a activarlo después de que se detuvo por fallos.  --modo=fecha|id
 *
 * Códigos de salida: 0 correcto, 1 error, 3 detenido.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/Sincronizador.php';

date_default_timezone_set(getenv('TZ') ?: 'America/Guatemala');

const VALORES_POR_DEFECTO = [
    'SYNC_MODO'                   => 'fecha',
    'SYNC_HORAS'                  => '02:00,14:00',
    'SYNC_TAMANO_LOTE'            => '500',
    'SYNC_PAUSA_MS'               => '200',
    'SYNC_SOLAPE_MINUTOS'         => '10',
    'SYNC_REVISION_IDS'           => '1000',
    'SYNC_REINTENTOS'             => '3',
    'SYNC_ESPERA_SEGUNDOS'        => '2',
    'SYNC_MAX_FALLOS'             => '3',
    'SYNC_TIMEOUT_CONEXION'       => '15',
    'SYNC_TIMEOUT_CONSULTA'       => '30',
    'EMPRESA_CONFIAR_CERTIFICADO' => 'no',
];

const VARIABLES_OBLIGATORIAS = [
    'EMPRESA_HOST', 'EMPRESA_PORT', 'EMPRESA_DB', 'EMPRESA_USER', 'EMPRESA_PASSWORD',
    'LOCAL_HOST', 'LOCAL_PORT', 'LOCAL_DB', 'LOCAL_USER', 'LOCAL_PASSWORD',
];

try {
    $opciones = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
            $opciones[$m[1]] = $m[2];
        }
    }

    $config = VALORES_POR_DEFECTO;
    foreach ([...array_keys(VALORES_POR_DEFECTO), ...VARIABLES_OBLIGATORIAS] as $nombre) {
        $valor = getenv($nombre);
        if ($valor !== false && $valor !== '') {
            $config[$nombre] = $valor;
        }
    }
    $faltan = array_diff(VARIABLES_OBLIGATORIAS, array_keys($config));
    if ($faltan) {
        throw new RuntimeException('Faltan variables de entorno: ' . implode(', ', $faltan));
    }

    $modo = $opciones['modo'] ?? $config['SYNC_MODO'];
    if (!in_array($modo, ['fecha', 'id'], true)) {
        throw new RuntimeException("Modo no válido: $modo (usa fecha o id)");
    }

    $sincronizador = new Sincronizador($config);

    switch ($argv[1] ?? '') {
        case 'ahora':
            exit($sincronizador->ejecutar($modo, (int)($opciones['max-lotes'] ?? 0)));
        case 'programar':
            $sincronizador->programar($modo);
        case 'estado':
            $sincronizador->imprimirEstado();
            break;
        case 'reactivar':
            $sincronizador->reactivar($modo);
            break;
        default:
            echo "Uso: php sync/sincronizar.php <ahora|programar|estado|reactivar> [--modo=fecha|id] [--max-lotes=N]\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(Sincronizador::SALIDA_ERROR);
}
