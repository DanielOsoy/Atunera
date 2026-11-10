#!/bin/sh
# Crea la base de seguridad y deja a cada usuario de MySQL con el mínimo permiso:
#   - auth_app      : usuarios y bitácora (leer, agregar, actualizar; nunca borrar).
#   - sync_control  : solo reactivar la sincronización (actualizar sync_estado de la copia simulada).
#   - app           : solo lectura de la base real (antes tenía todos los permisos).
#
# Ejecutar dentro del contenedor db con AUTH_DB_PASS y SYNC_CONTROL_PASS definidos.
# Se puede ejecutar varias veces.
set -eu

: "${AUTH_DB_PASS:?Falta AUTH_DB_PASS}"
: "${SYNC_CONTROL_PASS:?Falta SYNC_CONTROL_PASS}"
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"

mysql -uroot < /tmp/02-seguridad.sql

mysql -uroot <<SQL
CREATE USER IF NOT EXISTS 'auth_app'@'%' IDENTIFIED BY '$AUTH_DB_PASS';
ALTER USER 'auth_app'@'%' IDENTIFIED BY '$AUTH_DB_PASS';
GRANT SELECT, INSERT, UPDATE ON atunera_seguridad.* TO 'auth_app'@'%';

CREATE USER IF NOT EXISTS 'sync_control'@'%' IDENTIFIED BY '$SYNC_CONTROL_PASS';
ALTER USER 'sync_control'@'%' IDENTIFIED BY '$SYNC_CONTROL_PASS';
GRANT SELECT, UPDATE ON industria_atunera_sim.sync_estado TO 'sync_control'@'%';

-- La imagen de MySQL guarda el permiso inicial con el guion bajo escapado (industria\_atunera)
REVOKE IF EXISTS ALL PRIVILEGES ON \`industria\\_atunera\`.* FROM 'app'@'%';
REVOKE IF EXISTS ALL PRIVILEGES ON industria_atunera.* FROM 'app'@'%';
GRANT SELECT ON industria_atunera.* TO 'app'@'%';
SQL

echo "Base atunera_seguridad lista; usuarios auth_app, sync_control y app con permisos mínimos."
