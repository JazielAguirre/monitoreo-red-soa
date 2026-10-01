<?php
/**
 * Prepara la demo (docs/DEMO.md): recarga la base, mueve las fechas del seed para
 * que la última ronda de monitoreo sea de hace unos minutos, pone las IPs del router
 * y de "PC Recepción" de la red donde se presenta y comprueba que todo responde.
 *
 *   /opt/lampp/bin/php herramientas/preparar-demo.php                     detecta las IPs
 *   /opt/lampp/bin/php herramientas/preparar-demo.php --router=10.0.0.1 --pc=10.0.0.57
 *   /opt/lampp/bin/php herramientas/preparar-demo.php --sin-red           plan B sin WiFi
 *   (Windows: C:/xampp/php/php.exe herramientas/preparar-demo.php …)
 *
 * Termina con código 0 si todas las comprobaciones pasan (un ⚠ es solo un aviso), 1 si alguna falla y 2 si
 * no pudo preparar la base.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/base-de-datos.php';
require_once __DIR__ . '/red.php';
require_once __DIR__ . '/../servicios/comun/config.php';
require_once __DIR__ . '/../servicios/comun/db.php';
require_once __DIR__ . '/../servicios/comun/http.php';       // archivoRegistroLlamadas()
require_once __DIR__ . '/../servicios/comun/ping.php';       // hacerPing(), la misma función de Monitoreo
require_once __DIR__ . '/../servicios/descubrimiento/arp.php'; // tablaArpDelServicio(), la lectura del escáner

const MINUTOS_DESDE_LA_ULTIMA_RONDA = 10;
const ID_ROUTER = 2;          // "Router principal" en seed.sql
const ID_PC_RECEPCION = 3;    // "PC Recepción" en seed.sql
const IP_ROUTER_SIN_RED = '192.0.2.30';  // no responde: sirve para mostrar la alerta
const IP_PC_SIN_RED = '127.0.0.2';       // la propia máquina: siempre responde
const COLUMNAS_DE_FECHA = [
    'dispositivos' => ['fecha_registro', 'ultima_comprobacion'],
    'monitoreos' => ['fecha'],
    'alertas' => ['fecha', 'fecha_atendida'],
    'usuarios' => ['fecha_registro'],
];
const DATOS_DEL_SEED = ['dispositivos' => 6, 'monitoreos' => 56, 'alertas' => 4, 'usuarios' => 1];

// Qué hacer si algo falla (los mismos casos que la tabla "Si pasa X, haz Y" de docs/DEMO.md).
const QUE_HACER = [
    'apache' => PHP_OS_FAMILY === 'Windows'
        ? 'Enciende Apache en el panel de XAMPP (botón Start de Apache).'
        : 'Enciende Apache: sudo /opt/lampp/lampp startapache',
    'servicios' => 'Apache responde pero los servicios no: revisa que la carpeta se llame monitoreo-red-soa dentro de htdocs y que AllowOverride permita los .htaccess (README, "Instalación").',
    'mysql' => PHP_OS_FAMILY === 'Windows'
        ? 'Enciende MySQL en el panel de XAMPP. Si no arranca, revisa el registro de MySQL en el panel.'
        : 'Enciende MySQL: sudo /opt/lampp/lampp startmysql. Si no arranca, agrega tmpdir = /tmp debajo de [mysqld] en /opt/lampp/etc/my.cnf (README).',
    'router' => 'El router no responde al ping: comprueba que la computadora esté conectada a la red de la demo, indica la IP con --router=IP o usa --sin-red.',
];

$opciones = leerOpciones($argv);
if ($opciones === null) {
    fwrite(STDERR, "Uso: php herramientas/preparar-demo.php [--router=IP] [--pc=IP] [--sin-red]\n");
    exit(2);
}

echo "Preparando la demo del Sistema de Monitoreo de Red SOA\n\n";

// ---------------------------------------------------------------- 1. IPs

$ips = elegirIps($opciones);
$errorIps = validarIps($ips['router'], $ips['pc']);
if ($errorIps !== null) {
    fwrite(STDERR, "✗ $errorIps\n  No se tocó la base de datos.\n");
    exit(2);
}

// ---------------------------------------------------------------- 2. Base de datos

if (!mysqlResponde()) {
    fwrite(STDERR, "✗ No se pudo conectar con MySQL.\n  Qué hacer: " . QUE_HACER['mysql'] . "\n");
    exit(2);
}

cargarSchemaYSeed();
$conexion = obtenerConexion();
$choque = ipQueYaUsaOtroDispositivo($conexion, $ips);
if ($choque !== null) {
    fwrite(STDERR, "✗ $choque\n  La base quedó con el seed sin cambios; elige otra IP.\n");
    exit(2);
}

$segundos = desplazarFechas($conexion);
cambiarIps($conexion, $ips['router'], $ips['pc']);

// ---------------------------------------------------------------- 3. Resumen

mostrarResumen($conexion, $ips, $segundos);

// ---------------------------------------------------------------- 4. Comprobación previa

$bien = comprobarTodo($conexion, $ips);
exit($bien ? 0 : 1);

// =====================================================================

/**
 * --router=IP, --pc=IP y --sin-red. Devuelve null si hay una opción desconocida.
 */
