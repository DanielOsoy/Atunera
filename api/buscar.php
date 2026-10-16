<?php

/**
 * API: buscar.php
 *
 * Busca todos los registros (partidas) de una compra específica.
 * Se usa en el Dashboard para mostrar KPIs contextuales al ingresar
 * un código de compra en el formulario de consulta.
 *
 * Método: GET
 * URL:    /api/buscar.php?compra=TISY12455
 *
 * Parámetros GET:
 *   compra (string, requerido) — código de compra de 9 caracteres
 *
 * Respuesta exitosa (JSON):
 * {
 *   "status": "success",
 *   "compra": "TISY12455",
 *   "total":  4,
 *   "data": [
 *     { id, fecha_registro, compra, rendimiento_esperado, alerta_status,
 *       especie_nombre, talla_rango, estandar },
 *     ...
 *   ]
 * }
 *
 * Notas de diseño:
 *  - Los registros se ordenan por talla_id para agrupar visualmente las partidas
 *    de la misma compra por rango de peso.
 *  - Una sola compra puede tener varias partidas (una por cada especie/talla descargada).
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/database.php';

try {
    $compra = trim($_GET['compra'] ?? '');

    if ($compra === '') {
        throw new InvalidArgumentException("Debe proporcionar un código de compra.");
    }

    $db = (new Database())->getConnection();

    if (!$db) {
        throw new PDOException("No se pudo establecer conexión con la base de datos.");
    }

    // Busca exactamente el código indicado — sin LIKE, para que solo aparezcan
    // los registros que pertenecen a esa compra específica
    $stmt = $db->prepare("
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
        WHERE p.compra = :compra
        ORDER BY p.talla_id ASC
    ");
    $stmt->execute([':compra' => $compra]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "compra" => $compra,
        "total"  => count($rows),
        "data"   => $rows
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
