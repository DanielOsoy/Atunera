-- Índices para las búsquedas y el orden que usa la API sobre `predicciones`.
--   idx_predicciones_compra: buscar.php (WHERE compra = ? ORDER BY talla_id).
--   idx_predicciones_fecha:  leer.php e historial.php (ORDER BY fecha_registro).
-- Se puede ejecutar varias veces. No modifica datos.
-- Uso: mysql <base> < migraciones/01-indices-predicciones.sql

SET @sql = (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX idx_predicciones_compra ON predicciones (compra, talla_id)',
    'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'predicciones' AND INDEX_NAME = 'idx_predicciones_compra');
PREPARE crear FROM @sql; EXECUTE crear; DEALLOCATE PREPARE crear;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX idx_predicciones_fecha ON predicciones (fecha_registro)',
    'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'predicciones' AND INDEX_NAME = 'idx_predicciones_fecha');
PREPARE crear FROM @sql; EXECUTE crear; DEALLOCATE PREPARE crear;