function leerOpciones(array $argumentos): ?array
{
    $opciones = ['router' => null, 'pc' => null, 'sin_red' => false];

    foreach (array_slice($argumentos, 1) as $argumento) {
        if ($argumento === '--sin-red') {
            $opciones['sin_red'] = true;
        } elseif (preg_match('/^--(router|pc)=(.*)$/', $argumento, $c) === 1) {
            $opciones[$c[1]] = trim($c[2]);
        } else {
            return null;
        }
    }

    return $opciones;
}

/**
 * Las IPs indicadas, o las detectadas; sin red (o si no se detectan), las del plan B.
 */
function elegirIps(array $opciones): array
{
    $sinRed = $opciones['sin_red'];
    $routerDetectado = $sinRed || $opciones['router'] !== null ? null : detectarPuertaDeEnlace();
    $pcDetectada = $sinRed || $opciones['pc'] !== null ? null : detectarIpPropia();

    $elegir = fn(?string $indicada, ?string $detectada, string $planB): array => match (true) {
        $indicada !== null => [$indicada, 'indicada'],
        $detectada !== null => [$detectada, 'detectada'],
        default => [$planB, $sinRed ? 'plan sin red, --sin-red' : 'plan sin red: no se pudo detectar'],
    };

    [$router, $origenRouter] = $elegir($opciones['router'], $routerDetectado, IP_ROUTER_SIN_RED);
    [$pc, $origenPc] = $elegir($opciones['pc'], $pcDetectada, IP_PC_SIN_RED);

    return ['router' => $router, 'pc' => $pc, 'origen_router' => $origenRouter, 'origen_pc' => $origenPc];
}

function validarIps(string $router, string $pc): ?string
{
    foreach (['router' => $router, 'pc' => $pc] as $nombre => $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return "La IP de --$nombre no es una IPv4 válida: «{$ip}».";
        }
    }

    return $router === $pc ? "El router y PC Recepción no pueden tener la misma IP ($router)." : null;
}

function mysqlResponde(): bool
{
    try {
        new PDO(sprintf('mysql:host=%s;port=%d', DB_HOST, DB_PUERTO), DB_USUARIO, DB_CLAVE, [PDO::ATTR_TIMEOUT => 3]);
        return true;
    } catch (PDOException) {
        return false;
    }
}

/**
 * La IP elegida no puede ser la de otro dispositivo del seed (la columna ip es UNIQUE).
 */
function ipQueYaUsaOtroDispositivo(PDO $conexion, array $ips): ?string
{
    $consulta = $conexion->prepare('SELECT nombre FROM dispositivos WHERE ip = :ip AND id NOT IN (:router, :pc)');

    foreach (['router' => $ips['router'], 'pc' => $ips['pc']] as $nombre => $ip) {
        $consulta->execute(['ip' => $ip, 'router' => ID_ROUTER, 'pc' => ID_PC_RECEPCION]);
        $otro = $consulta->fetchColumn();
        if ($otro !== false) {
            return "La IP de --$nombre ($ip) ya es de «{$otro}».";
        }
    }

    return null;
}

/**
 * Mueve todas las fechas la misma cantidad de segundos, para que la última ronda de
 * monitoreo sea de hace MINUTOS_DESDE_LA_ULTIMA_RONDA minutos. Las distancias entre
 * fechas se conservan (las alertas atendidas siguen siendo de días anteriores).
 * El objetivo se calcula en PHP (America/Mazatlan); MySQL solo suma los segundos.
 */
