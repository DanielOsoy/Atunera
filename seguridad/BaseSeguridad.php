<?php

/**
 * Clase BaseSeguridad
 *
 * Conexión PDO a la base `atunera_seguridad` (usuarios y bitácora de accesos).
 * Usa un usuario de MySQL propio, distinto del que lee los datos del negocio,
 * para que un fallo en una parte no dé acceso a la otra.
 *
 * Variables de entorno: AUTH_DB_HOST, AUTH_DB_PORT, AUTH_DB_NAME, AUTH_DB_USER, AUTH_DB_PASS.
 */
final class BaseSeguridad
{
    private static ?PDO $conexion = null;

    /** Devuelve la conexión, creándola la primera vez. Lanza PDOException si falla. */
    public static function conexion(): PDO
    {
        if (self::$conexion === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('AUTH_DB_HOST') ?: '127.0.0.1',
                getenv('AUTH_DB_PORT') ?: '3306',
                getenv('AUTH_DB_NAME') ?: 'atunera_seguridad'
            );
            self::$conexion = new PDO($dsn, getenv('AUTH_DB_USER') ?: '', getenv('AUTH_DB_PASS') ?: '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Consultas preparadas reales en MySQL: los datos nunca se mezclan con el SQL
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$conexion;
    }
}
