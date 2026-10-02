<?php
/**
 * Prepara la demo (docs/DEMO.md).
 *
 * Sin opciones NO cambia la base: comprueba que todo responde y que tu base está
 * lista para presentar (tus dispositivos, historial reciente, el router y esta
 * computadora registrados) y muestra la URL para el celular.
 *
 *   /opt/lampp/bin/php herramientas/preparar-demo.php                      detecta el router y la IP propia
 *   /opt/lampp/bin/php herramientas/preparar-demo.php --router=IP --pc=IP  usa esas IPs para la comprobación
 *
 * Con --datos-de-ejemplo carga los datos de ejemplo (seed.sql) EN LUGAR de los tuyos,
 * como en la versión 1.0: mueve sus fechas a hace unos minutos y pone las IPs del
 * router y de "PC Recepción". Antes guarda un respaldo de tu base en respaldos/ y
 * pide escribir SI; para volver: herramientas/restaurar-respaldo.php.
 *
 *   /opt/lampp/bin/php herramientas/preparar-demo.php --datos-de-ejemplo [--router=IP --pc=IP | --sin-red]
 *   (Windows: C:/xampp/php/php.exe herramientas/preparar-demo.php …)
 *
 * Termina con código 0 si todas las comprobaciones pasan (un ⚠ es solo un aviso),
 * 1 si alguna falla o se canceló, y 2 si no pudo empezar (opciones, MySQL).
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
const MINUTOS_HISTORIAL_RECIENTE = 60;
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
const COMANDO_RONDAS = 'php herramientas/monitorear-rondas.php --rondas=12 --cada=60';

// Qué hacer si algo falla (los mismos casos que la tabla "Si pasa X, haz Y" de docs/DEMO.md).
const QUE_HACER = [
    'apache' => PHP_OS_FAMILY === 'Windows'
        ? 'Enciende Apache en el panel de XAMPP (botón Start de Apache).'
        : 'Enciende Apache: sudo /opt/lampp/lampp startapache',
    'servicios' => 'Apache responde pero los servicios no: revisa que la carpeta se llame monitoreo-red-soa dentro de htdocs y que AllowOverride permita los .htaccess (README, "Instalación").',
    'mysql' => PHP_OS_FAMILY === 'Windows'
        ? 'Enciende MySQL en el panel de XAMPP. Si no arranca, revisa el registro de MySQL en el panel.'
        : 'Enciende MySQL: sudo /opt/lampp/lampp startmysql. Si no arranca, agrega tmpdir = /tmp debajo de [mysqld] en /opt/lampp/etc/my.cnf (README).',
    'base' => 'Crea tu base vacía: php herramientas/crear-base.php',
    'router' => 'El router no responde al ping: comprueba que la computadora esté conectada a la red de la demo (el hotspot) o indica la IP con --router=IP.',
    'red' => 'No se detectó la red: conecta la computadora al hotspot de la demo o indica las IPs con --router=IP --pc=IP.',
];

$opciones = leerOpciones($argv);
if ($opciones === null) {
    fwrite(STDERR, "Uso: php herramientas/preparar-demo.php [--router=IP] [--pc=IP]\n"
        . "     php herramientas/preparar-demo.php --datos-de-ejemplo [--router=IP] [--pc=IP] [--sin-red]\n");
    exit(2);
}

if (!mysqlResponde()) {
    fwrite(STDERR, "✗ No se pudo conectar con MySQL.\n  Qué hacer: " . QUE_HACER['mysql'] . "\n");
    exit(2);
}

echo "Preparando la demo del Sistema de Monitoreo de Red SOA\n\n";

if ($opciones['datos_de_ejemplo']) {
    exit(cargarDatosDeEjemplo($opciones) ? 0 : 1);
}

$ips = ['router' => $opciones['router'] ?? detectarPuertaDeEnlace(), 'pc' => $opciones['pc'] ?? detectarIpPropia()];
$errorIps = validarIps($ips, false);
if ($errorIps !== null) {
    fwrite(STDERR, "✗ $errorIps\n");
    exit(2);
}

mostrarTuBase(nombreDeLaBase(), $ips);
exit(comprobarTuBase(nombreDeLaBase(), $ips) ? 0 : 1);

// =====================================================================

/**
 * --router=IP, --pc=IP, --datos-de-ejemplo y --sin-red (este último, solo con los
 * datos de ejemplo). Devuelve null si hay una opción desconocida o incompatible.
 */
