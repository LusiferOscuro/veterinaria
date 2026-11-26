<?php
session_start();

// 1. BASE DE DATOS SQLITE INTEGRADA (NO DEPENDE DE CARPETAS EXTERNAS)
$dbFile  = __DIR__ . '/veterinaria.sqlite';
$esNueva = !file_exists($dbFile);
$pdo     = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON;');

if ($esNueva) {
    $pdo->exec("
    CREATE TABLE usuarios (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      nombre TEXT NOT NULL,
      usuario TEXT NOT NULL UNIQUE,
      clave TEXT NOT NULL,
      rol TEXT NOT NULL,
      telefono TEXT NULL,
      activo INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE mascotas (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      nombre TEXT NOT NULL,
      especie TEXT NOT NULL,
      raza TEXT NULL,
      sexo TEXT NOT NULL,
      edad REAL NOT NULL DEFAULT 0,
      peso REAL NOT NULL DEFAULT 0,
      dueno TEXT NOT NULL,
      telefono TEXT NULL,
      registro DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE servicios (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      nombre TEXT NOT NULL,
      descripcion TEXT NULL,
      precio REAL NOT NULL DEFAULT 0,
      activo INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE metodos_pago (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      nombre TEXT NOT NULL,
      descripcion TEXT NULL,
      activo INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE tratamientos (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      mascota_id INTEGER NOT NULL,
      servicio_id INTEGER NOT NULL,
      metodo_pago_id INTEGER NULL,
      usuario_id INTEGER NULL,
      diagnostico TEXT NOT NULL,
      indicaciones TEXT NULL,
      costo REAL NOT NULL DEFAULT 0,
      estado TEXT NOT NULL DEFAULT 'En curso',
      pagado INTEGER NOT NULL DEFAULT 0,
      fecha DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE historial (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      mascota_id INTEGER NOT NULL,
      servicio_id INTEGER NULL,
      usuario_id INTEGER NULL,
      tratamiento TEXT NOT NULL,
      volver_cantidad INTEGER NOT NULL DEFAULT 1,
      volver_unidad TEXT NOT NULL DEFAULT 'Días',
      fecha_retorno TEXT NOT NULL,
      descripcion TEXT NULL,
      fecha DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    INSERT INTO servicios (nombre, descripcion, precio) VALUES
      ('Consulta general', 'Revisión clínica completa', 50),
      ('Vacunación', 'Aplicación de vacuna según calendario', 80),
      ('Desparasitación', 'Tratamiento antiparasitario', 40),
      ('Cirugía menor', 'Procedimientos quirúrgicos ambulatorios', 300),
      ('Baño y corte', 'Higiene y estética', 70),
      ('Radiografía', 'Estudio por imágenes', 150);
    INSERT INTO metodos_pago (nombre, descripcion) VALUES
      ('Efectivo', 'Pago en caja'),
      ('Transferencia bancaria', 'Transferencia a cuenta de la clínica'),
      ('QR', 'Pago mediante código QR'),
      ('Tarjeta', 'Débito o crédito');
    INSERT INTO mascotas (nombre, especie, raza, sexo, edad, peso, dueno, telefono) VALUES
      ('Firulais', 'Perro', 'Mestizo', 'Macho', 3, 12.50, 'Carlos Pérez', '71234567'),
      ('Michi', 'Gato', 'Siamés', 'Hembra', 2, 4.20, 'Ana Gómez', '76543210');
    ");
    $stU = $pdo->prepare("INSERT INTO usuarios (nombre, usuario, clave, rol, telefono) VALUES (?, ?, ?, ?, ?)");
    $stU->execute(['Dr. Veterinario', 'admin', password_hash('admin123', PASSWORD_DEFAULT), 'veterinario', '70000001']);
    $stU->execute(['Secretaria General', 'secretaria', password_hash('secre123', PASSWORD_DEFAULT), 'secretaria', '70000002']);
    $pdo->exec("INSERT INTO tratamientos (mascota_id, servicio_id, metodo_pago_id, usuario_id, diagnostico, indicaciones, costo, estado, pagado)
                VALUES (1, 2, 1, 1, 'Vacuna antirrábica anual', 'Reposo 24 h, revisar zona de aplicación', 80, 'Finalizado', 1)");
    $pdo->exec("INSERT INTO historial (mascota_id, servicio_id, usuario_id, tratamiento, volver_cantidad, volver_unidad, fecha_retorno, descripcion)
                VALUES (1, 2, 1, 'Refuerzo de vacuna antirrábica', 1, 'Meses', date('now', '+1 month'), 'Sin reacciones adversas. Volver para control.')");
}

// 2. FUNCIONES AUXILIARES Y SEGURIDAD
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function flash($tipo, $msg) { $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $msg]; }
function leerFlash() { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function redirigir($url) { header("Location: $url"); exit; }
function usuario() { return $_SESSION['usuario'] ?? null; }
function esVet() { return (usuario()['rol'] ?? '') === 'veterinario'; }

$c = $_GET['c'] ?? 'inicio';
$a = $_GET['a'] ?? 'listar';

// 3. AUTENTICACIÓN
if ($c === 'auth') {
    if ($a === 'salir') {
        $_SESSION = [];
        session_destroy();
        session_start();
        flash('ok', 'Sesión cerrada correctamente.');
        redirigir('?c=auth&a=login');
    }
    if ($a === 'entrar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rol   = ($_POST['rol'] ?? '') === 'secretaria' ? 'secretaria' : 'veterinario';
        $user  = trim($_POST['usuario'] ?? '');
        $clave = $_POST['clave'] ?? '';
        $st = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND rol = ? AND activo = 1");
        $st->execute([$user, $rol]);
        $u = $st->fetch();
        if ($u && password_verify($clave, $u['clave'])) {
            session_regenerate_id(true);
            $_SESSION['usuario'] = ['id' => $u['id'], 'nombre' => $u['nombre'], 'usuario' => $u['usuario'], 'rol' => $u['rol']];
            redirigir('?c=inicio');
        }
        flash('error', 'Usuario o contraseña incorrectos.');
        redirigir("?c=auth&a=login&rol=$rol");
    }
    if (usuario()) redirigir('?c=inicio');
    $rol = ($_GET['rol'] ?? '') === 'secretaria' ? 'secretaria' : 'veterinario';
    $rolNombre = $rol === 'veterinario' ? 'Veterinario' : 'Secretaria';
    $f = leerFlash();
    ?>
    <!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Ingreso - Veterinaria</title>
    <style>
      *{box-sizing:border-box}body{font-family:Arial,sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0f766e,#14b8a6)}
      .login{background:#fff;width:92%;max-width:380px;padding:30px;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.2)}
      h1{margin:0 0 4px;color:#0f766e;font-size:24px;text-align:center}p.sub{text-align:center;color:#64748b;margin:0 0 20px}
      .tabs{display:flex;margin-bottom:20px;border-radius:6px;overflow:hidden;border:1px solid #0f766e}
      .tabs a{flex:1;text-align:center;padding:9px;text-decoration:none;color:#0f766e;font-size:14px}
      .tabs a.activo{background:#0f766e;color:#fff;font-weight:bold}
      label{font-weight:bold;font-size:14px}input{width:100%;padding:10px;margin:5px 0 15px;border:1px solid #b9c5c3;border-radius:4px;font-size:14px}
      button{width:100%;background:#0f766e;color:#fff;border:0;padding:11px;border-radius:4px;font-size:15px;cursor:pointer}
      .alerta{padding:10px;border-radius:4px;margin-bottom:15px;font-size:14px}.alerta.ok{background:#d1fae5;color:#065f46}.alerta.error{background:#fee2e2;color:#991b1b}
    </style></head><body><div class="login">
      <h1>🐾 Veterinaria</h1>
      <p class="sub">Ingreso como <strong><?= e($rolNombre) ?></strong></p>
      <div class="tabs">
        <a href="?c=auth&a=login&rol=veterinario" class="<?= $rol==='veterinario'?'activo':'' ?>">Veterinario</a>
        <a href="?c=auth&a=login&rol=secretaria" class="<?= $rol==='secretaria'?'activo':'' ?>">Secretaria</a>
      </div>
      <?php if ($f): ?><div class="alerta <?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div><?php endif; ?>
      <form method="post" action="?c=auth&a=entrar">
        <input type="hidden" name="rol" value="<?= e($rol) ?>">
        <label>Usuario:</label><input name="usuario" required autofocus>
        <label>Contraseña:</label><input type="password" name="clave" required>
        <button type="submit">Ingresar</button>
      </form>
    </div></body></html>
    <?php exit;
}

if (!usuario()) redirigir('?c=auth&a=login');

// 4. PROCESAMIENTO POST DE TODOS LOS MÓDULOS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($c === 'mascota' && in_array($a, ['guardar', 'actualizar'])) {
        $d = [
            trim($_POST['nombre'] ?? ''), trim($_POST['especie'] ?? ''), trim($_POST['raza'] ?? ''),
            in_array($_POST['sexo'] ?? '', ['Macho', 'Hembra']) ? $_POST['sexo'] : 'Macho',
            max(0, (float)($_POST['edad'] ?? 0)), max(0, (float)($_POST['peso'] ?? 0)),
            trim($_POST['dueno'] ?? ''), trim($_POST['telefono'] ?? '')
        ];
        if ($a === 'guardar') {
            $pdo->prepare("INSERT INTO mascotas (nombre,especie,raza,sexo,edad,peso,dueno,telefono) VALUES (?,?,?,?,?,?,?,?)")->execute($d);
            flash('ok', 'Mascota registrada correctamente.');
        } else {
            $d[] = (int)$_POST['id'];
            $pdo->prepare("UPDATE mascotas SET nombre=?,especie=?,raza=?,sexo=?,edad=?,peso=?,dueno=?,telefono=? WHERE id=?")->execute($d);
            flash('ok', 'Mascota actualizada.');
        }
        redirigir('?c=mascota');
    }

    if ($c === 'tratamiento' && in_array($a, ['guardar', 'actualizar'])) {
        $estados = ['En curso', 'Finalizado', 'Cancelado'];
        $mp = (int)($_POST['metodo_pago_id'] ?? 0);
        $d = [
            (int)$_POST['mascota_id'], (int)$_POST['servicio_id'], $mp > 0 ? $mp : null,
            trim($_POST['diagnostico'] ?? ''), trim($_POST['indicaciones'] ?? ''),
            max(0, (float)($_POST['costo'] ?? 0)),
            in_array($_POST['estado'] ?? '', $estados) ? $_POST['estado'] : 'En curso',
            isset($_POST['pagado']) ? 1 : 0
        ];
        if ($a === 'guardar') {
            $pdo->prepare("INSERT INTO tratamientos (mascota_id,servicio_id,metodo_pago_id,usuario_id,diagnostico,indicaciones,costo,estado,pagado) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$d[0],$d[1],$d[2],usuario()['id'],$d[3],$d[4],$d[5],$d[6],$d[7]]);
            flash('ok', 'Tratamiento registrado.');
        } else {
            $d[] = (int)$_POST['id'];
            $pdo->prepare("UPDATE tratamientos SET mascota_id=?,servicio_id=?,metodo_pago_id=?,diagnostico=?,indicaciones=?,costo=?,estado=?,pagado=? WHERE id=?")->execute($d);
            flash('ok', 'Tratamiento actualizado.');
        }
        redirigir('?c=tratamiento');
    }

    if ($c === 'historial' && in_array($a, ['guardar', 'actualizar']) && esVet()) {
        $unidades = ['Días' => 'day', 'Semanas' => 'week', 'Meses' => 'month'];
        $cant = max(1, (int)($_POST['volver_cantidad'] ?? 1));
        $uni  = isset($unidades[$_POST['volver_unidad'] ?? '']) ? $_POST['volver_unidad'] : 'Días';
        $base = date('Y-m-d');
        if ($a === 'actualizar') {
            $st = $pdo->prepare("SELECT fecha FROM historial WHERE id=?");
            $st->execute([(int)$_POST['id']]);
            if ($r = $st->fetch()) $base = substr($r['fecha'], 0, 10);
        }
        $ret = (new DateTime($base))->modify("+$cant " . $unidades[$uni])->format('Y-m-d');
        $srv = (int)($_POST['servicio_id'] ?? 0);
        $mId = (int)$_POST['mascota_id'];
        if ($a === 'guardar') {
            $pdo->prepare("INSERT INTO historial (mascota_id,servicio_id,usuario_id,tratamiento,volver_cantidad,volver_unidad,fecha_retorno,descripcion) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$mId, $srv>0?$srv:null, usuario()['id'], trim($_POST['tratamiento']??''), $cant, $uni, $ret, trim($_POST['descripcion']??'')]);
            flash('ok', 'Historial registrado. Próximo retorno: ' . date('d/m/Y', strtotime($ret)));
        } else {
            $pdo->prepare("UPDATE historial SET mascota_id=?,servicio_id=?,tratamiento=?,volver_cantidad=?,volver_unidad=?,fecha_retorno=?,descripcion=? WHERE id=?")
                ->execute([$mId, $srv>0?$srv:null, trim($_POST['tratamiento']??''), $cant, $uni, $ret, trim($_POST['descripcion']??''), (int)$_POST['id']]);
            flash('ok', 'Historial actualizado.');
        }
        redirigir("?c=historial&mascota_id=$mId");
    }

    if ($c === 'servicio' && in_array($a, ['guardar', 'actualizar']) && esVet()) {
        $d = [trim($_POST['nombre']??''), trim($_POST['descripcion']??''), max(0,(float)($_POST['precio']??0)), isset($_POST['activo'])?1:0];
        if ($a === 'guardar') {
            $pdo->prepare("INSERT INTO servicios (nombre,descripcion,precio,activo) VALUES (?,?,?,?)")->execute($d);
            flash('ok', 'Servicio agregado.');
        } else {
            $d[] = (int)$_POST['id'];
            $pdo->prepare("UPDATE servicios SET nombre=?,descripcion=?,precio=?,activo=? WHERE id=?")->execute($d);
            flash('ok', 'Servicio actualizado.');
        }
        redirigir('?c=servicio');
    }

    if ($c === 'metodopago' && in_array($a, ['guardar', 'actualizar']) && esVet()) {
        $d = [trim($_POST['nombre']??''), trim($_POST['descripcion']??''), isset($_POST['activo'])?1:0];
        if ($a === 'guardar') {
            $pdo->prepare("INSERT INTO metodos_pago (nombre,descripcion,activo) VALUES (?,?,?)")->execute($d);
            flash('ok', 'Método de pago agregado.');
        } else {
            $d[] = (int)$_POST['id'];
            $pdo->prepare("UPDATE metodos_pago SET nombre=?,descripcion=?,activo=? WHERE id=?")->execute($d);
            flash('ok', 'Método actualizado.');
        }
        redirigir('?c=metodopago');
    }

    if ($c === 'secretaria' && in_array($a, ['guardar', 'actualizar']) && esVet()) {
        $nom = trim($_POST['nombre']??'');
        $usr = trim($_POST['usuario']??'');
        $cla = $_POST['clave']??'';
        $tel = trim($_POST['telefono']??'');
        $act = isset($_POST['activo'])?1:0;
        try {
            if ($a === 'guardar') {
                $pdo->prepare("INSERT INTO usuarios (nombre,usuario,clave,rol,telefono,activo) VALUES (?,?,?,'secretaria',?,?)")
                    ->execute([$nom, $usr, password_hash($cla, PASSWORD_DEFAULT), $tel, $act]);
                flash('ok', 'Secretaria registrada.');
            } else {
                $id = (int)$_POST['id'];
                $pdo->prepare("UPDATE usuarios SET nombre=?,usuario=?,telefono=?,activo=? WHERE id=? AND rol='secretaria'")
                    ->execute([$nom, $usr, $tel, $act, $id]);
                if ($cla !== '') {
                    $pdo->prepare("UPDATE usuarios SET clave=? WHERE id=? AND rol='secretaria'")
                        ->execute([password_hash($cla, PASSWORD_DEFAULT), $id]);
                }
                flash('ok', 'Secretaria actualizada.');
            }
        } catch (Exception $ex) {
            flash('error', 'Ese nombre de usuario ya existe.');
        }
        redirigir('?c=secretaria');
    }
}

// 5. PLANTILLA VISUAL Y VISTAS
$u = usuario();
$f = leerFlash();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sistema de Veterinaria</title>
<style>
  *{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#eef5f4;margin:0;color:#1f2d2b}
  nav{background:#0f766e;padding:12px 30px;display:flex;flex-wrap:wrap;align-items:center;gap:6px 20px}
  nav .marca{color:#fff;font-weight:bold;font-size:18px;margin-right:10px}
  nav a{color:#d7f3ef;text-decoration:none}nav a:hover{color:#fff;text-decoration:underline}
  nav .usuario{margin-left:auto;color:#d7f3ef;font-size:14px}nav .usuario a{color:#fff;font-weight:bold;margin-left:10px}
  .caja{width:92%;max-width:1000px;margin:25px auto;background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
  h2{margin-top:0;color:#0f766e}
  table{width:100%;border-collapse:collapse;margin-bottom:15px}
  th,td{border:1px solid #d5dcdb;padding:8px;text-align:center;font-size:14px}
  th{background:#0f766e;color:#fff}tr:nth-child(even) td{background:#f6faf9}
  .tabla-scroll{overflow-x:auto}
  label{font-weight:bold;font-size:14px}
  input,select,textarea{width:100%;padding:8px;margin:5px 0 15px;border:1px solid #b9c5c3;border-radius:4px;font-family:inherit;font-size:14px}
  input[type=checkbox]{width:auto;margin:0 6px 0 0}.check{display:flex;align-items:center;margin-bottom:15px}.check label{font-weight:normal}
  button,.btn{background:#0f766e;color:#fff;border:0;padding:10px 16px;border-radius:4px;cursor:pointer;text-decoration:none;display:inline-block;font-size:14px}
  .btn-sec{background:#64748b}.btn-peq{padding:5px 12px;font-size:13px;background:#2563eb}
  .acciones{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .alerta{padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:14px}.alerta.ok{background:#d1fae5;color:#065f46}.alerta.error{background:#fee2e2;color:#991b1b}
  .tag{padding:2px 8px;border-radius:10px;font-size:12px;color:#fff}.tag.verde{background:#16a34a}.tag.rojo{background:#dc2626}.tag.azul{background:#2563eb}.tag.gris{background:#6b7280}
  .tarjetas{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}
  .tarjeta{background:#f0fdfa;border:1px solid #99d5cd;border-radius:8px;padding:16px;text-align:center;text-decoration:none;color:#0f766e}
  .tarjeta strong{display:block;font-size:26px}.tarjeta.link:hover{background:#ccfbf1}
  .buscador{display:flex;gap:10px;margin-bottom:15px}.buscador input{margin:0}.vacio{color:#6b7280;text-align:center;padding:20px}
</style></head><body>
<nav>
  <span class="marca">🐾 Veterinaria</span>
  <a href="?c=inicio">Inicio</a>
  <a href="?c=mascota">Mascotas</a>
  <a href="?c=tratamiento">Tratamientos</a>
  <a href="?c=historial">Historial</a>
  <a href="?c=servicio">Servicios</a>
  <a href="?c=metodopago">Métodos de pago</a>
  <?php if (esVet()): ?><a href="?c=secretaria">Secretaria</a><?php endif; ?>
  <span class="usuario"><?= e($u['nombre']) ?> (<?= e(ucfirst($u['rol'])) ?>) <a href="?c=auth&a=salir">Salir</a></span>
</nav>
<div class="caja">
<?php if ($f): ?><div class="alerta <?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div><?php endif; ?>

<?php if ($c === 'inicio'):
  $res = [
    'mascotas'   => (int)$pdo->query("SELECT COUNT(*) FROM mascotas")->fetchColumn(),
    'en_curso'   => (int)$pdo->query("SELECT COUNT(*) FROM tratamientos WHERE estado='En curso'")->fetchColumn(),
    'pendientes' => (int)$pdo->query("SELECT COUNT(*) FROM tratamientos WHERE pagado=0 AND estado<>'Cancelado'")->fetchColumn(),
    'servicios'  => (int)$pdo->query("SELECT COUNT(*) FROM servicios WHERE activo=1")->fetchColumn(),
  ]; ?>
  <h2>Bienvenido/a, <?= e($u['nombre']) ?></h2>
  <div class="tarjetas" style="margin-bottom:24px">
    <div class="tarjeta"><strong><?= $res['mascotas'] ?></strong>Mascotas registradas</div>
    <div class="tarjeta"><strong><?= $res['en_curso'] ?></strong>Tratamientos en curso</div>
    <div class="tarjeta"><strong><?= $res['pendientes'] ?></strong>Pagos pendientes</div>
    <div class="tarjeta"><strong><?= $res['servicios'] ?></strong>Servicios activos</div>
  </div>
  <h2>Accesos rápidos</h2>
  <div class="tarjetas">
    <a class="tarjeta link" href="?c=mascota&a=crear">🐶 Registrar mascota</a>
    <a class="tarjeta link" href="?c=tratamiento&a=crear">💊 Nuevo tratamiento</a>
    <a class="tarjeta link" href="?c=historial">📋 Historial clínico</a>
    <a class="tarjeta link" href="?c=servicio">🩺 Servicios</a>
    <a class="tarjeta link" href="?c=metodopago">💳 Métodos de pago</a>
    <?php if (esVet()): ?><a class="tarjeta link" href="?c=secretaria">🧑‍💼 Secretaria</a><?php endif; ?>
  </div>

<?php elseif ($c === 'mascota'):
  if ($a === 'crear' || $a === 'editar'):
    $m = null;
    if ($a === 'editar') { $st = $pdo->prepare("SELECT * FROM mascotas WHERE id=?"); $st->execute([(int)($_GET['id']??0)]); $m = $st->fetch(); }
    ?>
    <h2><?= $m ? 'Editar Mascota' : 'Registro de Mascota' ?></h2>
    <form method="post" action="?c=mascota&a=<?= $m?'actualizar':'guardar' ?>">
      <?php if ($m): ?><input type="hidden" name="id" value="<?= $m['id'] ?>"><?php endif; ?>
      <label>Nombre:</label><input name="nombre" required value="<?= e($m['nombre']??'') ?>">
      <label>Especie:</label><input name="especie" required value="<?= e($m['especie']??'') ?>">
      <label>Raza:</label><input name="raza" value="<?= e($m['raza']??'') ?>">
      <label>Sexo:</label><select name="sexo"><option <?= ($m['sexo']??'')==='Macho'?'selected':'' ?>>Macho</option><option <?= ($m['sexo']??'')==='Hembra'?'selected':'' ?>>Hembra</option></select>
      <label>Edad (años):</label><input type="number" name="edad" step="0.1" min="0" required value="<?= e($m['edad']??'') ?>">
      <label>Peso (kg):</label><input type="number" name="peso" step="0.01" min="0" required value="<?= e($m['peso']??'') ?>">
      <label>Dueño:</label><input name="dueno" required value="<?= e($m['dueno']??'') ?>">
      <label>Teléfono:</label><input name="telefono" value="<?= e($m['telefono']??'') ?>">
      <div class="acciones"><button type="submit">Guardar</button><a class="btn btn-sec" href="?c=mascota">Cancelar</a></div>
    </form>
  <?php else:
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') { $st = $pdo->prepare("SELECT * FROM mascotas WHERE nombre LIKE ? OR dueno LIKE ? OR especie LIKE ? ORDER BY id DESC"); $st->execute(["%$q%","%$q%","%$q%"]); $lista = $st->fetchAll(); }
    else { $lista = $pdo->query("SELECT * FROM mascotas ORDER BY id DESC")->fetchAll(); }
    ?>
    <h2>Mascotas</h2>
    <form class="buscador" method="get"><input type="hidden" name="c" value="mascota"><input name="q" placeholder="Buscar..." value="<?= e($q) ?>"><button type="submit">Buscar</button></form>
    <div class="tabla-scroll"><table>
      <tr><th>ID</th><th>Nombre</th><th>Especie</th><th>Raza</th><th>Sexo</th><th>Edad</th><th>Peso</th><th>Dueño</th><th>Teléfono</th><th>Acciones</th></tr>
      <?php foreach ($lista as $m): ?>
      <tr><td><?= $m['id'] ?></td><td><?= e($m['nombre']) ?></td><td><?= e($m['especie']) ?></td><td><?= e($m['raza']) ?></td><td><?= e($m['sexo']) ?></td><td><?= e($m['edad']) ?></td><td><?= e($m['peso']) ?></td><td><?= e($m['dueno']) ?></td><td><?= e($m['telefono']) ?></td>
      <td><a class="btn btn-peq" href="?c=mascota&a=editar&id=<?= $m['id'] ?>">Editar</a> <a class="btn btn-peq" style="background:#0f766e" href="?c=historial&mascota_id=<?= $m['id'] ?>">Historial</a></td></tr>
      <?php endforeach; ?>
    </table></div>
    <p><a class="btn" href="?c=mascota&a=crear">Registrar Mascota</a></p>
  <?php endif; ?>

<?php elseif ($c === 'tratamiento'):
  if ($a === 'crear' || $a === 'editar'):
    $t = null;
    if ($a === 'editar') { $st = $pdo->prepare("SELECT * FROM tratamientos WHERE id=?"); $st->execute([(int)($_GET['id']??0)]); $t = $st->fetch(); }
    $mascotas = $pdo->query("SELECT * FROM mascotas ORDER BY nombre")->fetchAll();
    $servicios = $pdo->query("SELECT * FROM servicios ORDER BY nombre")->fetchAll();
    $metodos = $pdo->query("SELECT * FROM metodos_pago ORDER BY nombre")->fetchAll();
    ?>
    <h2><?= $t ? 'Editar Tratamiento' : 'Nuevo Tratamiento' ?></h2>
    <form method="post" action="?c=tratamiento&a=<?= $t?'actualizar':'guardar' ?>">
      <?php if ($t): ?><input type="hidden" name="id" value="<?= $t['id'] ?>"><?php endif; ?>
      <label>Mascota:</label><select name="mascota_id" required><option value="">-- Seleccione --</option>
        <?php foreach ($mascotas as $m): ?><option value="<?= $m['id'] ?>" <?= ($t['mascota_id']??'')==$m['id']?'selected':'' ?>><?= e($m['nombre']) ?> - <?= e($m['dueno']) ?></option><?php endforeach; ?>
      </select>
      <label>Servicio:</label><select name="servicio_id" id="srv" required><option value="">-- Seleccione --</option>
        <?php foreach ($servicios as $s): ?><option value="<?= $s['id'] ?>" data-p="<?= e($s['precio']) ?>" <?= ($t['servicio_id']??'')==$s['id']?'selected':'' ?>><?= e($s['nombre']) ?> - Bs <?= number_format($s['precio'],2) ?></option><?php endforeach; ?>
      </select>
      <label>Diagnóstico:</label><textarea name="diagnostico" rows="3" required><?= e($t['diagnostico']??'') ?></textarea>
      <label>Indicaciones:</label><textarea name="indicaciones" rows="3"><?= e($t['indicaciones']??'') ?></textarea>
      <label>Costo (Bs):</label><input type="number" name="costo" id="costo" step="0.01" min="0" required value="<?= e($t['costo']??'') ?>">
      <label>Estado:</label><select name="estado"><?php foreach(['En curso','Finalizado','Cancelado'] as $est): ?><option <?= ($t['estado']??'En curso')===$est?'selected':'' ?>><?= $est ?></option><?php endforeach; ?></select>
      <label>Método de pago:</label><select name="metodo_pago_id"><option value="">-- Sin definir --</option>
        <?php foreach ($metodos as $mp): ?><option value="<?= $mp['id'] ?>" <?= ($t['metodo_pago_id']??'')==$mp['id']?'selected':'' ?>><?= e($mp['nombre']) ?></option><?php endforeach; ?>
      </select>
      <div class="check"><input type="checkbox" id="pag" name="pagado" <?= ($t['pagado']??0)?'checked':'' ?>><label for="pag">Pago realizado</label></div>
      <div class="acciones"><button type="submit">Guardar</button><a class="btn btn-sec" href="?c=tratamiento">Cancelar</a></div>
    </form>
    <script>document.getElementById('srv').addEventListener('change',function(){var p=this.options[this.selectedIndex].getAttribute('data-p');if(p!==null)document.getElementById('costo').value=p;});</script>
  <?php else:
    $lista = $pdo->query("SELECT t.*,m.nombre AS mascota,m.dueno,s.nombre AS servicio,mp.nombre AS metodo FROM tratamientos t JOIN mascotas m ON m.id=t.mascota_id JOIN servicios s ON s.id=t.servicio_id LEFT JOIN metodos_pago mp ON mp.id=t.metodo_pago_id ORDER BY t.id DESC")->fetchAll();
    ?>
    <h2>Tratamientos</h2>
    <div class="tabla-scroll"><table>
      <tr><th>N°</th><th>Fecha</th><th>Mascota</th><th>Servicio</th><th>Diagnóstico</th><th>Costo</th><th>Estado</th><th>Pago</th><th>Acciones</th></tr>
      <?php foreach ($lista as $t): $ce=['En curso'=>'azul','Finalizado'=>'verde','Cancelado'=>'gris'][$t['estado']]??'gris'; ?>
      <tr><td><?= $t['id'] ?></td><td><?= e(substr($t['fecha'],0,10)) ?></td><td><?= e($t['mascota']) ?><br><small><?= e($t['dueno']) ?></small></td><td><?= e($t['servicio']) ?></td><td><?= e($t['diagnostico']) ?></td><td><?= number_format($t['costo'],2) ?></td>
      <td><span class="tag <?= $ce ?>"><?= e($t['estado']) ?></span></td>
      <td><span class="tag <?= $t['pagado']?'verde':'rojo' ?>"><?= $t['pagado']?'Pagado':'Pendiente' ?></span><?php if($t['metodo']): ?><br><small><?= e($t['metodo']) ?></small><?php endif; ?></td>
      <td><a class="btn btn-peq" href="?c=tratamiento&a=editar&id=<?= $t['id'] ?>">Editar</a></td></tr>
      <?php endforeach; ?>
    </table></div>
    <p><a class="btn" href="?c=tratamiento&a=crear">Nuevo Tratamiento</a></p>
  <?php endif; ?>

<?php elseif ($c === 'historial'):
  $mId = (int)($_GET['mascota_id'] ?? 0);
  if (($a === 'crear' || $a === 'editar') && esVet()):
    $reg = null;
    if ($a === 'editar') { $st = $pdo->prepare("SELECT * FROM historial WHERE id=?"); $st->execute([(int)($_GET['id']??0)]); $reg = $st->fetch(); $mId = (int)($reg['mascota_id']??0); }
    $mascotas = $pdo->query("SELECT * FROM mascotas ORDER BY nombre")->fetchAll();
    $servicios = $pdo->query("SELECT * FROM servicios ORDER BY nombre")->fetchAll();
    ?>
    <h2><?= $reg ? 'Editar Historial' : 'Nuevo Registro de Historial' ?></h2>
    <form method="post" action="?c=historial&a=<?= $reg?'actualizar':'guardar' ?>">
      <?php if ($reg): ?><input type="hidden" name="id" value="<?= $reg['id'] ?>"><?php endif; ?>
      <label>Mascota:</label><select name="mascota_id" required><option value="">-- Seleccione --</option>
        <?php foreach ($mascotas as $m): ?><option value="<?= $m['id'] ?>" <?= $mId==$m['id']?'selected':'' ?>><?= e($m['nombre']) ?> - <?= e($m['dueno']) ?></option><?php endforeach; ?>
      </select>
      <label>Servicio (opcional):</label><select name="servicio_id"><option value="">-- Ninguno --</option>
        <?php foreach ($servicios as $s): ?><option value="<?= $s['id'] ?>" <?= ($reg['servicio_id']??'')==$s['id']?'selected':'' ?>><?= e($s['nombre']) ?></option><?php endforeach; ?>
      </select>
      <label>Tratamiento indicado:</label><input name="tratamiento" required value="<?= e($reg['tratamiento']??'') ?>">
      <label>¿En cuánto tiempo debe volver?</label>
      <div style="display:flex;gap:10px">
        <input type="number" name="volver_cantidad" min="1" required style="flex:1" value="<?= e($reg['volver_cantidad']??7) ?>">
        <select name="volver_unidad" style="flex:2"><?php foreach(['Días','Semanas','Meses'] as $un): ?><option <?= ($reg['volver_unidad']??'Días')===$un?'selected':'' ?>><?= $un ?></option><?php endforeach; ?></select>
      </div>
      <label>Descripción:</label><textarea name="descripcion" rows="4"><?= e($reg['descripcion']??'') ?></textarea>
      <div class="acciones"><button type="submit">Guardar</button><a class="btn btn-sec" href="?c=historial">Cancelar</a></div>
    </form>
  <?php else:
    $hoy = date('Y-m-d');
    if ($mId > 0) { $st = $pdo->prepare("SELECT h.*,m.nombre AS mascota,m.dueno,s.nombre AS servicio FROM historial h JOIN mascotas m ON m.id=h.mascota_id LEFT JOIN servicios s ON s.id=h.servicio_id WHERE h.mascota_id=? ORDER BY h.id DESC"); $st->execute([$mId]); $lista = $st->fetchAll(); }
    else { $lista = $pdo->query("SELECT h.*,m.nombre AS mascota,m.dueno,s.nombre AS servicio FROM historial h JOIN mascotas m ON m.id=h.mascota_id LEFT JOIN servicios s ON s.id=h.servicio_id ORDER BY h.id DESC")->fetchAll(); }
    ?>
    <h2>Historial clínico</h2>
    <div class="tabla-scroll"><table>
      <tr><th>N°</th><th>Fecha</th><th>Mascota</th><th>Tratamiento</th><th>Debe volver en</th><th>Retorno</th><th>Descripción</th><?php if(esVet()): ?><th>Acciones</th><?php endif; ?></tr>
      <?php foreach ($lista as $h): ?>
      <tr><td><?= $h['id'] ?></td><td><?= e(substr($h['fecha'],0,10)) ?></td><td><?= e($h['mascota']) ?><br><small><?= e($h['dueno']) ?></small></td><td><?= e($h['tratamiento']) ?></td><td><?= (int)$h['volver_cantidad'] ?> <?= e($h['volver_unidad']) ?></td>
      <td><?= date('d/m/Y',strtotime($h['fecha_retorno'])) ?><br><span class="tag <?= $h['fecha_retorno']===$hoy?'azul':($h['fecha_retorno']<$hoy?'rojo':'verde') ?>"><?= $h['fecha_retorno']===$hoy?'Es hoy':($h['fecha_retorno']<$hoy?'Vencido':'Próximo') ?></span></td>
      <td><?= nl2br(e($h['descripcion'])) ?></td>
      <?php if(esVet()): ?><td><a class="btn btn-peq" href="?c=historial&a=editar&id=<?= $h['id'] ?>">Editar</a></td><?php endif; ?></tr>
      <?php endforeach; ?>
    </table></div>
    <?php if(esVet()): ?><p><a class="btn" href="?c=historial&a=crear<?= $mId?"&mascota_id=$mId":'' ?>">Nuevo Registro de Historial</a></p><?php endif; ?>
  <?php endif; ?>

<?php elseif ($c === 'servicio'):
  if (($a === 'crear' || $a === 'editar') && esVet()):
    $s = null;
    if ($a === 'editar') { $st = $pdo->prepare("SELECT * FROM servicios WHERE id=?"); $st->execute([(int)($_GET['id']??0)]); $s = $st->fetch(); }
    ?>
    <h2><?= $s ? 'Editar Servicio' : 'Nuevo Servicio' ?></h2>
    <form method="post" action="?c=servicio&a=<?= $s?'actualizar':'guardar' ?>">
      <?php if ($s): ?><input type="hidden" name="id" value="<?= $s['id'] ?>"><?php endif; ?>
      <label>Nombre:</label><input name="nombre" required value="<?= e($s['nombre']??'') ?>">
      <label>Descripción:</label><input name="descripcion" value="<?= e($s['descripcion']??'') ?>">
      <label>Precio (Bs):</label><input type="number" name="precio" step="0.01" min="0" required value="<?= e($s['precio']??'') ?>">
      <div class="check"><input type="checkbox" id="act" name="activo" <?= (!$s||$s['activo'])?'checked':'' ?>><label for="act">Servicio activo</label></div>
      <div class="acciones"><button type="submit">Guardar</button><a class="btn btn-sec" href="?c=servicio">Cancelar</a></div>
    </form>
  <?php else:
    $lista = $pdo->query("SELECT * FROM servicios ORDER BY nombre")->fetchAll(); ?>
    <h2>Servicios</h2>
    <table><tr><th>ID</th><th>Nombre</th><th>Descripción</th><th>Precio (Bs)</th><th>Estado</th><?php if(esVet()): ?><th>Acciones</th><?php endif; ?></tr>
    <?php foreach ($lista as $s): ?>
      <tr><td><?= $s['id'] ?></td><td><?= e($s['nombre']) ?></td><td><?= e($s['descripcion']) ?></td><td><?= number_format($s['precio'],2) ?></td><td><span class="tag <?= $s['activo']?'verde':'gris' ?>"><?= $s['activo']?'Activo':'Inactivo' ?></span></td><?php if(esVet()): ?><td><a class="btn btn-peq" href="?c=servicio&a=editar&id=<?= $s['id'] ?>">Editar</a></td><?php endif; ?></tr>
    <?php endforeach; ?></table>
    <?php if(esVet()): ?><p><a class="btn" href="?c=servicio&a=crear">Agregar Servicio</a></p><?php endif; ?>
  <?php endif; ?>

<?php elseif ($c === 'metodopago'):
  if (($a === 'crear' || $a === 'editar') && esVet()):
    $mp = null;
    if ($a === 'editar') { $st = $pdo->prepare("SELECT * FROM metodos_pago WHERE id=?"); $st->execute([(int)($_GET['id']??0)]); $mp = $st->fetch(); }
    ?>
    <h2><?= $mp ? 'Editar Método de Pago' : 'Nuevo Método de Pago' ?></h2>
    <form method="post" action="?c=metodopago&a=<?= $mp?'actualizar':'guardar' ?>">
      <?php if ($mp): ?><input type="hidden" name="id" value="<?= $mp['id'] ?>"><?php endif; ?>
      <label>Nombre:</label><input name="nombre" required value="<?= e($mp['nombre']??'') ?>">
      <label>Descripción:</label><input name="descripcion" value="<?= e($mp['descripcion']??'') ?>">
      <div class="check"><input type="checkbox" id="act" name="activo" <?= (!$mp||$mp['activo'])?'checked':'' ?>><label for="act">Método activo</label></div>
      <div class="acciones"><button type="submit">Guardar</button><a class="btn btn-sec" href="?c=metodopago">Cancelar</a></div>
    </form>
  <?php else:
    $lista = $pdo->query("SELECT * FROM metodos_pago ORDER BY nombre")->fetchAll(); ?>
    <h2>Métodos de pago</h2>
    <table><tr><th>ID</th><th>Nombre</th><th>Descripción</th><th>Estado</th><?php if(esVet()): ?><th>Acciones</th><?php endif; ?></tr>
    <?php foreach ($lista as $mp): ?>
      <tr><td><?= $mp['id'] ?></td><td><?= e($mp['nombre']) ?></td><td><?= e($mp['descripcion']) ?></td><td><span class="tag <?= $mp['activo']?'verde':'gris' ?>"><?= $mp['activo']?'Activo':'Inactivo' ?></span></td><?php if(esVet()): ?><td><a class="btn btn-peq" href="?c=metodopago&a=editar&id=<?= $mp['id'] ?>">Editar</a></td><?php endif; ?></tr>
    <?php endforeach; ?></table>
    <?php if(esVet()): ?><p><a class="btn" href="?c=metodopago&a=crear">Agregar Método de Pago</a></p><?php endif; ?>
  <?php endif; ?>

<?php elseif ($c === 'secretaria' && esVet()):
  if ($a === 'crear' || $a === 'editar'):
    $sec = null;
    if ($a === 'editar') { $st = $pdo->prepare("SELECT * FROM usuarios WHERE id=? AND rol='secretaria'"); $st->execute([(int)($_GET['id']??0)]); $sec = $st->fetch(); }
    ?>
    <h2><?= $sec ? 'Editar Secretaria' : 'Nueva Secretaria' ?></h2>
    <form method="post" action="?c=secretaria&a=<?= $sec?'actualizar':'guardar' ?>">
      <?php if ($sec): ?><input type="hidden" name="id" value="<?= $sec['id'] ?>"><?php endif; ?>
      <label>Nombre completo:</label><input name="nombre" required value="<?= e($sec['nombre']??'') ?>">
      <label>Usuario:</label><input name="usuario" required value="<?= e($sec['usuario']??'') ?>">
      <label>Contraseña<?= $sec?' (vacía para mantener)':'' ?>:</label><input type="password" name="clave" <?= $sec?'':'required' ?>>
      <label>Teléfono:</label><input name="telefono" value="<?= e($sec['telefono']??'') ?>">
      <div class="check"><input type="checkbox" id="act" name="activo" <?= (!$sec||$sec['activo'])?'checked':'' ?>><label for="act">Cuenta activa</label></div>
      <div class="acciones"><button type="submit">Guardar</button><a class="btn btn-sec" href="?c=secretaria">Cancelar</a></div>
    </form>
  <?php else:
    $lista = $pdo->query("SELECT * FROM usuarios WHERE rol='secretaria' ORDER BY nombre")->fetchAll(); ?>
    <h2>Secretaria</h2>
    <table><tr><th>ID</th><th>Nombre</th><th>Usuario</th><th>Teléfono</th><th>Estado</th><th>Acciones</th></tr>
    <?php foreach ($lista as $s): ?>
      <tr><td><?= $s['id'] ?></td><td><?= e($s['nombre']) ?></td><td><?= e($s['usuario']) ?></td><td><?= e($s['telefono']) ?></td><td><span class="tag <?= $s['activo']?'verde':'gris' ?>"><?= $s['activo']?'Activa':'Inactiva' ?></span></td><td><a class="btn btn-peq" href="?c=secretaria&a=editar&id=<?= $s['id'] ?>">Editar</a></td></tr>
    <?php endforeach; ?></table>
    <p><a class="btn" href="?c=secretaria&a=crear">Agregar Secretaria</a></p>
  <?php endif; ?>
<?php endif; ?>

</div></body></html>
