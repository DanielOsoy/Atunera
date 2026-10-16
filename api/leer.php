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

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/database.php';

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
    // (posiciones 2-4 del código son la abreviatura del barco, ej. "ISY" de "TISISY12455")
    $stmtBarcos = $db->query("SELECT id, nombre, abreviatura FROM barcos ORDER BY nombre");
    $barcos     = $stmtBarcos->fetchAll(PDO::FETCH_ASSOC);

    // Cantidad de registros por talla — permite calcular el promedio ponderado del
    // estándar sin recorrer todo el historial en el frontend
    $stmtCounts = $db->query("SELECT talla_id, COUNT(*) AS cantidad FROM predicciones GROUP BY talla_id");
    $tallaCounts = $stmtCounts->fetchAll(PDO::FETCH_ASSOC);

    // --- Historial completo ---
    // Se devuelven TODOS los registros (sin LIMIT) para que las gráficas y KPIs
    // globales sean precisos. El frontend agrupa por compra para evitar saturación visual.
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
    $history  = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data"   => [
            "species"     => $species,
            "tallas"      => $tallas,
            "barcos"      => $barcos,
            "history"     => $history,
            "tallaCounts" => $tallaCounts
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
