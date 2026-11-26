<?php
class Conexion {
    public static function conectar() {
        // Soporta DATABASE_URL (mysql://user:pass@host:port/dbname) o variables separadas DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
        $dbUrl = getenv('DATABASE_URL');
        if ($dbUrl) {
            $parts = parse_url($dbUrl);
            $host    = $parts['host'] ?? 'localhost';
            $port    = $parts['port'] ?? '3306';
            $bd      = isset($parts['path']) ? ltrim($parts['path'], '/') : 'veterinaria_db';
            $usuario = $parts['user'] ?? 'root';
            $clave   = $parts['pass'] ?? '';
        } else {
            $host    = getenv('DB_HOST')     ?: 'localhost';
            $port    = getenv('DB_PORT')     ?: '3306';
            $bd      = getenv('DB_DATABASE') ?: 'veterinaria_db';
            $usuario = getenv('DB_USERNAME') ?: 'root';
            $clave   = getenv('DB_PASSWORD') ?: '';
        }

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$bd;charset=utf8mb4";
            $opciones = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            // Soporte SSL opcional si el proveedor cloud (ej. Aiven/CleverCloud) lo requiere
            if (getenv('DB_SSL') === 'true') {
                $opciones[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
            $pdo = new PDO($dsn, $usuario, $clave, $opciones);
            return $pdo;
        } catch (PDOException $e) {
            die("Error de conexión a MySQL: " . $e->getMessage());
        }
    }
}
?>
