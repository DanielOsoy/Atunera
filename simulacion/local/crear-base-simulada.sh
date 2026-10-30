#!/bin/sh
# Crea la base local de prueba `industria_atunera_sim` dentro del contenedor MySQL:
#   - Misma estructura que la base real (tablas, índices y llaves foráneas).
#   - Mismos catálogos (especies, tallas, barcos, proveedores), SIN partidas.
#   - Usuario `sync_sim` que solo puede leer y escribir en la base simulada.
# La base real `industria_atunera` solo se lee, nunca se modifica.
#
# Ejecutar dentro del contenedor db con SIM_SYNC_PASS definido.
# Si la base simulada ya existe no hace nada, salvo con RECREAR=1 (borra lo sincronizado).
set -eu

: "${SIM_SYNC_PASS:?Falta SIM_SYNC_PASS}"
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"

REAL=industria_atunera
SIM=industria_atunera_sim

tablas=$(mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SIM'")
if [ "$tablas" -gt 0 ] && [ "${RECREAR:-0}" != "1" ]; then
    echo "La base $SIM ya existe con $tablas tablas. Usa RECREAR=1 para rehacerla."
    exit 0
fi

mysql -uroot -e "DROP DATABASE IF EXISTS $SIM; CREATE DATABASE $SIM CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

mysqldump -uroot --no-data --skip-triggers --no-tablespaces "$REAL" | mysql -uroot "$SIM"
mysqldump -uroot --no-create-info --skip-triggers --no-tablespaces "$REAL" especies tallas barcos proveedores | mysql -uroot "$SIM"

mysql -uroot <<SQL
CREATE USER IF NOT EXISTS 'sync_sim'@'%' IDENTIFIED BY '$SIM_SYNC_PASS';
ALTER USER 'sync_sim'@'%' IDENTIFIED BY '$SIM_SYNC_PASS';
GRANT SELECT, INSERT, UPDATE ON \`$SIM\`.* TO 'sync_sim'@'%';
SQL

echo "Base $SIM lista (estructura y catálogos copiados, sin partidas)."
