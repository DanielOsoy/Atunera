<?php

/**
 * API: predictivo.php
 *
 * Registra un nuevo lote (partida) y devuelve el rendimiento calculado.
 * Actúa como punto de entrada para el formulario "Registrar nuevo lote"
 * del Dashboard.
 *
 * Método: POST
 * URL:    /api/predictivo.php
 * Body (JSON):
 *   { "compra": "TISY12455", "especie": 1, "talla": 3 }
 *
 * Flujo:
 *   1. Valida los campos requeridos (compra no vacío, especie y talla > 0).
 *   2. Llama a Prediction::calculate() para obtener el rendimiento promedio
 *      histórico de esa combinación especie/talla.
 *   3. Inserta el registro en `predicciones` con el rendimiento y la alerta calculados.
 *   4. Devuelve el porcentaje y si hubo alerta para que el frontend actualice
 *      el panel de Proyección de Recuperación y el Monitor en Vivo.
 *
 * Respuesta exitosa (JSON):
 * { "status": "success", "data": { "percent": 43.5, "alert": false } }
 *
 * Respuesta de error (HTTP 400):
 * { "status": "error", "message": "Datos incompletos o inválidos." }
 *
 * CORS: Se permiten OPTIONS para preflight de browsers con Content-Type: application/json.
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Preflight de CORS — el browser envía OPTIONS antes del POST real
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Prediccion.php';

try {
    // Lee el cuerpo del request como JSON (enviado por fetch() con JSON.stringify)
    $data = json_decode(file_get_contents("php://input"));

    $compra  = trim($data->compra ?? '');
    $especie = (int)($data->especie ?? 0);
    $talla   = (int)($data->talla ?? 0);

    if ($compra === '' || $especie <= 0 || $talla <= 0) {
        throw new InvalidArgumentException("Datos incompletos o inválidos.");
    }

    $db = (new Database())->getConnection();

    if (!$db) {
        throw new PDOException("No se pudo establecer conexión con la base de datos.");
    }

    // Calcula el rendimiento esperado basado en el historial de la misma especie+talla
    $result = Prediction::calculate($db, $especie, $talla);

    // rendimiento_esperado se almacena en decimal (0 a 1) — el porcentaje se divide /100
    $rendimientoDecimal = $result['percent'] / 100;
    // alerta_status: 1 = por debajo del estándar, 0 = óptimo
    $alertaValor        = $result['alert'] ? 1 : 0;

    // Inserta el nuevo registro en el historial de predicciones
    $stmt = $db->prepare("
        INSERT INTO predicciones (compra, especie_id, talla_id, rendimiento_esperado, alerta_status)
        VALUES (:compra, :especie_id, :talla_id, :rendimiento_esperado, :alerta_status)
    ");
    $stmt->execute([
        ':compra'               => $compra,
        ':especie_id'           => $especie,
        ':talla_id'             => $talla,
        ':rendimiento_esperado' => $rendimientoDecimal,
        ':alerta_status'        => $alertaValor,
    ]);

    echo json_encode([
        "status" => "success",
        "data"   => [
            "percent" => $result['percent'],
            "alert"   => $result['alert']
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
