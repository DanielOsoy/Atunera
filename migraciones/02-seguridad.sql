-- Base de usuarios y bitácora de accesos de la app.
-- Separada de los datos del negocio: el usuario que la usa no puede leer ni tocar las partidas.
-- Se puede ejecutar varias veces. No borra datos.
-- Uso: mysql -uroot < migraciones/02-seguridad.sql   (luego seguridad/preparar-bd.sh crea los usuarios de MySQL)

CREATE DATABASE IF NOT EXISTS atunera_seguridad CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE atunera_seguridad;

-- Una fila por persona autorizada. Las cuentas no se borran: se desactivan, para conservar la bitácora.
CREATE TABLE IF NOT EXISTS usuarios (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    usuario             VARCHAR(40)  NOT NULL UNIQUE,
    nombre              VARCHAR(100) NOT NULL,
    rol                 ENUM('trazabilidad', 'gerente', 'desarrollador') NOT NULL,
    clave_hash          VARCHAR(255) NOT NULL,
    debe_cambiar_clave  TINYINT(1)   NOT NULL DEFAULT 1,
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    intentos_fallidos   INT          NOT NULL DEFAULT 0,
    bloqueado_hasta     DATETIME     NULL,
    ultimo_acceso       DATETIME     NULL,
    creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Qué pasó, cuándo, quién y desde dónde: ingresos, fallos, bloqueos y cambios de administración.
CREATE TABLE IF NOT EXISTS accesos (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    fecha          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    usuario_id     INT          NULL,
    usuario_texto  VARCHAR(40)  NOT NULL,
    evento         VARCHAR(40)  NOT NULL,
    detalle        VARCHAR(255) NOT NULL DEFAULT '',
    ip             VARCHAR(45)  NOT NULL DEFAULT '',
    agente         VARCHAR(255) NOT NULL DEFAULT '',
    INDEX idx_accesos_fecha (fecha),
    INDEX idx_accesos_ip_evento (ip, evento, fecha)
);
