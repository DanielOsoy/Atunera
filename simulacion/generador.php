<?php

/**
 * Generador de datos simulados para la empresa (SQL Server).
 *
 * Comandos:
 *   historico  Carga el histórico completo (por defecto 2021-01-01 a 2025-12-31, 6 a 20 partidas/día).
 *              --desde=AAAA-MM-DD --hasta=AAAA-MM-DD --min=6 --max=20 --semilla=42
 *              --total=N    Reparte N partidas en el rango (prueba de estrés)
 *              --vaciar     Borra las partidas existentes antes de cargar
 *   nuevos     Simula un ciclo de 12 horas: agrega partidas nuevas y corrige algunas existentes.
 *              --cantidad=N (3 a 10)  --correcciones=N (0 a 2)  --fecha="AAAA-MM-DD HH:MM"
 *   resumen    Muestra totales, rangos de ID y fechas, volumen por año y tamaño en disco.
 *   vaciar     Borra todas las partidas y reinicia el ID.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/ModeloPlanta.php';

const FILAS_POR_INSERT = 1000; // máximo de filas por VALUES en SQL Server
const COLUMNAS_HISTORICO = 'CodigoCompra, FechaProceso, BarcoID, EspecieID, TallaID, PesoNetoKg, Rendimiento, '
                         . 'Turno, Operador, Observaciones, FechaCreacion, FechaModificacion';

date_default_timezone_set(getenv('TZ') ?: 'America/Guatemala');

try {
    $opciones = leerOpciones(array_slice($argv, 2));
    match ($argv[1] ?? 'ayuda') {
        'historico' => historico(conectar(), $opciones),
        'nuevos'    => nuevos(conectar(), $opciones),
        'resumen'   => resumen(conectar()),
        'vaciar'    => vaciar(conectar()),
        default     => ayuda(),
    };
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ---------------------------------------------------------------------

function historico(PDO $pdo, array $op): void
{
    $desde   = new DateTimeImmutable($op['desde'] ?? '2021-01-01');
    $hasta   = new DateTimeImmutable($op['hasta'] ?? '2025-12-31');
    $semilla = (int)($op['semilla'] ?? 42);
    $dias    = $desde->diff($hasta)->days + 1;

    if (isset($op['total'])) {
        $promedio = (int)$op['total'] / $dias;
        $min = max(1, (int)round($promedio * 0.5));
        $max = max($min, (int)round($promedio * 1.5));
    } else {
        $min = (int)($op['min'] ?? 6);
        $max = (int)($op['max'] ?? 20);
    }

    $existentes = (int)$pdo->query('SELECT COUNT(*) FROM dbo.Partidas')->fetchColumn();
    if ($existentes > 0) {
        if (!isset($op['vaciar'])) {
            throw new RuntimeException("La tabla ya tiene $existentes partidas. Usa --vaciar para reemplazarlas.");
        }
        vaciar($pdo);
    }

    printf("Generando histórico %s a %s (%d días, %d a %d partidas/día, semilla %d)\n",
        $desde->format('Y-m-d'), $hasta->format('Y-m-d'), $dias, $min, $max, $semilla);

    $modelo = crearModelo($pdo, $semilla);
    $inicio = microtime(true);
    $buffer = [];
    $total  = 0;
    $compra = null;
    $anioActual = $desde->format('Y');
    $totalAnio  = 0;

    for ($dia = $desde; $dia <= $hasta; $dia = $dia->modify('+1 day')) {
        if ($dia->format('Y') !== $anioActual) {
            printf("  %s: %s partidas\n", $anioActual, number_format($totalAnio));
            $anioActual = $dia->format('Y');
            $totalAnio  = 0;
        }

        foreach ($modelo->horariosDelDia($dia, $modelo->enteroEntre($min, $max)) as $momento) {
            if ($compra === null || $compra['restantes'] <= 0) {
                $compra = $modelo->nuevaCompra($momento);
            }
            $buffer[] = $modelo->nuevaPartida($compra, $momento);
            $total++;
            $totalAnio++;

            if (count($buffer) === FILAS_POR_INSERT) {
                insertarLote($pdo, $buffer);
                $buffer = [];
            }
        }
    }
    if ($buffer) {
        insertarLote($pdo, $buffer);
    }
    printf("  %s: %s partidas\n", $anioActual, number_format($totalAnio));

    $segundos = microtime(true) - $inicio;
    printf("Listo: %s partidas en %.1f s (%s filas/s)\n",
        number_format($total), $segundos, number_format($total / max($segundos, 0.001)));
}

function nuevos(PDO $pdo, array $op): void
{
    $momentoFinal = new DateTimeImmutable($op['fecha'] ?? 'now');
    $modelo       = crearModelo($pdo, random_int(1, PHP_INT_MAX));
    $cantidad     = (int)($op['cantidad'] ?? $modelo->enteroEntre(3, 10));
    $correcciones = (int)($op['correcciones'] ?? $modelo->enteroEntre(0, 2));

    $existe = $pdo->prepare('SELECT TOP 1 1 FROM dbo.Partidas WHERE CodigoCompra = ?');
    $existeEnBase = function (string $codigo) use ($existe): bool {
        $existe->execute([$codigo]);
        return $existe->fetchColumn() !== false;
    };

    // Continúa la última compra abierta o arranca una nueva
    $ultima = $pdo->query('SELECT TOP 1 CodigoCompra, BarcoID, EspecieID FROM dbo.Partidas ORDER BY PartidaID DESC')
                  ->fetch(PDO::FETCH_ASSOC);
    $compra = null;
    if ($ultima && $modelo->enteroEntre(1, 100) <= 60) {
        $compra = [
            'codigo'           => $ultima['CodigoCompra'],
            'barcoId'          => (int)$ultima['BarcoID'],
            'especiePrincipal' => (int)$ultima['EspecieID'],
            'restantes'        => $modelo->enteroEntre(1, $cantidad),
        ];
    }

    // Las partidas caen dentro de las 12 horas anteriores al momento indicado
    $momentos = [];
    for ($i = 0; $i < $cantidad; $i++) {
        $momentos[] = $momentoFinal->modify(sprintf('-%d seconds', $modelo->enteroEntre(0, 12 * 3600)));
    }
    sort($momentos);

    // Sin FechaCreacion/FechaModificacion: las pone SQL Server (hora real de registro)
    $insertar = $pdo->prepare(
        'INSERT INTO dbo.Partidas (CodigoCompra, FechaProceso, BarcoID, EspecieID, TallaID, PesoNetoKg, '
        . 'Rendimiento, Turno, Operador, Observaciones) OUTPUT INSERTED.PartidaID VALUES (?,?,?,?,?,?,?,?,?,?)'
    );

    $pdo->beginTransaction();
    $idsNuevos = [];
    foreach ($momentos as $momento) {
        if ($compra === null || $compra['restantes'] <= 0) {
            $compra = $modelo->nuevaCompra($momento, $existeEnBase);
        }
        $insertar->execute(array_values($modelo->nuevaPartida($compra, $momento, false)));
        $idsNuevos[] = (int)$insertar->fetchColumn();
        $insertar->closeCursor();
    }

    // Correcciones de control de calidad sobre partidas recientes; el trigger actualiza FechaModificacion
    $candidatas = $pdo->query(sprintf(
        'SELECT TOP (%d) PartidaID, Rendimiento
           FROM (SELECT TOP 500 PartidaID, Rendimiento FROM dbo.Partidas WHERE PartidaID < %d ORDER BY PartidaID DESC) recientes
          ORDER BY NEWID()',
        $correcciones,
        $idsNuevos ? min($idsNuevos) : PHP_INT_MAX
    ));
    $corregir = $pdo->prepare(
        "UPDATE dbo.Partidas SET Rendimiento = ?, Observaciones = N'Rendimiento corregido por control de calidad' WHERE PartidaID = ?"
    );
    $idsCorregidos = [];
    foreach ($candidatas->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $corregir->execute([$modelo->ajusteCorreccion((float)$p['Rendimiento']), $p['PartidaID']]);
        $idsCorregidos[] = (int)$p['PartidaID'];
    }
    $pdo->commit();

    printf("Ciclo de 12 h simulado hasta %s\n", $momentoFinal->format('Y-m-d H:i'));
    printf("  Partidas nuevas: %d %s\n", count($idsNuevos), $idsNuevos ? '(IDs ' . min($idsNuevos) . '-' . max($idsNuevos) . ')' : '');
    printf("  Partidas corregidas: %d %s\n", count($idsCorregidos), $idsCorregidos ? '(IDs ' . implode(', ', $idsCorregidos) . ')' : '');
}

function resumen(PDO $pdo): void
{
    $g = $pdo->query(
        'SELECT COUNT(*) AS partidas, COUNT(DISTINCT CodigoCompra) AS compras,
                MIN(PartidaID) AS id_min, MAX(PartidaID) AS id_max,
                MIN(FechaProceso) AS proceso_min, MAX(FechaProceso) AS proceso_max,
                MAX(FechaModificacion) AS modificacion_max,
                SUM(CASE WHEN FechaModificacion > DATEADD(hour, 24, FechaCreacion) THEN 1 ELSE 0 END) AS corregidas
           FROM dbo.Partidas'
    )->fetch(PDO::FETCH_ASSOC);

    if ((int)$g['partidas'] === 0) {
        echo "La tabla dbo.Partidas está vacía.\n";
        return;
    }

    printf("Partidas: %s | Compras: %s | Promedio por compra: %.1f\n",
        number_format((int)$g['partidas']), number_format((int)$g['compras']), $g['partidas'] / $g['compras']);
    printf("IDs: %s a %s\n", $g['id_min'], $g['id_max']);
    printf("FechaProceso: %s a %s\n", $g['proceso_min'], $g['proceso_max']);
    printf("Última FechaModificacion: %s | Corregidas después de registrarse: %s\n",
        $g['modificacion_max'], number_format((int)$g['corregidas']));

    echo "\nPor año:\n";
    $anios = $pdo->query(
        'SELECT YEAR(FechaProceso) AS anio, COUNT(*) AS partidas, COUNT(DISTINCT CodigoCompra) AS compras,
                COUNT(DISTINCT CAST(FechaProceso AS date)) AS dias
           FROM dbo.Partidas GROUP BY YEAR(FechaProceso) ORDER BY anio'
    );
    foreach ($anios as $a) {
        printf("  %s: %7s partidas | %5s compras | %.1f partidas/día\n",
            $a['anio'], number_format((int)$a['partidas']), number_format((int)$a['compras']), $a['partidas'] / $a['dias']);
    }

    echo "\nPor especie:\n";
    $especies = $pdo->query(
        'SELECT e.Nombre, COUNT(*) AS partidas, AVG(p.Rendimiento) AS rendimiento,
                100.0 * SUM(CASE WHEN p.Rendimiento < t.Estandar THEN 1 ELSE 0 END) / COUNT(*) AS bajo_estandar
           FROM dbo.Partidas p
           JOIN dbo.Especies e ON e.EspecieID = p.EspecieID
           JOIN dbo.Tallas   t ON t.TallaID   = p.TallaID
          GROUP BY e.Nombre ORDER BY partidas DESC'
    );
    foreach ($especies as $e) {
        printf("  %-10s %5.1f%% de las partidas | rendimiento %.2f%% | %4.1f%% bajo el estándar\n",
            $e['Nombre'], 100 * $e['partidas'] / $g['partidas'], 100 * $e['rendimiento'], $e['bajo_estandar']);
    }

    $espacio = $pdo->query("EXEC sp_spaceused 'dbo.Partidas'")->fetch(PDO::FETCH_ASSOC);
    printf("\nEspacio en disco: datos %s | índices %s\n", trim($espacio['data']), trim($espacio['index_size']));
}

function vaciar(PDO $pdo): void
{
    $pdo->exec('TRUNCATE TABLE dbo.Partidas');
    echo "dbo.Partidas vaciada (ID reiniciado).\n";
}

function ayuda(): void
{
    echo "Uso: php simulacion/generador.php <historico|nuevos|resumen|vaciar> [--opciones]\n";
    echo "Ver el encabezado de este archivo para el detalle de opciones.\n";
}

// ---------------------------------------------------------------------

function conectar(): PDO
{
    $variables = ['EMPRESA_HOST', 'EMPRESA_PORT', 'EMPRESA_DB', 'EMPRESA_ADMIN_USER', 'EMPRESA_ADMIN_PASSWORD'];
    $faltan = array_filter($variables, fn($v) => getenv($v) === false || getenv($v) === '');
    if ($faltan) {
        throw new RuntimeException('Faltan variables de entorno: ' . implode(', ', $faltan));
    }

    $dsn = sprintf('sqlsrv:Server=%s,%s;Database=%s;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=15',
        getenv('EMPRESA_HOST'), getenv('EMPRESA_PORT'), getenv('EMPRESA_DB'));

    return new PDO($dsn, getenv('EMPRESA_ADMIN_USER'), getenv('EMPRESA_ADMIN_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function crearModelo(PDO $pdo, int $semilla): ModeloPlanta
{
    $barcos = $pdo->query('SELECT BarcoID, Abreviatura FROM dbo.Barcos')->fetchAll(PDO::FETCH_ASSOC);
    $tallas = $pdo->query('SELECT TallaID, Estandar FROM dbo.Tallas')->fetchAll(PDO::FETCH_ASSOC);
    return new ModeloPlanta($barcos, $tallas, $semilla);
}

/** Inserta hasta 1000 filas en una sola sentencia. Los textos pasan por quote(). */
function insertarLote(PDO $pdo, array $filas): void
{
    $valores = [];
    foreach ($filas as $f) {
        $valores[] = sprintf("(%s,'%s',%d,%d,%d,%.2F,%.4F,'%s',%s,%s,'%s','%s')",
            $pdo->quote($f['CodigoCompra']),
            $f['FechaProceso'],
            $f['BarcoID'],
            $f['EspecieID'],
            $f['TallaID'],
            $f['PesoNetoKg'],
            $f['Rendimiento'],
            $f['Turno'],
            $pdo->quote($f['Operador']),
            $f['Observaciones'] === null ? 'NULL' : 'N' . $pdo->quote($f['Observaciones']),
            $f['FechaCreacion'],
            $f['FechaModificacion']
        );
    }

    $pdo->beginTransaction();
    $pdo->exec('INSERT INTO dbo.Partidas (' . COLUMNAS_HISTORICO . ') VALUES ' . implode(',', $valores));
    $pdo->commit();
}

/** Convierte ["--clave=valor", "--bandera"] en ['clave' => 'valor', 'bandera' => true]. */
function leerOpciones(array $argumentos): array
{
    $opciones = [];
    foreach ($argumentos as $arg) {
        if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
            $opciones[$m[1]] = $m[2] ?? true;
        }
    }
    return $opciones;
}
