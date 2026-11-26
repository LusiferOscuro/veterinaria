<?php
class Conexion {
    private static $pdo = null;

    public static function conectar() {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dbUrl  = getenv('DATABASE_URL');
        $dbHost = getenv('DB_HOST');

        try {
            if ($dbUrl || $dbHost) {
                // Modo MySQL externo (cuando se configuran variables en Render)
                if ($dbUrl) {
                    $parts   = parse_url($dbUrl);
                    $host    = $parts['host'] ?? '127.0.0.1';
                    $port    = $parts['port'] ?? '3306';
                    $bd      = isset($parts['path']) ? ltrim($parts['path'], '/') : 'veterinaria_db';
                    $usuario = $parts['user'] ?? 'root';
                    $clave   = $parts['pass'] ?? '';
                } else {
                    $host    = $dbHost;
                    $port    = getenv('DB_PORT')     ?: '3306';
                    $bd      = getenv('DB_DATABASE') ?: 'veterinaria_db';
                    $usuario = getenv('DB_USERNAME') ?: 'root';
                    $clave   = getenv('DB_PASSWORD') ?: '';
                }

                $dsn = "mysql:host=$host;port=$port;dbname=$bd;charset=utf8mb4";
                $opciones = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ];
                if (getenv('DB_SSL') === 'true') {
                    $opciones[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                }
                self::$pdo = new PDO($dsn, $usuario, $clave, $opciones);
            } else {
                // Modo autónomo integrado (SQLite) para funcionar directo en Render sin servidor MySQL externo
                $dataDir = __DIR__ . '/../data';
                if (!is_dir($dataDir)) {
                    mkdir($dataDir, 0777, true);
                }
                $dbFile = $dataDir . '/veterinaria.sqlite';
                $esNueva = !file_exists($dbFile);

                self::$pdo = new PDO('sqlite:' . $dbFile);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$pdo->exec('PRAGMA foreign_keys = ON;');

                if ($esNueva) {
                    self::inicializarSQLite(self::$pdo);
                }
            }
            return self::$pdo;
        } catch (PDOException $e) {
            die("Error de conexión a la base de datos: " . $e->getMessage());
        }
    }

    private static function inicializarSQLite(PDO $pdo) {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS usuarios (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre    TEXT NOT NULL,
  usuario   TEXT NOT NULL UNIQUE,
  clave     TEXT NOT NULL,
  rol       TEXT NOT NULL CHECK(rol IN ('veterinario','secretaria')),
  telefono  TEXT NULL,
  activo    INTEGER NOT NULL DEFAULT 1,
  creado    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mascotas (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre    TEXT NOT NULL,
  especie   TEXT NOT NULL,
  raza      TEXT NULL,
  sexo      TEXT NOT NULL CHECK(sexo IN ('Macho','Hembra')),
  edad      REAL NOT NULL DEFAULT 0,
  peso      REAL NOT NULL DEFAULT 0,
  dueno     TEXT NOT NULL,
  telefono  TEXT NULL,
  registro  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS servicios (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre      TEXT NOT NULL,
  descripcion TEXT NULL,
  precio      REAL NOT NULL DEFAULT 0,
  activo      INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS metodos_pago (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre      TEXT NOT NULL,
  descripcion TEXT NULL,
  activo      INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS tratamientos (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  mascota_id     INTEGER NOT NULL,
  servicio_id    INTEGER NOT NULL,
  metodo_pago_id INTEGER NULL,
  usuario_id     INTEGER NULL,
  diagnostico    TEXT NOT NULL,
  indicaciones   TEXT NULL,
  costo          REAL NOT NULL DEFAULT 0,
  estado         TEXT NOT NULL DEFAULT 'En curso' CHECK(estado IN ('En curso','Finalizado','Cancelado')),
  pagado         INTEGER NOT NULL DEFAULT 0,
  fecha          DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mascota_id)     REFERENCES mascotas(id),
  FOREIGN KEY (servicio_id)    REFERENCES servicios(id),
  FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago(id),
  FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)
);

CREATE TABLE IF NOT EXISTS historial (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  mascota_id      INTEGER NOT NULL,
  servicio_id     INTEGER NULL,
  usuario_id      INTEGER NULL,
  tratamiento     TEXT NOT NULL,
  volver_cantidad INTEGER NOT NULL DEFAULT 1,
  volver_unidad   TEXT NOT NULL DEFAULT 'Días' CHECK(volver_unidad IN ('Días','Semanas','Meses')),
  fecha_retorno   TEXT NOT NULL,
  descripcion     TEXT NULL,
  fecha           DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mascota_id)  REFERENCES mascotas(id),
  FOREIGN KEY (servicio_id) REFERENCES servicios(id),
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)
);

INSERT INTO usuarios (nombre, usuario, clave, rol, telefono) VALUES
('Dr. Veterinario', 'admin',      '$2b$12$TkOOxSaaQIeRUgljgM0rM.Hvf4p7QAlam2lNky9Jgb7s9Ogp2eYH2', 'veterinario', '70000001'),
('Secretaria General', 'secretaria', '$2b$12$xUnpdTxgJGDqFysiNVSdmOZFlhTk3E.DdQ/z/03obISZvg.rfI2zq', 'secretaria', '70000002');

INSERT INTO servicios (nombre, descripcion, precio) VALUES
('Consulta general', 'Revisión clínica completa', 50),
('Vacunación', 'Aplicación de vacuna según calendario', 80),
('Desparasitación', 'Tratamiento antiparasitario', 40),
('Cirugía menor', 'Procedimientos quirúrgicos ambulatorios', 300),
('Baño y corte', 'Higiene y estética', 70),
('Radiografía', 'Estudio por imágenes', 150);

INSERT INTO metodos_pago (nombre, descripcion) VALUES
('Efectivo', 'Pago en caja'),
('Transferencia bancaria', 'Transferencia a la cuenta de la clínica'),
('QR', 'Pago mediante código QR'),
('Tarjeta', 'Débito o crédito');

INSERT INTO mascotas (nombre, especie, raza, sexo, edad, peso, dueno, telefono) VALUES
('Firulais', 'Perro', 'Mestizo', 'Macho', 3, 12.50, 'Carlos Pérez', '71234567'),
('Michi',    'Gato',  'Siamés',  'Hembra', 2, 4.20,  'Ana Gómez',   '76543210');

INSERT INTO tratamientos (mascota_id, servicio_id, metodo_pago_id, usuario_id, diagnostico, indicaciones, costo, estado, pagado) VALUES
(1, 2, 1, 1, 'Vacuna antirrábica anual', 'Reposo 24 h, revisar zona de aplicación', 80, 'Finalizado', 1);

INSERT INTO historial (mascota_id, servicio_id, usuario_id, tratamiento, volver_cantidad, volver_unidad, fecha_retorno, descripcion) VALUES
(1, 2, 1, 'Refuerzo de vacuna antirrábica', 1, 'Meses', date('now', '+1 month'), 'Sin reacciones adversas. Volver para control y refuerzo.');
SQL;
        $pdo->exec($sql);
    }
}
?>
