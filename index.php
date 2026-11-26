<?php
session_start();

// ==========================================
// 1. BASE DE DATOS SQLITE AUTÓNOMA
// ==========================================
$dbFile = __DIR__ . '/veterinaria.sqlite';
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Crear tablas si no existen
$db->exec("
CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    nombre TEXT NOT NULL,
    rol TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mascotas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    especie TEXT NOT NULL,
    raza TEXT NOT NULL,
    edad TEXT NOT NULL,
    dueno TEXT NOT NULL,
    telefono TEXT NOT NULL,
    fecha_registro TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS servicios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    descripcion TEXT,
    precio REAL NOT NULL,
    dias_retorno INTEGER DEFAULT 30,
    activo INTEGER DEFAULT 1
);

CREATE TABLE IF NOT EXISTS metodos_pago (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    detalle TEXT,
    activo INTEGER DEFAULT 1
);

CREATE TABLE IF NOT EXISTS tratamientos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mascota_id INTEGER NOT NULL,
    servicio_id INTEGER NOT NULL,
    metodo_pago_id INTEGER NOT NULL,
    diagnostico TEXT NOT NULL,
    medicacion TEXT,
    monto REAL NOT NULL,
    estado_pago TEXT NOT NULL DEFAULT 'Pendiente',
    fecha TEXT NOT NULL,
    FOREIGN KEY (mascota_id) REFERENCES mascotas(id),
    FOREIGN KEY (servicio_id) REFERENCES servicios(id),
    FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago(id)
);

CREATE TABLE IF NOT EXISTS historial (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mascota_id INTEGER NOT NULL,
    fecha_atencion TEXT NOT NULL,
    motivo TEXT NOT NULL,
    observaciones TEXT,
    fecha_retorno TEXT NOT NULL,
    atendido_por TEXT NOT NULL,
    FOREIGN KEY (mascota_id) REFERENCES mascotas(id)
);
");

