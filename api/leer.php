<?php

/**
 * API: leer.php
 *
 * Endpoint de lectura general. Devuelve todos los catálogos y el historial
 * completo de lotes registrados en un solo request.
 *
 * Método: GET
 * URL:    /api/leer.php
 *
 * Respuesta exitosa (JSON):
 * {
 *   "status": "success",
 *   "data": {
 *     "species":     [ { id, nombre } ],
 *     "tallas":      [ { id, rango, estandar } ],
 *     "barcos":      [ { id, nombre, abreviatura } ],
 *     "history":     [ { id, fecha_registro, compra, especie_id, talla_id,
 *                        rendimiento_esperado, alerta_status,
 *                        especie_nombre, talla_rango, estandar } ],
 *     "tallaCounts": [ { talla_id, cantidad } ]
 *   }
 * }
 *
 * `tallaCounts` es usado en el frontend para calcular el rendimiento
 * ponderado estándar (cada talla pondera distinto según cuántos registros tiene).
 */

require_once __DIR__ . '/../seguridad/sesion.php';
require_once __DIR__ . '/../config/database.php';

// Solo usuarios con sesión; todos los roles leen el historial
requerirSesionApi();

try {
    $db = (new Database())->getConnection();

    if (!$db) {
        throw new PDOException("No se pudo establecer conexión con la base de datos.");
    }

    // --- Catálogos ---

    // Lista de especies disponibles para los selectores del formulario
    $stmtEsp = $db->query("SELECT id, nombre FROM especies ORDER BY id");
    $species  = $stmtEsp->fetchAll(PDO::FETCH_ASSOC);

    // Rangos de talla ordenados por límite inferior numérico del rango,
    // y como criterio secundario por límite superior (para sub-rangos del mismo inicio).
    $stmtTalla = $db->query("
        SELECT id, rango, estandar FROM tallas
        ORDER BY
            CASE WHEN rango LIKE '-%'
                THEN CAST(rango AS DECIMAL(10,3))
                ELSE CAST(SUBSTRING_INDEX(rango, '-', 1) AS DECIMAL(10,3))
            END ASC,
            CAST(SUBSTRING_INDEX(rango, '-', -1) AS DECIMAL(10,3)) ASC
    ");
    $tallas    = $stmtTalla->fetchAll(PDO::FETCH_ASSOC);

    // Barcos registrados — se usan para auto-detectar el barco desde el código de compra
    // (posiciones 2-4 del código son la abreviatura del barco, ej. "SY1" de "TISY12455")
    $stmtBarcos = $db->query("SELECT id, nombre, abreviatura FROM barcos ORDER BY nombre");
    $barcos     = $stmtBarcos->fetchAll(PDO::FETCH_ASSOC);

    // Cantidad de registros por talla — permite calcular el promedio ponderado del
    // estándar sin recorrer todo el historial en el frontend
    $stmtCounts = $db->query("SELECT talla_id, COUNT(*) AS cantidad FROM predicciones GROUP BY talla_id");
    $tallaCounts = $stmtCounts->fetchAll(PDO::FETCH_ASSOC);

    // --- Historial completo ---
    // Se devuelven TODOS los registros (sin LIMIT) para que las gráficas y KPIs
    // globales sean precisos. El frontend agrupa por compra para evitar saturación visual.
    // Se envían fila por fila y sin búfer de MySQL: la memoria de PHP no crece con el historial.
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $historySql = "
        SELECT
            p.id,
            p.fecha_registro,
            p.compra,
            p.especie_id,
            p.talla_id,
            p.rendimiento_esperado,
            p.alerta_status,
            e.nombre  AS especie_nombre,
            t.rango   AS talla_rango,
            t.estandar
        FROM predicciones p
        LEFT JOIN especies e ON p.especie_id = e.id
        LEFT JOIN tallas   t ON p.talla_id   = t.id
        ORDER BY p.fecha_registro ASC
    ";

    $stmtHist = $db->query($historySql);

    // Comprime si el navegador lo acepta, enviando bloques de 64 KB
    if (extension_loaded('zlib')) {
        ob_start('ob_gzhandler', 65536);
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    $envioIniciado = true;

    echo '{"status":"success","data":{'
        . '"species":' . json_encode($species, $flags)
        . ',"tallas":' . json_encode($tallas, $flags)
        . ',"barcos":' . json_encode($barcos, $flags)
        . ',"history":[';

    $separador = '';
    while ($fila = $stmtHist->fetch(PDO::FETCH_ASSOC)) {
        echo $separador, json_encode($fila, $flags);
        $separador = ',';
    }

    echo '],"tallaCounts":' . json_encode($tallaCounts, $flags) . '}}';

} catch (Throwable $e) {
    error_log("leer.php: " . $e->getMessage());

    // Con el envío iniciado no se puede cambiar el código HTTP: el JSON queda
    // incompleto a propósito para que el frontend lo detecte como fallo.
    if (empty($envioIniciado)) {
        http_response_code(500);
        echo json_encode([
            "status"  => "error",
            "message" => "No se pudieron cargar los datos."
        ], JSON_UNESCAPED_UNICODE);
    }
}
