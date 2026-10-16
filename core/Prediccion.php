<?php

/**
 * Clase Prediction
 *
 * Contiene la lógica central de predicción de rendimiento para un lote de atún.
 * El rendimiento (yield) mide qué porcentaje de la materia prima se convierte
 * en producto terminado.
 *
 * Algoritmo:
 *   1. Consulta el promedio histórico de rendimiento para la misma combinación
 *      especie + talla en la base de datos.
 *   2. Si no hay historial, usa el estándar de la industria definido en la
 *      tabla `tallas.estandar` como valor base.
 *   3. Compara el rendimiento calculado contra el estándar para disparar alertas.
 */
class Prediction
{
    /**
     * Calcula el rendimiento esperado para una combinación especie/talla.
     *
     * @param PDO $db        Conexión activa a la base de datos.
     * @param int $especie_id ID de la especie (tabla especies).
     * @param int $talla_id   ID del rango de talla (tabla tallas).
     *
     * @return array{percent: float, alert: bool}
     *   - percent: rendimiento como porcentaje (ej. 43.50)
     *   - alert:   true si el rendimiento está por debajo del estándar de la talla
     */
    public static function calculate(PDO $db, int $especie_id, int $talla_id): array
    {
        // Promedio histórico de todos los lotes registrados con esta misma especie y talla
        $stmt = $db->prepare(
            "SELECT AVG(rendimiento_esperado) AS promedio FROM predicciones WHERE especie_id = ? AND talla_id = ?"
        );
        $stmt->execute([$especie_id, $talla_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // El estándar es específico de cada talla — se lee de la BD en lugar de estar
        // hardcodeado, porque cada rango de peso tiene un estándar distinto de la industria
        // (-1.4 kg → 41%, 1.4-1.8 → 41.5%, 10-20 → 47%, etc.)
        $stmtEstandar = $db->prepare("SELECT estandar FROM tallas WHERE id = ?");
        $stmtEstandar->execute([$talla_id]);
        $estandarRow = $stmtEstandar->fetch(PDO::FETCH_ASSOC);
        // Fallback a 44% si la talla no tiene estándar definido
        $estandar    = $estandarRow ? (float)$estandarRow['estandar'] : 0.44;

        // Si no hay registros históricos, el promedio es null y se usa el estándar
        // como estimación inicial (rendimiento esperado = estándar de la talla)
        $rendimiento = ($row && $row['promedio'] !== null)
            ? (float)$row['promedio']
            : $estandar;

        return [
            // Convierte de decimal (0.435) a porcentaje (43.5) con 2 decimales
            "percent" => round($rendimiento * 100, 2),
            // Alerta si el rendimiento promedio histórico no alcanza el estándar
            "alert"   => $rendimiento < $estandar
        ];
    }
}
