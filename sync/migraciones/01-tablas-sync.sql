-- Prepara una base local para recibir datos del sincronizador. Idempotente.
-- Se ejecuta como administrador sobre la base de destino, nunca con el usuario del sincronizador.

-- origen_id: PartidaID en la empresa. Es la llave que evita duplicados.
-- Los registros propios de la base local quedan con NULL y el sincronizador no los toca.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'predicciones' AND COLUMN_NAME = 'origen_id');
SET @ddl := IF(@existe = 0,
    'ALTER TABLE predicciones ADD COLUMN origen_id INT NULL, ADD UNIQUE KEY uq_predicciones_origen (origen_id)',
    'SELECT 1');
PREPARE sentencia FROM @ddl;
EXECUTE sentencia;
DEALLOCATE PREPARE sentencia;

-- Punto de control por mecanismo: dónde quedó la última sincronización exitosa.
CREATE TABLE IF NOT EXISTS sync_estado (
    modo                VARCHAR(10)   NOT NULL PRIMARY KEY,
    ultima_fecha        DATETIME(3)   NULL,
    ultimo_id           INT           NOT NULL DEFAULT 0,
    fallos_consecutivos INT           NOT NULL DEFAULT 0,
    detenido            TINYINT(1)    NOT NULL DEFAULT 0,
    motivo              VARCHAR(1000) NULL,
    actualizado_en      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Bitácora: una fila por corrida. Si queda en 'en_curso', la corrida murió a medias.
CREATE TABLE IF NOT EXISTS sync_log (
    id           BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    modo         VARCHAR(10)   NOT NULL,
    inicio       DATETIME(3)   NOT NULL,
    fin          DATETIME(3)   NULL,
    estado       ENUM('en_curso','ok','parcial','error','omitida') NOT NULL DEFAULT 'en_curso',
    lotes        INT           NOT NULL DEFAULT 0,
    leidas       INT           NOT NULL DEFAULT 0,
    insertadas   INT           NOT NULL DEFAULT 0,
    actualizadas INT           NOT NULL DEFAULT 0,
    sin_cambios  INT           NOT NULL DEFAULT 0,
    reintentos   INT           NOT NULL DEFAULT 0,
    total_origen INT           NULL,
    total_local  INT           NULL,
    segundos     DECIMAL(10,3) NULL,
    mensaje      VARCHAR(1000) NULL,
    KEY idx_sync_log_inicio (inicio)
);
