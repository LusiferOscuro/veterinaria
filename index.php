<?php
// Auto-organizador: si GitHub subió los archivos planos en la raíz, los mueve a sus carpetas MVC
$mapa = [
    'Conexion.php' => 'config', 'Sesion.php' => 'config',
    'AuthController.php' => 'controllers', 'HistorialController.php' => 'controllers',
    'InicioController.php' => 'controllers', 'MascotaController.php' => 'controllers',
    'MetodoPagoController.php' => 'controllers', 'SecretariaController.php' => 'controllers',
    'ServicioController.php' => 'controllers', 'TratamientoController.php' => 'controllers',
    'Historial.php' => 'models', 'Mascota.php' => 'models', 'MetodoPago.php' => 'models',
    'Servicio.php' => 'models', 'Tratamiento.php' => 'models', 'Usuario.php' => 'models',
    'login.php' => 'views/auth', 'panel.php' => 'views/inicio',
    'encabezado.php' => 'views/plantilla', 'pie.php' => 'views/plantilla',
];
foreach ($mapa as $archivo => $carpeta) {
    if (file_exists(__DIR__ . '/' . $archivo) && !file_exists(__DIR__ . '/' . $carpeta . '/' . $archivo)) {
        if (!is_dir(__DIR__ . '/' . $carpeta)) mkdir(__DIR__ . '/' . $carpeta, 0777, true);
        @rename(__DIR__ . '/' . $archivo, __DIR__ . '/' . $carpeta . '/' . $archivo);
    }
}

require_once "config/Sesion.php";
Sesion::iniciar();

$controladores = [
    'auth'        => 'AuthController',
    'inicio'      => 'InicioController',
    'mascota'     => 'MascotaController',
    'tratamiento' => 'TratamientoController',
    'historial'   => 'HistorialController',
    'metodopago'  => 'MetodoPagoController',
    'servicio'    => 'ServicioController',
    'secretaria'  => 'SecretariaController',
];

$c = $_GET['c'] ?? 'inicio';
$a = $_GET['a'] ?? 'listar';
if (!isset($controladores[$c])) $c = 'inicio';
if ($c !== 'auth') Sesion::requerirLogin();

$clase = $controladores[$c];
require_once "controllers/$clase.php";
$controlador = new $clase();

$soloPost = ['guardar', 'actualizar', 'entrar'];
if (in_array($a, $soloPost, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') $a = 'listar';
if (!is_callable([$controlador, $a])) $a = ($c === 'auth') ? 'login' : 'listar';
$controlador->$a();
?>
