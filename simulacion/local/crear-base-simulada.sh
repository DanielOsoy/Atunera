#!/bin/sh
# Prepara la base local de prueba `industria_atunera_sim` dentro del contenedor MySQL:
#   - Misma estructura que la base real (tablas, índices y llaves foráneas).
#   - Mismos catálogos (especies, tallas, barcos, proveedores), SIN partidas.
#   - Usuario `sync_sim` (sincronizador) y `app_sim` (copia de la app, solo lectura).
# La base real `industria_atunera` solo se lee, nunca se modifica.
#
# Ejecutar dentro del contenedor db con SIM_SYNC_PASS y SIM_APP_PASS definidos.
# Si la base ya existe solo actualiza usuarios, salvo con RECREAR=1 (borra lo sincronizado).
set -eu

: "${SIM_SYNC_PASS:?Falta SIM_SYNC_PASS}"
: "${SIM_APP_PASS:?Falta SIM_APP_PASS}"
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"

REAL=industria_atunera
SIM=industria_atunera_sim

tablas=$(mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SIM'")
if [ "$tablas" -gt 0 ] && [ "${RECREAR:-0}" != "1" ]; then
    echo "La base $SIM ya existe con $tablas tablas; no se modifica."
else
    mysql -uroot -e "DROP DATABASE IF EXISTS $SIM; CREATE DATABASE $SIM CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
    mysqldump -uroot --no-data --skip-triggers --no-tablespaces "$REAL" | mysql -uroot "$SIM"
    mysqldump -uroot --no-create-info --skip-triggers --no-tablespaces "$REAL" especies tallas barcos proveedores | mysql -uroot "$SIM"
    echo "Base $SIM creada (estructura y catálogos copiados, sin partidas)."
fi

mysql -uroot <<SQL
CREATE USER IF NOT EXISTS 'sync_sim'@'%' IDENTIFIED BY '$SIM_SYNC_PASS';
ALTER USER 'sync_sim'@'%' IDENTIFIED BY '$SIM_SYNC_PASS';
GRANT SELECT, INSERT, UPDATE ON \`$SIM\`.* TO 'sync_sim'@'%';
CREATE USER IF NOT EXISTS 'app_sim'@'%' IDENTIFIED BY '$SIM_APP_PASS';
ALTER USER 'app_sim'@'%' IDENTIFIED BY '$SIM_APP_PASS';
GRANT SELECT ON \`$SIM\`.* TO 'app_sim'@'%';
SQL

echo "Usuarios sync_sim y app_sim listos."