function leerOpciones(array $argumentos): ?array
{
    $opciones = ['router' => null, 'pc' => null, 'sin_red' => false, 'datos_de_ejemplo' => false];

    foreach (array_slice($argumentos, 1) as $argumento) {
        if ($argumento === '--sin-red') {
            $opciones['sin_red'] = true;
        } elseif ($argumento === '--datos-de-ejemplo') {
            $opciones['datos_de_ejemplo'] = true;
        } elseif (preg_match('/^--(router|pc)=(.*)$/', $argumento, $c) === 1) {
            $opciones[$c[1]] = trim($c[2]);
        } else {
            return null;
        }
    }

    return $opciones['sin_red'] && !$opciones['datos_de_ejemplo'] ? null : $opciones;
}

/**
 * Error de las IPs, o null. En los datos de ejemplo las dos son obligatorias y distintas.
 */
function validarIps(array $ips, bool $obligatorias): ?string
{
    foreach (['router' => $ips['router'], 'pc' => $ips['pc']] as $nombre => $ip) {
        if ($ip === null && !$obligatorias) {
            continue;
        }
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return "La IP de --$nombre no es una IPv4 válida: «{$ip}».";
        }
    }

    return $ips['router'] !== null && $ips['router'] === $ips['pc'] ? "El router y esta computadora no pueden tener la misma IP ({$ips['router']})." : null;
}

// ---------------------------------------------------------------- Tu base (sin cambiarla)

/**
 * Resumen de tu base y URLs para entrar.
 */
function mostrarTuBase(string $base, array $ips): void
{
    echo "Tu base ($base) no se modifica.\n\n";

    if (existeBase($base)) {
        $dispositivos = obtenerConexion()->query('SELECT nombre, ip, estado FROM dispositivos ORDER BY nombre')->fetchAll();
        echo 'Dispositivos (' . count($dispositivos) . ")\n";
        foreach ($dispositivos as $d) {
            printf("  %-28s %-16s %s\n", $d['nombre'], $d['ip'], $d['estado']);
        }
        echo $dispositivos === [] ? "  (ninguno todavía)\n\n" : "\n";
    }

    mostrarUrls($ips['pc']);
}

/**
 * Comprobación previa sobre tu base. Devuelve false si algo sale con ✗.
 */
function comprobarTuBase(string $base, array $ips): bool
{
    $previa = iniciarComprobacionPrevia();

    $conteos = contarFilas($base);
    if ($conteos === null) {
        $previa->linea(false, "La base $base no existe", QUE_HACER['base']);
        return $previa->terminar();
    }

    $pendientes = (int) obtenerConexion()->query('SELECT COUNT(*) FROM alertas WHERE atendida = 0')->fetchColumn();
    if ($conteos['dispositivos'] === 0) {
        $previa->aviso("Tu base no tiene dispositivos todavía", 'Agrégalos en Dispositivos con «Buscar dispositivos en la red» (docs/DEMO.md, «Antes de empezar»).');
    } else {
        $previa->linea(true, 'Tu base tiene ' . cantidad($conteos['dispositivos'], 'dispositivo', 'dispositivos') . ', '
            . cantidad($conteos['monitoreos'], 'monitoreo', 'monitoreos') . ' y ' . cantidad($pendientes, 'alerta pendiente', 'alertas pendientes'));
    }

    $ultimo = obtenerConexion()->query('SELECT MAX(fecha) FROM monitoreos')->fetchColumn();
    $minutos = $ultimo ? (int) round((time() - (int) strtotime((string) $ultimo)) / 60) : null;
    if ($minutos === null || $minutos > MINUTOS_HISTORIAL_RECIENTE) {
        $previa->aviso(
            $minutos === null ? 'Todavía no hay historial de monitoreos' : 'El último monitoreo fue hace ' . $minutos . ' min (' . formatoFecha((string) $ultimo) . ')',
            'Para que el historial y las gráficas tengan datos, con los teléfonos con la pantalla encendida: ' . COMANDO_RONDAS
        );
    } else {
        $previa->linea(true, "Hay historial reciente: el último monitoreo fue hace $minutos min");
    }

    if ($ips['router'] === null || $ips['pc'] === null) {
        $previa->linea(false, 'No se detectó la red de esta computadora', QUE_HACER['red']);
        return $previa->terminar();
    }

    comprobarRouter($previa, $ips['router']);
    comprobarRegistrado($previa, $ips['router'], 'El router', 'sale con tipo Router');
    $pingPc = hacerPing($ips['pc']);
    $previa->linea($pingPc['responde'], "Esta computadora ({$ips['pc']}) " . ($pingPc['responde'] ? 'responde' : 'no responde') . ' al ping', 'Revisa la IP indicada con --pc.');
    comprobarRegistrado($previa, $ips['pc'], 'Esta computadora', 'sale marcada «Esta computadora»');

    return $previa->terminar($ips['pc']);
}

