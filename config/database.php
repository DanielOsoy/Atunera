<?php

/**
 * Clase Database
 *
 * Gestiona la conexión PDO a MySQL.
 * Lee las credenciales desde variables de entorno para permitir
 * distintos entornos (Docker, local con XAMPP) sin cambiar el código.
 *
 * Variables esperadas en el entorno:
 *   DB_HOST  — hostname del servidor MySQL          (default: 127.0.0.1)
 *   DB_PORT  — puerto del servidor MySQL            (default: 3306)
 *   DB_NAME  — nombre de la base de datos           (default: industria_atunera)
 *   DB_USER  — usuario MySQL                        (default: root)
 *   DB_PASS  — contraseña MySQL                     (default: vacío)
 *
 * En Docker las variables se inyectan desde compose.yaml via "environment:".
 */
class Database
{
    private string $host;
    private string $port;
    private string $db_name;
    private string $username;
    private string $password;

    public ?PDO $conn = null;

    public function __construct()
    {
        // getenv() lee la variable de entorno; el operador ?: provee el valor por defecto
        // para cuando se corre fuera de Docker (ej. XAMPP local)
        $this->host     = getenv('DB_HOST') ?: '127.0.0.1';
        $this->port     = getenv('DB_PORT') ?: '3306';
        $this->db_name  = getenv('DB_NAME') ?: 'industria_atunera';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') ?: '';
    }

    /**
     * Crea y devuelve la conexión PDO.
     * Retorna null si la conexión falla (el error queda en el log de PHP).
     */
    public function getConnection(): ?PDO
    {
        $this->conn = null;

        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4";

            $this->conn = new PDO($dsn, $this->username, $this->password, [
                // Lanza excepciones en vez de errores silenciosos
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Devuelve filas como arrays asociativos por defecto
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Consultas preparadas reales en MySQL: los datos nunca se mezclan con el SQL
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $exception) {
            error_log("Error de conexión: " . $exception->getMessage());
        }

        return $this->conn;
    }
}