function desplazarFechas(PDO $conexion): int
{
    $ultima = (string) $conexion->query('SELECT MAX(fecha) FROM monitoreos')->fetchColumn();
    $objetivo = time() - MINUTOS_DESDE_LA_ULTIMA_RONDA * 60;
    $segundos = $objetivo - (int) strtotime($ultima);

    $conexion->beginTransaction();
    foreach (COLUMNAS_DE_FECHA as $tabla => $columnas) {
        foreach ($columnas as $columna) {
            // Tablas y columnas vienen de la constante, nunca del usuario.
            $conexion->prepare("UPDATE $tabla SET $columna = DATE_ADD($columna, INTERVAL :segundos SECOND) WHERE $columna IS NOT NULL")
                ->execute(['segundos' => $segundos]);
        }
    }
    $conexion->commit();

    return $segundos;
}

/**
 * Cambia la IP del router y de PC Recepción, también en su historial, para que
 * Monitoreos no muestre IPs de otra red. Los estados del seed se conservan hasta
 * el "Monitorear todos" con que empieza el guion.
 */
function cambiarIps(PDO $conexion, string $router, string $pc): void
{
    $conexion->beginTransaction();
    foreach ([ID_ROUTER => $router, ID_PC_RECEPCION => $pc] as $id => $ip) {
        $conexion->prepare('UPDATE dispositivos SET ip = :ip WHERE id = :id')->execute(['ip' => $ip, 'id' => $id]);
        $conexion->prepare('UPDATE monitoreos SET ip = :ip WHERE dispositivo_id = :id')->execute(['ip' => $ip, 'id' => $id]);
    }
    $conexion->commit();
}

function formatoFecha(?string $fecha): string
{
    return $fecha === null ? '—' : date('d/m/Y H:i', (int) strtotime($fecha));
}

function mostrarResumen(PDO $conexion, array $ips, int $segundos): void
{
    $valor = fn(string $sql): ?string => ($v = $conexion->query($sql)->fetchColumn()) === false ? null : $v;
    $dias = round($segundos / 86400, 1);
    $urlCelular = 'http://' . $ips['pc'] . parse_url(URL_INTERFAZ, PHP_URL_PATH) . '/';

    echo "Base de datos recargada (schema.sql + seed.sql) y fechas movidas $dias días.\n\n";
    echo "Fechas\n";
    echo '  Primer monitoreo del historial ... ' . formatoFecha($valor('SELECT MIN(fecha) FROM monitoreos')) . "\n";
    echo '  Última ronda de monitoreo ........ ' . formatoFecha($valor('SELECT MAX(fecha) FROM monitoreos')) . ' (hace ' . MINUTOS_DESDE_LA_ULTIMA_RONDA . " min)\n";
    echo '  Alerta pendiente ................. ' . formatoFecha($valor('SELECT MAX(fecha) FROM alertas WHERE atendida = 0')) . "\n";
    echo '  Alertas atendidas ................ del ' . formatoFecha($valor('SELECT MIN(fecha) FROM alertas WHERE atendida = 1'))
        . ' al ' . formatoFecha($valor('SELECT MAX(fecha) FROM alertas WHERE atendida = 1')) . "\n\n";
    echo "IPs\n";
    echo "  Router principal ... {$ips['router']} ({$ips['origen_router']})\n";
    echo "  PC Recepción ....... {$ips['pc']} ({$ips['origen_pc']})\n\n";
    echo "Para entrar\n";
    echo '  En esta computadora ... ' . URL_INTERFAZ . "/\n";
    echo str_starts_with($ips['pc'], '127.')
        ? "  En el celular ......... sin red no hay URL para el celular (plan B: hotspot de un teléfono y volver a correr este script)\n"
        : "  En el celular ......... $urlCelular\n";
    echo "  Usuario ............... admin / admin123\n";
    echo "  Llamadas entre servicios en vivo: php herramientas/ver-llamadas.php\n";
    echo '  (registro: ' . archivoRegistroLlamadas() . ")\n\n";
}

/**
 * Comprobación previa: ✓ o ✗ por línea y, si algo falla, qué hacer. Un ⚠ avisa de
 * algo que no impide la demo (no cuenta como fallo).
 */
