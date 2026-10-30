<?php

declare(strict_types=1);

/**
 * Copia de forma incremental las partidas de la empresa (SQL Server) a la base local (MySQL).
 *
 * Mecanismos (uno por corrida, cada uno con su punto de control):
 *   fecha  Sigue (FechaModificacion, PartidaID). Detecta partidas nuevas y corregidas.
 *   id     Sigue PartidaID y vuelve a revisar los últimos N IDs. Solo detecta
 *          correcciones dentro de esa ventana.
 *
 * Garantías:
 *   - Sin duplicados: inserta o actualiza usando origen_id como llave única.
 *   - Sin datos a medias: cada lote y su punto de control se guardan en una misma transacción.
 *   - Tras varias corridas fallidas seguidas se detiene hasta reactivarlo a mano.
 */
final class Sincronizador
{
    public const SALIDA_OK       = 0;
    public const SALIDA_ERROR    = 1;
    public const SALIDA_DETENIDO = 3;

    // Correspondencia con la empresa: al conectar la base real se ajusta aquí y en las vistas.
    private const SQL_ESPECIES = 'SELECT EspecieID AS id, Nombre AS nombre FROM integracion.vw_especies';
    private const SQL_TALLAS   = 'SELECT TallaID AS id, Rango AS rango, Estandar AS estandar FROM integracion.vw_tallas';
    private const SQL_BARCOS   = 'SELECT BarcoID AS id, Nombre AS nombre, Abreviatura AS abreviatura FROM integracion.vw_barcos';
    private const SQL_CONTEO   = 'SELECT COUNT(*) AS total FROM integracion.vw_partidas';

    private const COLUMNAS_PARTIDA = 'PartidaID, CodigoCompra, FechaProceso, EspecieID, TallaID, Rendimiento, FechaModificacion';

    private const FECHA_INICIAL = '1900-01-01 00:00:00.000';

    private ?PDO $empresa = null;
    private PDO $local;
    private int $reintentos = 0;

    /** @param array<string, string> $config */
    public function __construct(private array $config)
    {
    }