/**
 * ⚠ si la IP no es de ningún dispositivo de tu base; ✓ con su nombre si lo es.
 */
function comprobarRegistrado(object $previa, string $ip, string $quien, string $pista): void
{
    $consulta = obtenerConexion()->prepare('SELECT nombre FROM dispositivos WHERE ip = :ip');
    $consulta->execute(['ip' => $ip]);
    $nombre = $consulta->fetchColumn();

    if ($nombre === false) {
        $previa->aviso("$quien ($ip) no está en tu base", "Agrégalo con «Buscar dispositivos en la red» ($pista).");
    } else {
        $previa->linea(true, "$quien ($ip) está en tu base como «{$nombre}»");
    }
}

// ---------------------------------------------------------------- Datos de ejemplo (a pedido)

/**
 * Respalda tu base, pide confirmación y carga los datos de ejemplo como en la
 * versión 1.0. Devuelve false si se canceló o alguna comprobación falla.
 */
function cargarDatosDeEjemplo(array $opciones): bool
{
    $ips = elegirIpsDeEjemplo($opciones);
    $errorIps = validarIps($ips, true);
    if ($errorIps !== null) {
        fwrite(STDERR, "✗ $errorIps\n  No se tocó la base de datos.\n");
        exit(2);
    }
    $choque = ipDeOtroDispositivoDelSeed($ips);
    if ($choque !== null) {
        fwrite(STDERR, "✗ $choque\n  No se tocó la base de datos; elige otra IP.\n");
        exit(2);
    }

    $base = nombreDeLaBase();
    $conteos = contarFilas($base);
    if ($conteos !== null) {
        echo 'Respaldo de tu base: ' . respaldarBase($base, 'antes-de-datos-de-ejemplo') . "\n\n";
    }
    if (!pedirConfirmacion("Tu base $base (" . describirConteos($conteos) . ') se reemplazará por los DATOS DE EJEMPLO.')) {
        echo "Cancelado: la base $base no cambió.\n";
        return false;
    }
    echo "\n";

    recrearBase($base, [ARCHIVO_SCHEMA, ARCHIVO_INICIAL, ARCHIVO_SEED]);
    $conexion = obtenerConexion();
    $segundos = desplazarFechas($conexion);
    cambiarIps($conexion, $ips['router'], $ips['pc']);

    mostrarResumenDeEjemplo($conexion, $ips, $segundos);

    return comprobarDatosDeEjemplo($conexion, $ips);
}

/**
 * Las IPs indicadas, o las detectadas; sin red (o si no se detectan), las del plan B.
 */
function elegirIpsDeEjemplo(array $opciones): array
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

/**
 * La IP elegida no puede ser la de otro dispositivo del seed (la columna ip es UNIQUE).
 * Se revisa en seed.sql antes de tocar la base.
 */