// Datos iniciales (Seed)
if ((int)$db->query("SELECT COUNT(*) FROM usuarios")->fetchColumn() === 0) {
    $db->exec("
        INSERT INTO usuarios (usuario, password, nombre, rol) VALUES
        ('admin', 'admin123', 'Dr. Veterinario', 'Veterinario'),
        ('secretaria', 'secre123', 'Lic. Ana Pérez', 'Secretaria');
    ");
}

if ((int)$db->query("SELECT COUNT(*) FROM servicios")->fetchColumn() === 0) {
    $db->exec("
        INSERT INTO servicios (nombre, descripcion, precio, dias_retorno, activo) VALUES
        ('Consulta General', 'Evaluación clínica completa y signos vitales', 80.00, 30, 1),
        ('Vacunación Polivalente', 'Inmunización anual contra moquillo, parvovirus y hepatitis', 120.00, 365, 1),
        ('Vacuna Antirrábica', 'Dosis anual obligatoria contra la rabia', 70.00, 365, 1),
        ('Desparasitación Interna/Externa', 'Control antiparasitario según peso', 65.00, 90, 1),
        ('Curación y Sutura Menor', 'Limpieza de heridas y vendaje', 150.00, 7, 1),
        ('Baño Medicado y Peluquería', 'Higiene dermatológica y corte sanitario', 90.00, 30, 1);
    ");
}

if ((int)$db->query("SELECT COUNT(*) FROM metodos_pago")->fetchColumn() === 0) {
    $db->exec("
        INSERT INTO metodos_pago (nombre, detalle, activo) VALUES
        ('Pago por QR (altoke)', 'CTA: 3170361-000-001 | Válido hasta: 11 de enero de 2028', 1),
        ('Efectivo (Bs.)', 'Pago directo en caja de recepción', 1),
        ('Transferencia Bancaria', 'Transferencia directa a cuenta de la clínica', 1),
        ('Tarjeta Débito / Crédito', 'POS físico en clínica', 1);
    ");
}

if ((int)$db->query("SELECT COUNT(*) FROM mascotas")->fetchColumn() === 0) {
    $hoy = date('Y-m-d');
    $retorno = date('Y-m-d', strtotime('+30 days'));
    $db->exec("
        INSERT INTO mascotas (nombre, especie, raza, edad, dueno, telefono, fecha_registro) VALUES
        ('Firulais', 'Perro', 'Mestizo', '3 años', 'Carlos Mendoza', '76091506', '$hoy'),
        ('Michi', 'Gato', 'Siamés', '1 año', 'Lucía Vargas', '71234567', '$hoy');
    ");
    $db->exec("
        INSERT INTO historial (mascota_id, fecha_atencion, motivo, observaciones, fecha_retorno, atendido_por) VALUES
        (1, '$hoy', 'Registro inicial en sistema', 'Apertura automática de ficha clínica al registrar mascota.', '$retorno', 'Dr. Veterinario'),
        (2, '$hoy', 'Registro inicial en sistema', 'Apertura automática de ficha clínica al registrar mascota.', '$retorno', 'Dr. Veterinario');
    ");
}

// ==========================================
// 2. AUTENTICACIÓN Y CONTROL DE RUTAS
// ==========================================
$mensaje = '';
$tipoMensaje = 'success';
$qrRecienCreado = null;

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'login') {
    $user = trim($_POST['usuario'] ?? '');
    $pass = trim($_POST['password'] ?? '');
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE usuario = ? AND password = ?");
    $stmt->execute([$user, $pass]);
    $u = $stmt->fetch();
    if ($u) {
        $_SESSION['user'] = $u;
        header('Location: ?c=inicio');
        exit;
    } else {
        $mensaje = 'Usuario o contraseña incorrectos.';
        $tipoMensaje = 'error';
    }
}

$usuarioActual = $_SESSION['user'] ?? null;
$esVet = $usuarioActual && $usuarioActual['rol'] === 'Veterinario';

// ==========================================
// 3. PROCESAMIENTO DE ACCIONES POST
// ==========================================
if ($usuarioActual && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // 3.1 REGISTRAR MASCOTA + INSERCIÓN AUTOMÁTICA EN HISTORIAL
    if ($accion === 'crear_mascota') {
        $nombre = trim($_POST['nombre']);
        $especie = trim($_POST['especie']);
        $raza = trim($_POST['raza']);
        $edad = trim($_POST['edad']);
        $dueno = trim($_POST['dueno']);
        $telefono = trim($_POST['telefono']);
        $hoy = date('Y-m-d');
        $retorno = date('Y-m-d', strtotime('+30 days'));

        $stmt = $db->prepare("INSERT INTO mascotas (nombre, especie, raza, edad, dueno, telefono, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nombre, $especie, $raza, $edad, $dueno, $telefono, $hoy]);
        $nuevaMascotaId = $db->lastInsertId();

        // Inserción automática en historial clínico
        $motivoAuto = "Registro inicial de mascota ($especie - $raza)";
        $obsAuto = "Ingreso automático al historial clínico. Propietario: $dueno (Tel: $telefono).";
        $stmtHist = $db->prepare("INSERT INTO historial (mascota_id, fecha_atencion, motivo, observaciones, fecha_retorno, atendido_por) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtHist->execute([$nuevaMascotaId, $hoy, $motivoAuto, $obsAuto, $retorno, $usuarioActual['nombre']]);

        $mensaje = "Mascota '$nombre' registrada y agregada automáticamente al Historial Clínico.";
    }

    // 3.2 CREAR TRATAMIENTO (CON DETECCIÓN DE PAGO POR QR)
    if ($accion === 'crear_tratamiento') {
        $mascota_id = (int)$_POST['mascota_id'];
        $servicio_id = (int)$_POST['servicio_id'];
        $metodo_pago_id = (int)$_POST['metodo_pago_id'];
        $diagnostico = trim($_POST['diagnostico']);
        $medicacion = trim($_POST['medicacion']);
        $estado_pago = $_POST['estado_pago'] ?? 'Pendiente';
        $hoy = date('Y-m-d');

        $srv = $db->query("SELECT * FROM servicios WHERE id = $servicio_id")->fetch();
        $met = $db->query("SELECT * FROM metodos_pago WHERE id = $metodo_pago_id")->fetch();
        $mas = $db->query("SELECT * FROM mascotas WHERE id = $mascota_id")->fetch();

        $monto = $srv ? (float)$srv['precio'] : 0;
        $diasRetorno = $srv ? (int)$srv['dias_retorno'] : 15;
        $fechaRetorno = date('Y-m-d', strtotime("+$diasRetorno days"));

        $stmt = $db->prepare("INSERT INTO tratamientos (mascota_id, servicio_id, metodo_pago_id, diagnostico, medicacion, monto, estado_pago, fecha) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$mascota_id, $servicio_id, $metodo_pago_id, $diagnostico, $medicacion, $monto, $estado_pago, $hoy]);

        // Registrar también evolución en historial
        $stmtHist = $db->prepare("INSERT INTO historial (mascota_id, fecha_atencion, motivo, observaciones, fecha_retorno, atendido_por) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtHist->execute([
            $mascota_id,
            $hoy,
            "Tratamiento: " . ($srv['nombre'] ?? 'Atención'),
            "Diagnóstico: $diagnostico | Medicación: $medicacion | Pago: " . ($met['nombre'] ?? ''),
            $fechaRetorno,
            $usuarioActual['nombre']
        ]);

        if ($met && stripos($met['nombre'], 'QR') !== false) {
            $qrRecienCreado = [
                'mascota' => $mas['nombre'] ?? 'Paciente',
                'servicio' => $srv['nombre'] ?? 'Tratamiento',
                'monto' => number_format($monto, 2),
                'estado' => $estado_pago
            ];
            $mensaje = "Tratamiento registrado con Pago por QR. Escanea el código altoke abajo para completar el cobro.";
        } else {
            $mensaje = "Tratamiento registrado correctamente y vinculado al historial.";
        }
    }

    // 3.3 MARCAR TRATAMIENTO COMO PAGADO
    if ($accion === 'pagar_tratamiento') {
        $tid = (int)$_POST['tratamiento_id'];
        $db->prepare("UPDATE tratamientos SET estado_pago = 'Pagado' WHERE id = ?")->execute([$tid]);
        $mensaje = "Pago confirmado y actualizado a Pagado.";
    }

    // 3.4 AGREGAR ENTRADA MANUAL AL HISTORIAL
    if ($accion === 'crear_historial') {
        $mascota_id = (int)$_POST['mascota_id'];
        $motivo = trim($_POST['motivo']);
        $observaciones = trim($_POST['observaciones']);
        $dias = max(1, (int)($_POST['dias_retorno'] ?? 15));
        $hoy = date('Y-m-d');
        $retorno = date('Y-m-d', strtotime("+$dias days"));

        $stmt = $db->prepare("INSERT INTO historial (mascota_id, fecha_atencion, motivo, observaciones, fecha_retorno, atendido_por) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$mascota_id, $hoy, $motivo, $observaciones, $retorno, $usuarioActual['nombre']]);
        $mensaje = "Registro clínico agregado. Próximo control calculado para: $retorno.";
    }

    // 3.5 ACCIONES EXCLUSIVAS DE VETERINARIO (SERVICIOS, MÉTODOS DE PAGO, PERSONAL)
    if ($esVet && $accion === 'crear_servicio') {
        $stmt = $db->prepare("INSERT INTO servicios (nombre, descripcion, precio, dias_retorno, activo) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([trim($_POST['nombre']), trim($_POST['descripcion']), (float)$_POST['precio'], (int)$_POST['dias_retorno']]);
        $mensaje = "Servicio agregado al catálogo.";
    }

    if ($esVet && $accion === 'crear_metodo_pago') {
        $stmt = $db->prepare("INSERT INTO metodos_pago (nombre, detalle, activo) VALUES (?, ?, 1)");
        $stmt->execute([trim($_POST['nombre']), trim($_POST['detalle'])]);
        $mensaje = "Método de pago registrado.";
    }

    if ($esVet && $accion === 'crear_usuario') {
        $stmt = $db->prepare("INSERT INTO usuarios (usuario, password, nombre, rol) VALUES (?, ?, ?, ?)");
        $stmt->execute([trim($_POST['usuario']), trim($_POST['password']), trim($_POST['nombre']), $_POST['rol']]);
        $mensaje = "Cuenta de personal creada.";
    }
}

// ==========================================
// 4. PANTALLA DE LOGIN SI NO HAY SESIÓN
// ==========================================
if (!$usuarioActual):
    $tabLogin = ($_GET['c'] ?? '') === 'secretaria' ? 'secretaria' : 'veterinario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso - Sistema de Veterinaria</title>
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; background: linear-gradient(135deg, #00695c 0%, #004d40 100%); display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: #fff; width: 100%; max-width: 420px; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); overflow: hidden; }
        .header { background: #00796b; color: #fff; padding: 26px 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .header p { margin: 6px 0 0; opacity: 0.9; font-size: 14px; }
        .tabs { display: flex; background: #e0f2f1; }
        .tab { flex: 1; text-align: center; padding: 12px; text-decoration: none; color: #004d40; font-weight: 600; font-size: 14px; border-bottom: 3px solid transparent; }
        .tab.active { background: #fff; color: #00796b; border-bottom-color: #00796b; }
        .body { padding: 24px; }
        .field { margin-bottom: 16px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #37474f; margin-bottom: 6px; }
        input { width: 100%; padding: 11px 12px; border: 1px solid #cfd8dc; border-radius: 8px; font-size: 15px; }
        input:focus { outline: none; border-color: #00796b; }
        button { width: 100%; padding: 12px; background: #00796b; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
        button:hover { background: #00695c; }
        .alert { background: #ffebee; color: #c62828; padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .hint { margin-top: 16px; background: #f1f8e9; border: 1px solid #c5e1a5; padding: 10px 12px; border-radius: 8px; font-size: 12px; color: #33691e; }
    </style>
</head>
<body>
<div class="card">
    <div class="header">
        <h1>🐾 Sistema de Veterinaria</h1>
        <p>Gestión Clínica, Tratamientos y Pagos</p>
    </div>
    <div class="tabs">
        <a class="tab <?= $tabLogin === 'veterinario' ? 'active' : '' ?>" href="?c=veterinario">🩺 Veterinario</a>
        <a class="tab <?= $tabLogin === 'secretaria' ? 'active' : '' ?>" href="?c=secretaria">🗂️ Secretaria</a>
    </div>
    <div class="body">
        <?php if ($mensaje): ?>
            <div class="alert"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="accion" value="login">
            <div class="field">
                <label>Usuario</label>
                <input type="text" name="usuario" value="<?= $tabLogin === 'secretaria' ? 'secretaria' : 'admin' ?>" required>
            </div>
            <div class="field">
                <label>Contraseña</label>
                <input type="password" name="password" value="<?= $tabLogin === 'secretaria' ? 'secre123' : 'admin123' ?>" required>
            </div>
            <button type="submit">Ingresar al Sistema</button>
        </form>
        <div class="hint">
            <strong>Credenciales de prueba:</strong><br>
            • Veterinario: <code>admin</code> / <code>admin123</code><br>
            • Secretaria: <code>secretaria</code> / <code>secre123</code>
        </div>
    </div>
</div>
</body>
</html>
<?php
exit;
endif;

// ==========================================
// 5. CONSULTAS PARA VISTAS DEL PANEL
// ==========================================
$vista = $_GET['c'] ?? 'inicio';
$totalMascotas = (int)$db->query("SELECT COUNT(*) FROM mascotas")->fetchColumn();
$tratamientosCurso = (int)$db->query("SELECT COUNT(*) FROM tratamientos WHERE estado_pago = 'Pendiente'")->fetchColumn();
$pagosPendientes = (float)$db->query("SELECT COALESCE(SUM(monto), 0) FROM tratamientos WHERE estado_pago = 'Pendiente'")->fetchColumn();
$serviciosActivos = (int)$db->query("SELECT COUNT(*) FROM servicios WHERE activo = 1")->fetchColumn();

$mascotas = $db->query("SELECT * FROM mascotas ORDER BY id DESC")->fetchAll();
$servicios = $db->query("SELECT * FROM servicios ORDER BY id ASC")->fetchAll();
$metodosPago = $db->query("SELECT * FROM metodos_pago ORDER BY id ASC")->fetchAll();
$tratamientos = $db->query("
    SELECT t.*, m.nombre AS mascota_nombre, m.dueno, s.nombre AS servicio_nombre, mp.nombre AS metodo_nombre
    FROM tratamientos t
    JOIN mascotas m ON t.mascota_id = m.id
    JOIN servicios s ON t.servicio_id = s.id
    JOIN metodos_pago mp ON t.metodo_pago_id = mp.id
    ORDER BY t.id DESC
")->fetchAll();
$historial = $db->query("
    SELECT h.*, m.nombre AS mascota_nombre, m.especie, m.dueno
    FROM historial h
    JOIN mascotas m ON h.mascota_id = m.id
    ORDER BY h.id DESC
")->fetchAll();
$usuarios = $db->query("SELECT * FROM usuarios ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Veterinaria</title>
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { margin: 0; background: #eef3f2; color: #1e293b; }
        .navbar { background: #00796b; color: #fff; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; min-height: 58px; }
        .brand { font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .nav-links { display: flex; gap: 4px; flex-wrap: wrap; }
        .nav-links a { color: #e0f2f1; text-decoration: none; padding: 10px 13px; border-radius: 6px; font-size: 14px; font-weight: 500; }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.18); color: #fff; }
        .user-box { font-size: 13px; display: flex; align-items: center; gap: 12px; }
        .user-box a { color: #ffccbc; text-decoration: none; font-weight: 700; }
        .container { max-width: 1120px; margin: 28px auto; padding: 0 18px; }
        .panel { background: #fff; border-radius: 14px; padding: 26px; box-shadow: 0 4px 18px rgba(0,0,0,0.06); margin-bottom: 24px; }
        h2 { margin-top: 0; color: #00695c; font-size: 22px; }
        h3 { color: #00796b; font-size: 17px; margin-top: 22px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { border: 1.5px solid #b2dfdb; background: #f4fbfa; border-radius: 12px; padding: 20px; text-align: center; }
        .stat-num { font-size: 28px; font-weight: 700; color: #00796b; }
        .stat-label { font-size: 13px; color: #475569; margin-top: 4px; }
        .quick-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
        .quick-btn { display: block; text-align: center; padding: 16px 12px; border: 1.5px solid #b2dfdb; border-radius: 10px; background: #f8fcfb; color: #00695c; text-decoration: none; font-weight: 600; font-size: 14px; }
        .quick-btn:hover { background: #e0f2f1; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; }
        .field { display: flex; flex-direction: column; }
        label { font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 5px; }
        input, select, textarea { padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff; }
        .btn { background: #00796b; color: #fff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px; display: inline-block; text-decoration: none; }
        .btn:hover { background: #00695c; }
        .btn-sm { padding: 6px 11px; font-size: 12px; }
        .btn-qr { background: #ff6f00; color: #fff; }
        .btn-qr:hover { background: #e65100; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 14px; }
        th, td { padding: 11px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { background: #f1f8f7; color: #00695c; font-weight: 600; }
        .badge { padding: 4px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge-ok { background: #dcfce7; color: #166534; }
        .badge-warn { background: #fef3c7; color: #92400e; }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-weight: 500; background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .qr-box { background: #fff8e1; border: 2px solid #ffb300; border-radius: 14px; padding: 20px; text-align: center; max-width: 380px; margin: 16px auto; }
        .qr-brand { font-size: 24px; font-weight: 800; color: #ef6c00; letter-spacing: -0.5px; margin-bottom: 8px; }
        .qr-meta { font-size: 12px; color: #475569; margin-top: 10px; line-height: 1.5; }
        .modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); align-items: center; justify-content: center; z-index: 999; padding: 16px; }
        .modal-card { background: #fff; border-radius: 16px; padding: 24px; max-width: 400px; width: 100%; text-align: center; position: relative; }
        .close-btn { position: absolute; top: 12px; right: 14px; background: #e2e8f0; border: none; border-radius: 50%; width: 30px; height: 30px; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

<div class="navbar">
    <div class="brand">🐾 Veterinaria</div>
    <div class="nav-links">
        <a href="?c=inicio" class="<?= $vista === 'inicio' ? 'active' : '' ?>">Inicio</a>
        <a href="?c=mascotas" class="<?= $vista === 'mascotas' ? 'active' : '' ?>">Mascotas</a>
        <a href="?c=tratamientos" class="<?= $vista === 'tratamientos' ? 'active' : '' ?>">Tratamientos</a>
        <a href="?c=historial" class="<?= $vista === 'historial' ? 'active' : '' ?>">Historial</a>
        <a href="?c=servicios" class="<?= $vista === 'servicios' ? 'active' : '' ?>">Servicios</a>
        <a href="?c=pagos" class="<?= $vista === 'pagos' ? 'active' : '' ?>">Métodos de pago</a>
        <?php if ($esVet): ?>
            <a href="?c=personal" class="<?= $vista === 'personal' ? 'active' : '' ?>">Secretaria</a>
        <?php endif; ?>
    </div>
    <div class="user-box">
        <span><?= htmlspecialchars($usuarioActual['nombre']) ?> (<?= htmlspecialchars($usuarioActual['rol']) ?>)</span>
        <a href="?logout=1">Salir</a>
    </div>
</div>

<div class="container">
    <?php if ($mensaje): ?>
        <div class="alert">✅ <?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <!-- INICIO -->
    <?php if ($vista === 'inicio'): ?>
    <div class="panel">
        <h2>Bienvenido/a, <?= htmlspecialchars($usuarioActual['nombre']) ?></h2>
        <div class="stats">
            <div class="stat-card">
                <div class="stat-num"><?= $totalMascotas ?></div>
                <div class="stat-label">Mascotas registradas</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= $tratamientosCurso ?></div>
                <div class="stat-label">Tratamientos en curso</div>
            </div>
            <div class="stat-card">
                <div class="stat-num">Bs. <?= number_format($pagosPendientes, 2) ?></div>
                <div class="stat-label">Pagos pendientes</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= $serviciosActivos ?></div>
                <div class="stat-label">Servicios activos</div>
            </div>
        </div>

        <h3>Accesos rápidos</h3>
        <div class="quick-grid">
            <a class="quick-btn" href="?c=mascotas">🐶 Registrar mascota</a>
            <a class="quick-btn" href="?c=tratamientos">💊 Nuevo tratamiento</a>
            <a class="quick-btn" href="?c=historial">📋 Historial clínico</a>
            <a class="quick-btn" href="?c=servicios">🩺 Servicios</a>
            <a class="quick-btn" href="?c=pagos">💳 Métodos de pago</a>
            <?php if ($esVet): ?>
                <a class="quick-btn" href="?c=personal">🧑‍💼 Secretaria</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- MASCOTAS (REGISTRO AUTOMÁTICO EN HISTORIAL) -->
    <?php elseif ($vista === 'mascotas'): ?>
    <div class="panel">
        <h2>🐶 Registro de Mascotas</h2>
        <p style="font-size:13px; color:#475569; margin-top:-8px;">Al guardar una mascota nueva, se genera automáticamente su registro inicial en el <strong>Historial Clínico</strong>.</p>
        <form method="POST" class="form-grid">
            <input type="hidden" name="accion" value="crear_mascota">
            <div class="field"><label>Nombre de mascota</label><input type="text" name="nombre" placeholder="Ej. Toby" required></div>
            <div class="field"><label>Especie</label>
                <select name="especie">
                    <option value="Perro">Perro</option>
                    <option value="Gato">Gato</option>
                    <option value="Ave">Ave</option>
                    <option value="Otro">Otro</option>
                </select>
            </div>
            <div class="field"><label>Raza</label><input type="text" name="raza" placeholder="Ej. Labrador" required></div>
            <div class="field"><label>Edad</label><input type="text" name="edad" placeholder="Ej. 2 años" required></div>
            <div class="field"><label>Propietario / Dueño</label><input type="text" name="dueno" placeholder="Nombre completo" required></div>
            <div class="field"><label>Teléfono</label><input type="text" name="telefono" placeholder="7XXXXXXX" required></div>
            <div class="field" style="justify-content:flex-end;"><button type="submit" class="btn">Guardar Mascota + Historial</button></div>
        </form>

        <table>
            <thead>
                <tr><th>ID</th><th>Mascota</th><th>Especie / Raza</th><th>Edad</th><th>Propietario</th><th>Teléfono</th><th>Fecha Registro</th></tr>
            </thead>
            <tbody>
                <?php foreach ($mascotas as $m): ?>
                <tr>
                    <td>#<?= $m['id'] ?></td>
                    <td><strong><?= htmlspecialchars($m['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($m['especie']) ?> - <?= htmlspecialchars($m['raza']) ?></td>
                    <td><?= htmlspecialchars($m['edad']) ?></td>
                    <td><?= htmlspecialchars($m['dueno']) ?></td>
                    <td><?= htmlspecialchars($m['telefono']) ?></td>
                    <td><?= htmlspecialchars($m['fecha_registro']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- TRATAMIENTOS + PAGO POR QR -->
    <?php elseif ($vista === 'tratamientos'): ?>
    <div class="panel">
        <h2>💊 Gestión de Tratamientos y Cobros</h2>

        <?php if ($qrRecienCreado): ?>
        <div class="qr-box">
            <div class="qr-brand">altoke</div>
            <div style="font-size:14px; font-weight:600; margin-bottom:10px;">
                Cobro de Tratamiento: <?= htmlspecialchars($qrRecienCreado['servicio']) ?><br>
                Paciente: <?= htmlspecialchars($qrRecienCreado['mascota']) ?> — <strong>Bs. <?= $qrRecienCreado['monto'] ?></strong>
            </div>
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=210x210&data=ALTOKE%7CCTA:3170361-000-001%7CMONTO:<?= urlencode($qrRecienCreado['monto']) ?>BS%7CVETERINARIA" alt="QR altoke" style="border-radius:10px; border:4px solid #fff; box-shadow:0 2px 10px rgba(0,0,0,0.12);">
            <div class="qr-meta">
                <strong>Válido hasta:</strong> 11 de enero de 2028<br>
                <strong>CTA:</strong> 3170361-000-001
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" class="form-grid" id="formTratamiento">
            <input type="hidden" name="accion" value="crear_tratamiento">
            <div class="field">
                <label>Mascota</label>
                <select name="mascota_id" required>
                    <?php foreach ($mascotas as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nombre']) ?> (<?= htmlspecialchars($m['dueno']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Servicio / Tratamiento</label>
                <select name="servicio_id" id="selectServicio" onchange="actualizarVistaQR()" required>
                    <?php foreach ($servicios as $s): ?>
                        <option value="<?= $s['id'] ?>" data-precio="<?= number_format($s['precio'], 2) ?>"><?= htmlspecialchars($s['nombre']) ?> (Bs. <?= number_format($s['precio'], 2) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Método de Pago</label>
                <select name="metodo_pago_id" id="selectMetodoPago" onchange="actualizarVistaQR()" required>
                    <?php foreach ($metodosPago as $mp): ?>
                        <option value="<?= $mp['id'] ?>" data-nombre="<?= htmlspecialchars($mp['nombre']) ?>"><?= htmlspecialchars($mp['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Estado de Pago</label>
                <select name="estado_pago">
                    <option value="Pendiente">Pendiente</option>
                    <option value="Pagado">Pagado</option>
                </select>
            </div>
            <div class="field"><label>Diagnóstico</label><input type="text" name="diagnostico" placeholder="Ej. Otitis leve / Control" required></div>
            <div class="field"><label>Medicación indicada</label><input type="text" name="medicacion" placeholder="Ej. Gotas óticas c/12h" required></div>
            <div class="field" style="justify-content:flex-end;"><button type="submit" class="btn">Registrar Tratamiento</button></div>
        </form>

        <!-- Vista previa instantánea del QR al seleccionar opción QR -->
        <div id="previewQRBox" class="qr-box" style="display:none; margin-top:20px;">
            <div class="qr-brand">altoke</div>
            <div style="font-size:13px; font-weight:600; margin-bottom:8px;">
                Escanea el QR para pagar el tratamiento (<span id="qrMontoLive">Bs. 80.00</span>)
            </div>
            <img id="qrImgLive" src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=ALTOKE%7CCTA:3170361-000-001%7CVETERINARIA" alt="QR altoke" style="border-radius:10px; border:4px solid #fff; box-shadow:0 2px 10px rgba(0,0,0,0.12);">
            <div class="qr-meta">
                <strong>Válido hasta:</strong> 11 de enero de 2028<br>
                <strong>CTA:</strong> 3170361-000-001
            </div>
        </div>

        <table>
            <thead>
                <tr><th>Fecha</th><th>Mascota</th><th>Tratamiento</th><th>Diagnóstico</th><th>Método Pago</th><th>Monto</th><th>Estado</th><th>Acciones</th></tr>
            </thead>
            <tbody>
                <?php foreach ($tratamientos as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['fecha']) ?></td>
                    <td><strong><?= htmlspecialchars($t['mascota_nombre']) ?></strong><br><small><?= htmlspecialchars($t['dueno']) ?></small></td>
                    <td><?= htmlspecialchars($t['servicio_nombre']) ?></td>
                    <td><?= htmlspecialchars($t['diagnostico']) ?><br><small style="color:#64748b;">Rx: <?= htmlspecialchars($t['medicacion']) ?></small></td>
                    <td><?= htmlspecialchars($t['metodo_nombre']) ?></td>
                    <td><strong>Bs. <?= number_format($t['monto'], 2) ?></strong></td>
                    <td>
                        <?php if ($t['estado_pago'] === 'Pagado'): ?>
                            <span class="badge badge-ok">Pagado</span>
                        <?php else: ?>
                            <span class="badge badge-warn">Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td style="display:flex; gap:6px; flex-wrap:wrap;">
                        <?php if (stripos($t['metodo_nombre'], 'QR') !== false): ?>
                            <button type="button" class="btn btn-sm btn-qr" onclick="abrirModalQR('<?= htmlspecialchars($t['mascota_nombre'], ENT_QUOTES) ?>', '<?= htmlspecialchars($t['servicio_nombre'], ENT_QUOTES) ?>', '<?= number_format($t['monto'], 2) ?>')">Ver QR</button>
                        <?php endif; ?>
                        <?php if ($t['estado_pago'] !== 'Pagado'): ?>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="accion" value="pagar_tratamiento">
                                <input type="hidden" name="tratamiento_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-sm">Confirmar Pago</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- HISTORIAL CLÍNICO -->
    <?php elseif ($vista === 'historial'): ?>
    <div class="panel">
        <h2>📋 Historial Clínico y Control de Retorno</h2>
        <form method="POST" class="form-grid">
            <input type="hidden" name="accion" value="crear_historial">
            <div class="field">
                <label>Mascota</label>
                <select name="mascota_id" required>
                    <?php foreach ($mascotas as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nombre']) ?> (<?= htmlspecialchars($m['dueno']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Motivo de consulta</label><input type="text" name="motivo" placeholder="Ej. Control post-vacuna" required></div>
            <div class="field"><label>Observaciones clínicas</label><input type="text" name="observaciones" placeholder="Evolución favorable" required></div>
            <div class="field"><label>Próximo retorno (días)</label><input type="number" name="dias_retorno" value="15" min="1" required></div>
            <div class="field" style="justify-content:flex-end;"><button type="submit" class="btn">Agregar al Historial</button></div>
        </form>

        <table>
            <thead>
                <tr><th>Fecha Atención</th><th>Mascota</th><th>Motivo</th><th>Observaciones</th><th>Próximo Retorno</th><th>Atendido por</th></tr>
            </thead>
            <tbody>
                <?php foreach ($historial as $h): ?>
                <tr>
                    <td><?= htmlspecialchars($h['fecha_atencion']) ?></td>
                    <td><strong><?= htmlspecialchars($h['mascota_nombre']) ?></strong> (<?= htmlspecialchars($h['especie']) ?>)<br><small><?= htmlspecialchars($h['dueno']) ?></small></td>
                    <td><?= htmlspecialchars($h['motivo']) ?></td>
                    <td><?= htmlspecialchars($h['observaciones']) ?></td>
                    <td><span class="badge badge-ok"><?= htmlspecialchars($h['fecha_retorno']) ?></span></td>
                    <td><?= htmlspecialchars($h['atendido_por']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- SERVICIOS -->
    <?php elseif ($vista === 'servicios'): ?>
    <div class="panel">
        <h2>🩺 Catálogo de Servicios Veterinarios</h2>
        <?php if ($esVet): ?>
        <form method="POST" class="form-grid">
            <input type="hidden" name="accion" value="crear_servicio">
            <div class="field"><label>Nombre del servicio</label><input type="text" name="nombre" required></div>
            <div class="field"><label>Descripción</label><input type="text" name="descripcion" required></div>
            <div class="field"><label>Precio (Bs.)</label><input type="number" step="0.01" name="precio" required></div>
            <div class="field"><label>Días para retorno</label><input type="number" name="dias_retorno" value="30" required></div>
            <div class="field" style="justify-content:flex-end;"><button type="submit" class="btn">Agregar Servicio</button></div>
        </form>
        <?php endif; ?>

        <table>
            <thead><tr><th>Servicio</th><th>Descripción</th><th>Tarifa</th><th>Retorno Sugerido</th></tr></thead>
            <tbody>
                <?php foreach ($servicios as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($s['descripcion']) ?></td>
                    <td>Bs. <?= number_format($s['precio'], 2) ?></td>
                    <td>Cada <?= (int)$s['dias_retorno'] ?> días</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- MÉTODOS DE PAGO -->
    <?php elseif ($vista === 'pagos'): ?>
    <div class="panel">
        <h2>💳 Métodos de Pago Habilitados</h2>
        <?php if ($esVet): ?>
        <form method="POST" class="form-grid">
            <input type="hidden" name="accion" value="crear_metodo_pago">
            <div class="field"><label>Nombre del método</label><input type="text" name="nombre" placeholder="Ej. Tigo Money / QR" required></div>
            <div class="field"><label>Detalle / Número de cuenta</label><input type="text" name="detalle" placeholder="Datos para cobro" required></div>
            <div class="field" style="justify-content:flex-end;"><button type="submit" class="btn">Agregar Método</button></div>
        </form>
        <?php endif; ?>

        <table>
            <thead><tr><th>Método</th><th>Detalle / Cuenta</th><th>Vista Rápida</th></tr></thead>
            <tbody>
                <?php foreach ($metodosPago as $mp): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($mp['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($mp['detalle']) ?></td>
                    <td>
                        <?php if (stripos($mp['nombre'], 'QR') !== false): ?>
                            <button type="button" class="btn btn-sm btn-qr" onclick="abrirModalQR('Consulta / Tratamiento', 'Cobro Directo', 'Según servicio')">Mostrar QR altoke</button>
                        <?php else: ?>
                            <span class="badge badge-ok">Activo</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- PERSONAL / SECRETARIA (SOLO VETERINARIO) -->
    <?php elseif ($vista === 'personal' && $esVet): ?>
    <div class="panel">
        <h2>🧑‍💼 Gestión de Personal (Secretaria y Veterinarios)</h2>
        <form method="POST" class="form-grid">
            <input type="hidden" name="accion" value="crear_usuario">
            <div class="field"><label>Nombre completo</label><input type="text" name="nombre" required></div>
            <div class="field"><label>Usuario</label><input type="text" name="usuario" required></div>
            <div class="field"><label>Contraseña</label><input type="text" name="password" required></div>
            <div class="field"><label>Rol</label>
                <select name="rol">
                    <option value="Secretaria">Secretaria</option>
                    <option value="Veterinario">Veterinario</option>
                </select>
            </div>
            <div class="field" style="justify-content:flex-end;"><button type="submit" class="btn">Crear Cuenta</button></div>
        </form>

        <table>
            <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th></tr></thead>
            <tbody>
                <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['nombre']) ?></td>
                    <td><code><?= htmlspecialchars($u['usuario']) ?></code></td>
                    <td><span class="badge badge-ok"><?= htmlspecialchars($u['rol']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL QR ALTOKE -->
<div class="modal-bg" id="modalQR">
    <div class="modal-card">
        <button type="button" class="close-btn" onclick="document.getElementById('modalQR').style.display='none'">×</button>
        <div class="qr-brand">altoke</div>
        <p id="modalQRInfo" style="font-size:14px; font-weight:600; margin:6px 0 12px;"></p>
        <img id="modalQRImg" src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=ALTOKE%7CCTA:3170361-000-001%7CVETERINARIA" alt="QR altoke" style="border-radius:10px; border:4px solid #fff; box-shadow:0 2px 12px rgba(0,0,0,0.15);">
        <div class="qr-meta">
            <strong>Válido hasta:</strong> 11 de enero de 2028<br>
            <strong>CTA:</strong> 3170361-000-001
        </div>
    </div>
</div>

<script>
function actualizarVistaQR() {
    const selMetodo = document.getElementById('selectMetodoPago');
    const selServ = document.getElementById('selectServicio');
    const box = document.getElementById('previewQRBox');
    if (!selMetodo || !box) return;

    const optMetodo = selMetodo.options[selMetodo.selectedIndex];
    const nombreMetodo = (optMetodo.getAttribute('data-nombre') || '').toUpperCase();
    const optServ = selServ.options[selServ.selectedIndex];
    const precio = optServ ? optServ.getAttribute('data-precio') : '0.00';

    if (nombreMetodo.includes('QR')) {
        box.style.display = 'block';
        document.getElementById('qrMontoLive').textContent = 'Bs. ' + precio;
        document.getElementById('qrImgLive').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent('ALTOKE|CTA:3170361-000-001|MONTO:' + precio + 'BS|VETERINARIA');
    } else {
        box.style.display = 'none';
    }
}

function abrirModalQR(mascota, servicio, monto) {
    document.getElementById('modalQRInfo').innerHTML = mascota + '<br>' + servicio + ' — Bs. ' + monto;
    document.getElementById('modalQRImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent('ALTOKE|CTA:3170361-000-001|PACIENTE:' + mascota + '|MONTO:' + monto + 'BS');
    document.getElementById('modalQR').style.display = 'flex';
}

document.addEventListener('DOMContentLoaded', actualizarVistaQR);
</script>
</body>
</html>