    public function ejecutar(string $modo, int $maxLotes = 0): int
    {
        $this->local = $this->conectarLocal();

        // Evita dos corridas simultáneas; MySQL libera el candado si el proceso muere
        if ((int)$this->local->query("SELECT GET_LOCK('sync_atunera', 0)")->fetchColumn() !== 1) {
            echo "Ya hay una sincronización en curso; se omite esta corrida.\n";
            return self::SALIDA_OK;
        }

        $inicio = microtime(true);
        $this->reintentos = 0;
        $this->local->prepare('INSERT IGNORE INTO sync_estado (modo) VALUES (?)')->execute([$modo]);
        $estado = $this->leerEstado($modo);

        $this->local->prepare('INSERT INTO sync_log (modo, inicio) VALUES (?, NOW(3))')->execute([$modo]);
        $logId = (int)$this->local->lastInsertId();

        if ((int)$estado['detenido'] === 1) {
            $mensaje = 'Sincronizador detenido: ' . $estado['motivo'];
            $this->cerrarLog($logId, 'omitida', [], $inicio, $mensaje);
            fwrite(STDERR, $mensaje . PHP_EOL . "Corrige la causa y ejecuta el comando reactivar.\n");
            return self::SALIDA_DETENIDO;
        }

        $t = ['lotes' => 0, 'leidas' => 0, 'insertadas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0];

        try {
            $estandares = $this->sincronizarCatalogos();
            $parcial = $this->sincronizarPartidas($modo, $estado, $estandares, $maxLotes, $t);

            $t['total_origen'] = (int)$this->leerEmpresa(self::SQL_CONTEO)[0]['total'];
            $t['total_local']  = (int)$this->local
                ->query('SELECT COUNT(*) FROM predicciones WHERE origen_id IS NOT NULL')->fetchColumn();

            $this->local->prepare('UPDATE sync_estado SET fallos_consecutivos = 0, motivo = NULL WHERE modo = ?')
                        ->execute([$modo]);

            // Ningún mecanismo detecta registros borrados en la empresa; el conteo sí los delata
            $advertencia = !$parcial && $t['total_origen'] !== $t['total_local']
                ? sprintf('Conteos distintos: empresa %d, local %d. Puede haber registros borrados en la empresa.',
                    $t['total_origen'], $t['total_local'])
                : null;

            $this->cerrarLog($logId, $parcial ? 'parcial' : 'ok', $t, $inicio, $advertencia);
            $this->imprimirResumen($modo, $parcial ? 'parcial' : 'ok', $t, $inicio, $advertencia);
            return self::SALIDA_OK;
        } catch (Throwable $e) {
            if ($this->local->inTransaction()) {
                $this->local->rollBack();
            }
            $mensaje = $this->registrarFallo($modo, (int)$estado['fallos_consecutivos'] + 1, $e);
            $this->cerrarLog($logId, 'error', $t, $inicio, $mensaje);
            fwrite(STDERR, 'ERROR: ' . $mensaje . PHP_EOL);
            return self::SALIDA_ERROR;
        }
    }

    public function reactivar(string $modo): void
    {
        $local = $this->conectarLocal();
        $local->prepare('UPDATE sync_estado SET detenido = 0, fallos_consecutivos = 0, motivo = NULL WHERE modo = ?')
              ->execute([$modo]);
        echo "Sincronizador reactivado (modo $modo).\n";
    }

    public function imprimirEstado(): void
    {
        $local = $this->conectarLocal();

        echo "Puntos de control:\n";
        foreach ($local->query('SELECT * FROM sync_estado ORDER BY modo') as $e) {
            printf("  %-6s fecha=%s id=%s fallos=%s %s\n",
                $e['modo'], $e['ultima_fecha'] ?? '-', $e['ultimo_id'], $e['fallos_consecutivos'],
                $e['detenido'] ? 'DETENIDO: ' . $e['motivo'] : 'activo');
        }

        echo "\nÚltimas corridas:\n";
        $corridas = $local->query(
            'SELECT inicio, modo, estado, lotes, leidas, insertadas, actualizadas, sin_cambios, reintentos, segundos, mensaje
               FROM sync_log ORDER BY id DESC LIMIT 10'
        );
        foreach ($corridas as $c) {
            printf("  %s %-5s %-8s lotes=%-3s leídas=%-6s nuevas=%-6s actualizadas=%-4s iguales=%-5s reintentos=%s %ss %s\n",
                substr($c['inicio'], 0, 19), $c['modo'], $c['estado'], $c['lotes'], $c['leidas'], $c['insertadas'],
                $c['actualizadas'], $c['sin_cambios'], $c['reintentos'], $c['segundos'] ?? '-', $c['mensaje'] ?? '');
        }
    }

    /** Corre en los horarios configurados (hora local). Si falla una corrida, espera a la siguiente. */
    public function programar(string $modo): never
    {
        $horas = array_map('trim', explode(',', $this->config['SYNC_HORAS']));
        printf("Programador activo: modo %s, horarios %s\n", $modo, implode(', ', $horas));

        while (true) {
            $proxima = $this->proximaEjecucion($horas);
            printf("Próxima sincronización: %s\n", $proxima->format('Y-m-d H:i'));
            sleep(max(1, $proxima->getTimestamp() - time()));

            try {
                $this->ejecutar($modo);
            } catch (Throwable $e) {
                fwrite(STDERR, 'ERROR: no se pudo ejecutar la corrida: ' . $e->getMessage() . PHP_EOL);
            }
        }
    }

    // -----------------------------------------------------------------

    /** @param string[] $horas */
    private function proximaEjecucion(array $horas): DateTimeImmutable
    {
        $ahora = new DateTimeImmutable();
        $candidatas = [];
        foreach ($horas as $hora) {
            [$h, $m] = array_map('intval', explode(':', $hora));
            $candidata = $ahora->setTime($h, $m);
            $candidatas[] = $candidata > $ahora ? $candidata : $candidata->modify('+1 day');
        }
        return min($candidatas);
    }

    /** @return array<int, float> Estándar por talla */
    private function sincronizarCatalogos(): array
    {
        $especies = $this->leerEmpresa(self::SQL_ESPECIES);
        $tallas   = $this->leerEmpresa(self::SQL_TALLAS);
        $barcos   = $this->leerEmpresa(self::SQL_BARCOS);

        $this->local->beginTransaction();
        $this->upsert('especies', ['id', 'nombre'], $especies);
        $this->upsert('tallas', ['id', 'rango', 'estandar'], $tallas);
        $this->upsert('barcos', ['id', 'nombre', 'abreviatura'], $barcos);
        $this->local->commit();

        return array_column(array_map(
            fn($t) => ['id' => (int)$t['id'], 'estandar' => (float)$t['estandar']], $tallas
        ), 'estandar', 'id');
    }

    /** @return bool true si paró por --max-lotes y quedan datos pendientes */
    private function sincronizarPartidas(string $modo, array $estado, array $estandares, int $maxLotes, array &$t): bool
    {
        $tamano = (int)$this->config['SYNC_TAMANO_LOTE'];
        $pausa  = (int)$this->config['SYNC_PAUSA_MS'] * 1000;
        [$fecha, $id] = $this->cursorInicial($modo, $estado);

        while (true) {
            $filas = $modo === 'fecha'
                ? $this->leerEmpresa(
                    sprintf('SELECT TOP (%d) %s FROM integracion.vw_partidas
                              WHERE FechaModificacion >= CAST(? AS datetime2(3))
                                AND (FechaModificacion > CAST(? AS datetime2(3)) OR PartidaID > ?)
                              ORDER BY FechaModificacion, PartidaID', $tamano, self::COLUMNAS_PARTIDA),
                    [$fecha, $fecha, $id])
                : $this->leerEmpresa(
                    sprintf('SELECT TOP (%d) %s FROM integracion.vw_partidas WHERE PartidaID > ? ORDER BY PartidaID',
                        $tamano, self::COLUMNAS_PARTIDA),
                    [$id]);

            if (!$filas) {
                return false;
            }

            $ultima = end($filas);
            $fecha  = $ultima['FechaModificacion'];
            $id     = (int)$ultima['PartidaID'];

            $this->guardarLote($modo, $filas, $estandares, $fecha, $id, $t);

            if (count($filas) < $tamano) {
                return false;
            }
            if ($maxLotes > 0 && $t['lotes'] >= $maxLotes) {
                return true;
            }
            usleep($pausa);
        }
    }

    /**
     * Punto de partida de la lectura. Retrocede a propósito: una transacción de la empresa que
     * confirma tarde puede traer una fecha anterior al punto de control. Releer es inofensivo.
     *
     * @return array{0:string, 1:int}
     */
    private function cursorInicial(string $modo, array $estado): array
    {
        if ($modo === 'id') {
            return [self::FECHA_INICIAL, max(0, (int)$estado['ultimo_id'] - (int)$this->config['SYNC_REVISION_IDS'])];
        }
        if ($estado['ultima_fecha'] === null) {
            return [self::FECHA_INICIAL, 0];
        }
        $inicio = (new DateTimeImmutable($estado['ultima_fecha']))
            ->modify(sprintf('-%d minutes', (int)$this->config['SYNC_SOLAPE_MINUTOS']));
        return [$inicio->format('Y-m-d H:i:s.v'), 0];
    }

    /** Guarda las filas y el punto de control en una misma transacción. */
    private function guardarLote(string $modo, array $filas, array $estandares, string $fecha, int $id, array &$t): void
    {
        $valores = [];
        $ids = [];
        foreach ($filas as $f) {
            $tallaId = (int)$f['TallaID'];
            if (!isset($estandares[$tallaId])) {
                throw new RuntimeException("La partida {$f['PartidaID']} usa la talla $tallaId, que no está en el catálogo.");
            }
            array_push($valores,
                (int)$f['PartidaID'], $f['FechaProceso'], $f['CodigoCompra'], (int)$f['EspecieID'], $tallaId,
                $f['Rendimiento'], (float)$f['Rendimiento'] < $estandares[$tallaId] ? 1 : 0);
            $ids[] = (int)$f['PartidaID'];
        }

        $this->local->beginTransaction();

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $consulta = $this->local->prepare("SELECT COUNT(*) FROM predicciones WHERE origen_id IN ($marcas)");
        $consulta->execute($ids);
        $existentes = (int)$consulta->fetchColumn();

        $fila = '(?,?,?,?,?,?,?)';
        $insertar = $this->local->prepare(
            'INSERT INTO predicciones (origen_id, fecha_registro, compra, especie_id, talla_id, rendimiento_esperado, alerta_status)
             VALUES ' . implode(',', array_fill(0, count($filas), $fila)) . ' AS nueva
             ON DUPLICATE KEY UPDATE fecha_registro = nueva.fecha_registro, compra = nueva.compra,
                 especie_id = nueva.especie_id, talla_id = nueva.talla_id,
                 rendimiento_esperado = nueva.rendimiento_esperado, alerta_status = nueva.alerta_status'
        );
        $insertar->execute($valores);

        // MySQL cuenta 1 por fila insertada, 2 por actualizada y 0 por idéntica
        $nuevas = count($filas) - $existentes;
        $actualizadas = (int)(($insertar->rowCount() - $nuevas) / 2);

        $this->local->prepare('UPDATE sync_estado SET ultima_fecha = ?, ultimo_id = ? WHERE modo = ?')
                    ->execute([$fecha, $id, $modo]);

        $this->local->commit();

        $t['lotes']++;
        $t['leidas']       += count($filas);
        $t['insertadas']   += $nuevas;
        $t['actualizadas'] += $actualizadas;
        $t['sin_cambios']  += $existentes - $actualizadas;
    }

    /** @param array<int, array<string, mixed>> $filas */
    private function upsert(string $tabla, array $columnas, array $filas): void
    {
        if (!$filas) {
            return;
        }
        $marcas = '(' . implode(',', array_fill(0, count($columnas), '?')) . ')';
        $cambios = implode(',', array_map(fn($c) => "$c = nueva.$c", array_slice($columnas, 1)));
        $sql = sprintf('INSERT INTO %s (%s) VALUES %s AS nueva ON DUPLICATE KEY UPDATE %s',
            $tabla, implode(',', $columnas), implode(',', array_fill(0, count($filas), $marcas)), $cambios);

        $valores = [];
        foreach ($filas as $f) {
            foreach ($columnas as $c) {
                $valores[] = $f[$c];
            }
        }
        $this->local->prepare($sql)->execute($valores);
    }

    /** Lee de la empresa con reintentos; ante un fallo reconecta y espera cada vez más. */
    private function leerEmpresa(string $sql, array $parametros = []): array
    {
        $intentos = (int)$this->config['SYNC_REINTENTOS'];
        $espera   = (int)$this->config['SYNC_ESPERA_SEGUNDOS'];

        for ($intento = 1; ; $intento++) {
            try {
                $this->empresa ??= $this->conectarEmpresa();
                $consulta = $this->empresa->prepare($sql);
                $consulta->execute($parametros);
                return $consulta->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $this->empresa = null;
                if ($intento > $intentos) {
                    throw $e;
                }
                $this->reintentos++;
                fwrite(STDERR, sprintf("Fallo al leer de la empresa (intento %d de %d), reintento en %d s: %s\n",
                    $intento, $intentos + 1, $espera, $e->getMessage()));
                sleep($espera);
                $espera *= 4;
            }
        }
    }

    private function registrarFallo(string $modo, int $fallos, Throwable $e): string
    {
        $maximo   = (int)$this->config['SYNC_MAX_FALLOS'];
        $detenido = $fallos >= $maximo;
        $mensaje  = mb_substr(sprintf('Fallo %d de %d: %s', $fallos, $maximo, $e->getMessage()), 0, 900);

        $this->local->prepare('UPDATE sync_estado SET fallos_consecutivos = ?, detenido = ?, motivo = ? WHERE modo = ?')
                    ->execute([$fallos, $detenido ? 1 : 0, $mensaje, $modo]);

        return $detenido ? 'SINCRONIZADOR DETENIDO. ' . $mensaje : $mensaje;
    }

    private function leerEstado(string $modo): array
    {
        $consulta = $this->local->prepare('SELECT * FROM sync_estado WHERE modo = ?');
        $consulta->execute([$modo]);
        return $consulta->fetch(PDO::FETCH_ASSOC);
    }

    private function cerrarLog(int $logId, string $estado, array $t, float $inicio, ?string $mensaje): void
    {
        $this->local->prepare(
            'UPDATE sync_log SET fin = NOW(3), estado = ?, lotes = ?, leidas = ?, insertadas = ?, actualizadas = ?,
                    sin_cambios = ?, reintentos = ?, total_origen = ?, total_local = ?, segundos = ?, mensaje = ?
              WHERE id = ?'
        )->execute([
            $estado, $t['lotes'] ?? 0, $t['leidas'] ?? 0, $t['insertadas'] ?? 0, $t['actualizadas'] ?? 0,
            $t['sin_cambios'] ?? 0, $this->reintentos, $t['total_origen'] ?? null, $t['total_local'] ?? null,
            round(microtime(true) - $inicio, 3), $mensaje, $logId,
        ]);
    }

    private function imprimirResumen(string $modo, string $estado, array $t, float $inicio, ?string $advertencia): void
    {
        printf("[%s] modo=%s estado=%s lotes=%d leídas=%d nuevas=%d actualizadas=%d iguales=%d | empresa=%d local=%d | %.1f s\n",
            date('Y-m-d H:i:s'), $modo, $estado, $t['lotes'], $t['leidas'], $t['insertadas'], $t['actualizadas'],
            $t['sin_cambios'], $t['total_origen'], $t['total_local'], microtime(true) - $inicio);
        if ($advertencia) {
            echo "ADVERTENCIA: $advertencia\n";
        }
    }

    private function conectarEmpresa(): PDO
    {
        $dsn = sprintf('sqlsrv:Server=%s,%s;Database=%s;Encrypt=yes;TrustServerCertificate=%s;LoginTimeout=%d',
            $this->config['EMPRESA_HOST'], $this->config['EMPRESA_PORT'], $this->config['EMPRESA_DB'],
            $this->config['EMPRESA_CONFIAR_CERTIFICADO'], (int)$this->config['SYNC_TIMEOUT_CONEXION']);

        return new PDO($dsn, $this->config['EMPRESA_USER'], $this->config['EMPRESA_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLSRV_ATTR_QUERY_TIMEOUT => (int)$this->config['SYNC_TIMEOUT_CONSULTA'],
        ]);
    }

    private function conectarLocal(): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->config['LOCAL_HOST'], $this->config['LOCAL_PORT'], $this->config['LOCAL_DB']);

        $pdo = new PDO($dsn, $this->config['LOCAL_USER'], $this->config['LOCAL_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => (int)$this->config['SYNC_TIMEOUT_CONEXION'],
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