function comprobarTodo(PDO $conexion, array $ips): bool
{
    echo "Comprobación previa\n";
    $bien = true;
    $avisos = 0;
    $linea = function (bool $ok, string $texto, ?string $queHacer = null) use (&$bien): void {
        echo ($ok ? '  ✓ ' : '  ✗ ') . $texto . "\n";
        if (!$ok) {
            $bien = false;
            if ($queHacer !== null) {
                echo "      Qué hacer: $queHacer\n";
            }
        }
    };
    $aviso = function (string $texto, string $nota) use (&$avisos): void {
        $avisos++;
        echo "  ⚠ $texto\n      $nota\n";
    };

    [$codigoWeb] = pedirUrl(URL_INTERFAZ . '/login.html');
    $linea($codigoWeb === 200, 'Apache responde en ' . URL_INTERFAZ . "/ (HTTP $codigoWeb)", QUE_HACER['apache']);

    [$codigoSesion, $cuerpo] = pedirUrl(URL_AUTH . '/sesion');
    $json = json_decode($cuerpo, true);
    $linea($codigoSesion === 401 && ($json['ok'] ?? null) === false, "Los servicios contestan: GET /auth/sesion sin sesión → 401 (HTTP $codigoSesion)",
        $codigoWeb === 200 ? QUE_HACER['servicios'] : QUE_HACER['apache']);

    $conteos = [];
    foreach (array_keys(DATOS_DEL_SEED) as $tabla) {
        $conteos[$tabla] = (int) $conexion->query("SELECT COUNT(*) FROM $tabla")->fetchColumn();
    }
    $minutos = (int) round((time() - (int) strtotime((string) $conexion->query('SELECT MAX(fecha) FROM monitoreos')->fetchColumn())) / 60);
    $linea($conteos === DATOS_DEL_SEED && abs($minutos - MINUTOS_DESDE_LA_ULTIMA_RONDA) <= 1,
        sprintf('La base tiene los datos de la demo: %d dispositivos, %d monitoreos, %d alertas, %d usuario; última ronda hace %d min',
            $conteos['dispositivos'], $conteos['monitoreos'], $conteos['alertas'], $conteos['usuarios'], $minutos),
        QUE_HACER['mysql']);

    $ping = hacerPing($ips['router']);
    if ($ips['router'] === IP_ROUTER_SIN_RED) {
        $linea(!$ping['responde'], "Plan sin red: el router ({$ips['router']}) no responde, como se espera; servirá para mostrar la alerta");
    } else {
        $tiempo = $ping['responde'] ? ', ' . ($ping['tiempo_respuesta_ms'] === 0.0 ? '< 1' : $ping['tiempo_respuesta_ms']) . ' ms' : '';
        // Muchos routers y hotspots bloquean el ping: si está en la tabla ARP, sí está conectado.
        $mac = $ping['responde'] ? null : (tablaArpDelServicio()[$ips['router']] ?? null);
        if ($mac !== null) {
            $aviso(
                "El router bloquea el ping, pero está conectado (aparece en ARP): {$ips['router']}, MAC $mac",
                'En la demo, «Monitorear todos» lo mostrará Inactivo con una alerta (el contador llega a 4); «Buscar dispositivos en la red» sí lo encuentra.'
            );
        } else {
            $linea($ping['responde'], "El router ({$ips['router']}) " . ($ping['responde'] ? 'responde' : 'no responde') . " al ping (hacerPing de ping.php$tiempo)", QUE_HACER['router']);
        }
    }
    $pingPc = hacerPing($ips['pc']);
    $linea($pingPc['responde'], "PC Recepción ({$ips['pc']}) " . ($pingPc['responde'] ? 'responde' : 'no responde') . ' al ping', 'Es esta computadora: revisa la IP indicada con --pc.');

    echo "\nRecordatorios (no se pueden comprobar desde aquí)\n";
    if (!str_starts_with($ips['pc'], '127.')) {
        echo PHP_OS_FAMILY === 'Windows'
            ? "  • Firewall de Windows: permite Apache (httpd.exe) en redes privadas para que entre el celular.\n"
            : '  • Cortafuegos: si el celular no carga la página, sudo ufw allow from ' . preg_replace('/\.\d+$/', '.0', $ips['pc']) . "/24 to any port 80 proto tcp (README).\n";
        echo "  • El celular debe estar en la misma red WiFi que esta computadora.\n";
    }
    echo "  • Inicia sesión en la computadora y en el celular antes de empezar.\n";
    echo "  • El ping de esta comprobación corre con tu usuario; el de la demo lo hace Apache (normalmente da lo mismo).\n";

    if (!$bien) {
        echo "\nHay comprobaciones con ✗: revisa «Qué hacer» antes de presentar.\n";
    } else {
        echo $avisos > 0 ? "\nTodo listo para la demo (lee los avisos con ⚠).\n" : "\nTodo listo para la demo.\n";
    }

    return $bien;
}

/**
 * GET sencillo: [código HTTP (0 si no hubo respuesta), cuerpo].
 */
function pedirUrl(string $url): array
{
    $contexto = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    $cuerpo = @file_get_contents($url, false, $contexto);

    return [$cuerpo === false ? 0 : codigoHttp($http_response_header ?? []), (string) $cuerpo];
}