function ipDeOtroDispositivoDelSeed(array $ips): ?string
{
    preg_match_all("/^\\s*\\((\\d+), '([^']+)', '([\\d.]+)'/m", (string) file_get_contents(ARCHIVO_SEED), $filas, PREG_SET_ORDER);

    foreach (['router' => $ips['router'], 'pc' => $ips['pc']] as $nombre => $ip) {
        foreach ($filas as [, $id, $nombreDelSeed, $ipDelSeed]) {
            if ($ipDelSeed === $ip && !in_array((int) $id, [ID_ROUTER, ID_PC_RECEPCION], true)) {
                return "La IP de --$nombre ($ip) ya es de «{$nombreDelSeed}» en los datos de ejemplo.";
            }
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
 * Monitoreos no muestre IPs de otra red.
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

function mostrarResumenDeEjemplo(PDO $conexion, array $ips, int $segundos): void
{
    $valor = fn(string $sql): ?string => ($v = $conexion->query($sql)->fetchColumn()) === false ? null : $v;
    $dias = round($segundos / 86400, 1);

    echo "Datos de ejemplo cargados (schema.sql + inicial.sql + seed.sql) y fechas movidas $dias días.\n\n";
    echo "Fechas\n";
    echo '  Primer monitoreo del historial ... ' . formatoFecha($valor('SELECT MIN(fecha) FROM monitoreos')) . "\n";
    echo '  Última ronda de monitoreo ........ ' . formatoFecha($valor('SELECT MAX(fecha) FROM monitoreos')) . ' (hace ' . MINUTOS_DESDE_LA_ULTIMA_RONDA . " min)\n";
    echo '  Alerta pendiente ................. ' . formatoFecha($valor('SELECT MAX(fecha) FROM alertas WHERE atendida = 0')) . "\n";
    echo '  Alertas atendidas ................ del ' . formatoFecha($valor('SELECT MIN(fecha) FROM alertas WHERE atendida = 1'))
        . ' al ' . formatoFecha($valor('SELECT MAX(fecha) FROM alertas WHERE atendida = 1')) . "\n\n";
    echo "IPs\n";
    echo "  Router principal ... {$ips['router']} ({$ips['origen_router']})\n";
    echo "  PC Recepción ....... {$ips['pc']} ({$ips['origen_pc']})\n\n";
    mostrarUrls($ips['pc']);
}

function comprobarDatosDeEjemplo(PDO $conexion, array $ips): bool
{
    $previa = iniciarComprobacionPrevia();

    $conteos = [];
    foreach (array_keys(DATOS_DEL_SEED) as $tabla) {
        $conteos[$tabla] = (int) $conexion->query("SELECT COUNT(*) FROM $tabla")->fetchColumn();
    }
    $minutos = (int) round((time() - (int) strtotime((string) $conexion->query('SELECT MAX(fecha) FROM monitoreos')->fetchColumn())) / 60);
    $previa->linea($conteos === DATOS_DEL_SEED && abs($minutos - MINUTOS_DESDE_LA_ULTIMA_RONDA) <= 1,
        sprintf('La base tiene los datos de ejemplo: %d dispositivos, %d monitoreos, %d alertas, %d usuario; última ronda hace %d min',
            $conteos['dispositivos'], $conteos['monitoreos'], $conteos['alertas'], $conteos['usuarios'], $minutos),
        QUE_HACER['mysql']);

    if ($ips['router'] === IP_ROUTER_SIN_RED) {
        $previa->linea(!hacerPing($ips['router'])['responde'], "Plan sin red: el router ({$ips['router']}) no responde, como se espera; servirá para mostrar la alerta");
    } else {
        comprobarRouter($previa, $ips['router']);
    }
    $pingPc = hacerPing($ips['pc']);
    $previa->linea($pingPc['responde'], "PC Recepción ({$ips['pc']}) " . ($pingPc['responde'] ? 'responde' : 'no responde') . ' al ping', 'Es esta computadora: revisa la IP indicada con --pc.');

    return $previa->terminar($ips['pc']);
}

// ---------------------------------------------------------------- Piezas comunes

/**
 * Empieza la comprobación previa (Apache y servicios) y devuelve el objeto con
 * linea() (✓ o ✗ con «Qué hacer»), aviso() (⚠, no cuenta como fallo) y terminar().
 */
function iniciarComprobacionPrevia(): object
{
    echo "Comprobación previa\n";

    $previa = new class {
        public bool $bien = true;
        public int $avisos = 0;

        public function linea(bool $ok, string $texto, ?string $queHacer = null): void
        {
            echo ($ok ? '  ✓ ' : '  ✗ ') . $texto . "\n";
            if (!$ok) {
                $this->bien = false;
                if ($queHacer !== null) {
                    echo "      Qué hacer: $queHacer\n";
                }
            }
        }

        public function aviso(string $texto, string $nota): void
        {
            $this->avisos++;
            echo "  ⚠ $texto\n      $nota\n";
        }

        /** Recordatorios y veredicto final. */
        public function terminar(?string $ipPc = null): bool
        {
            mostrarRecordatorios($ipPc);
            if (!$this->bien) {
                echo "\nHay comprobaciones con ✗: revisa «Qué hacer» antes de presentar.\n";
            } else {
                echo $this->avisos > 0 ? "\nTodo listo para la demo (lee los avisos con ⚠).\n" : "\nTodo listo para la demo.\n";
            }

            return $this->bien;
        }
    };

    [$codigoWeb] = pedirUrl(URL_INTERFAZ . '/login.html');
    $previa->linea($codigoWeb === 200, 'Apache responde en ' . URL_INTERFAZ . "/ (HTTP $codigoWeb)", QUE_HACER['apache']);

    [$codigoSesion, $cuerpo] = pedirUrl(URL_AUTH . '/sesion');
    $json = json_decode($cuerpo, true);
    $previa->linea($codigoSesion === 401 && ($json['ok'] ?? null) === false, "Los servicios contestan: GET /auth/sesion sin sesión → 401 (HTTP $codigoSesion)",
        $codigoWeb === 200 ? QUE_HACER['servicios'] : QUE_HACER['apache']);

    return $previa;
}

/**
 * Ping al router; si no responde pero está en la tabla ARP (muchos hotspots
 * bloquean el ping), es un ⚠, no un ✗.
 */
function comprobarRouter(object $previa, string $router): void
{
    $ping = hacerPing($router);
    $tiempo = $ping['responde'] ? ', ' . ($ping['tiempo_respuesta_ms'] === 0.0 ? '< 1' : $ping['tiempo_respuesta_ms']) . ' ms' : '';
    $mac = $ping['responde'] ? null : (tablaArpDelServicio()[$router] ?? null);

    if ($mac !== null) {
        $previa->aviso(
            "El router bloquea el ping, pero está conectado (aparece en ARP): $router, MAC $mac",
            'En la demo, «Monitorear todos» lo mostrará Inactivo con una alerta; «Buscar dispositivos en la red» sí lo encuentra.'
        );
    } else {
        $previa->linea($ping['responde'], "El router ($router) " . ($ping['responde'] ? 'responde' : 'no responde') . " al ping (hacerPing de ping.php$tiempo)", QUE_HACER['router']);
    }
}

function mostrarUrls(?string $ipPc): void
{
    echo "Para entrar\n";
    echo '  En esta computadora ... ' . URL_INTERFAZ . "/\n";
    echo $ipPc === null || str_starts_with($ipPc, '127.')
        ? "  En el celular ......... sin red no hay URL para el celular (conecta la computadora al hotspot y vuelve a correr este script)\n"
        : '  En el celular ......... http://' . $ipPc . parse_url(URL_INTERFAZ, PHP_URL_PATH) . "/\n";
    echo "  Usuario ............... admin / admin123\n";
    echo "  Llamadas entre servicios en vivo: php herramientas/ver-llamadas.php\n";
    echo '  (registro: ' . archivoRegistroLlamadas() . ")\n\n";
}

function mostrarRecordatorios(?string $ipPc): void
{
    echo "\nRecordatorios (no se pueden comprobar desde aquí)\n";
    if ($ipPc !== null && !str_starts_with($ipPc, '127.')) {
        echo PHP_OS_FAMILY === 'Windows'
            ? "  • Firewall de Windows: permite Apache (httpd.exe) en redes privadas para que entre el celular.\n"
            : '  • Cortafuegos: si el celular no carga la página, sudo ufw allow from ' . preg_replace('/\.\d+$/', '.0', $ipPc) . "/24 to any port 80 proto tcp (README).\n";
        echo "  • El celular debe estar en la misma red WiFi que esta computadora.\n";
    }
    echo "  • Los teléfonos registrados deben tener la pantalla encendida: muchos no responden al ping con la pantalla apagada.\n";
    echo "  • Inicia sesión en la computadora y en el celular antes de empezar.\n";
    echo "  • El ping de esta comprobación corre con tu usuario; el de la demo lo hace Apache (normalmente da lo mismo).\n";
}

/**
 * "1 alerta pendiente" / "3 alertas pendientes".
 */
function cantidad(int $numero, string $singular, string $plural): string
{
    return $numero . ' ' . ($numero === 1 ? $singular : $plural);
}

function formatoFecha(?string $fecha): string
{
    return $fecha === null ? '—' : date('d/m/Y H:i', (int) strtotime($fecha));
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
