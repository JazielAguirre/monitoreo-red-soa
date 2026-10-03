<?php
/**
 * Funciones comunes de las pruebas automáticas (docs/PLAN.md, "Pruebas automáticas"):
 * peticiones a través de Apache, comprobaciones y recarga de la base de datos.
 *
 * Las suites trabajan en su propia base (DB_NOMBRE_PRUEBAS), nunca en la del
 * usuario: al cargar este archivo se activa pruebas/.base-de-pruebas.json (ver
 * comun/db.php), que los servicios, a través de Apache, y las herramientas de
 * terminal también respetan. Al terminar, cada suite comprueba que la base del
 * usuario quedó idéntica.
 *
 * Solo para línea de comandos:
 *   Linux:   /opt/lampp/bin/php pruebas/<servicio>.php
 *   Windows: C:/xampp/php/php.exe pruebas/<servicio>.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../servicios/comun/config.php';
require_once __DIR__ . '/../servicios/comun/db.php';
require_once __DIR__ . '/../servicios/comun/http.php'; // ARCHIVO_FALLO_SIMULADO
require_once __DIR__ . '/../herramientas/base-de-datos.php'; // cargarSchemaYSeed()
require_once __DIR__ . '/../servicios/descubrimiento/simulacion.php'; // ARCHIVO_RED_SIMULADA
require_once __DIR__ . '/../servicios/descubrimiento/arp.php'; // formatoArpDelSistema()

$GLOBALS['pruebas_correctas'] = 0;
$GLOBALS['pruebas_fallidas'] = [];
$GLOBALS['pruebas_avisos'] = [];
$GLOBALS['cookie_sesion'] = null; // id de la sesión de iniciarSesionDePrueba()

// ---------------------------------------------------------------- Base de pruebas

const RENOVAR_BASE_DE_PRUEBAS_CADA = 15; // segundos; el archivo dura 60

activarBaseDePruebas();

/**
 * Toma la huella de la base del usuario y activa la base de pruebas. Si ya la
 * activó otra suite (el ensayo del guion corre pruebas/dispositivos.php dentro de
 * pruebas/interfaz.php), la usa sin borrarla al terminar: la borra quien la activó.
 */
function activarBaseDePruebas(): void
{
    // Todo lo que lancen las pruebas queda marcado: si la base de pruebas caducara,
    // los servicios y las herramientas se niegan a usar la del usuario (comun/db.php).
    putenv('MONITOREO_PRUEBAS=1');
    $GLOBALS['huella_de_tu_base'] = huellaDeTuBase();
    $GLOBALS['huella_comprobada'] = false;
    $GLOBALS['base_de_pruebas_propia'] = !baseDePruebasActiva();
    escribirArchivoBaseDePruebas();
    terminarAlInterrumpir();
    // Registrada desde una función de cierre, corre después de todas las demás
    // (cerrar Chrome, quitar el fallo simulado…): hasta el final se usa la base de pruebas.
    register_shutdown_function(fn() => register_shutdown_function('cerrarBaseDePruebas'));
}

function escribirArchivoBaseDePruebas(): void
{
    file_put_contents(ARCHIVO_BASE_DE_PRUEBAS, json_encode(['expira' => time() + VIGENCIA_MAXIMA_BASE_DE_PRUEBAS]));
}

/**
 * Renueva la base de pruebas cada RENOVAR_BASE_DE_PRUEBAS_CADA segundos. La llaman
 * las peticiones, las comprobaciones y el Chrome de las pruebas mientras espera.
 * Si el archivo ya no está vigente (pasó más de un minuto sin renovarse), detiene
 * las pruebas: seguir mandaría las peticiones a la base del usuario.
 */
function renovarBaseDePruebas(): void
{
    static $ultima = 0;

    if (!empty($GLOBALS['renovacion_en_pausa']) || time() - $ultima < RENOVAR_BASE_DE_PRUEBAS_CADA) {
        return;
    }

    if (!baseDePruebasActiva()) {
        fwrite(STDERR, "\n\u{2717} La base de pruebas se desactivó (pasó más de un minuto sin renovarse). Las pruebas se detienen para no tocar tu base (" . DB_NOMBRE . ").\n");
        exit(2);
    }

    escribirArchivoBaseDePruebas();
    $ultima = time();
}

/**
 * Al cerrar: si la suite terminó antes de comprobarlo (error, Ctrl+C), muestra si
 * la base del usuario quedó idéntica; después desactiva la base de pruebas si la
 * activó esta suite.
 */
function cerrarBaseDePruebas(): void
{
    if (!$GLOBALS['huella_comprobada']) {
        $identica = huellaDeTuBase() === $GLOBALS['huella_de_tu_base'];
        echo ($identica ? "\u{2713}" : "\u{2717}") . ' Tu base (' . DB_NOMBRE . ') ' . ($identica ? 'quedó idéntica.' : 'CAMBIÓ durante las pruebas.') . "\n";
    }

    if ($GLOBALS['base_de_pruebas_propia']) {
        borrarRespaldosDePrueba(); // solo los de la base de pruebas; los del usuario nunca
        if (is_file(ARCHIVO_BASE_DE_PRUEBAS)) {
            unlink(ARCHIVO_BASE_DE_PRUEBAS);
        }
    }
}

function huellaDeTuBase(): array|string
{
    return huellaDeLaBase(DB_NOMBRE);
}

/**
 * Huella de una base: por tabla, filas, CHECKSUM TABLE y el siguiente
 * AUTO_INCREMENT (cambia aunque se agregue y se borre una fila). Un texto si la
 * base no existe o MySQL no responde.
 */
function huellaDeLaBase(string $base): array|string
{
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PUERTO, DB_CHARSET), DB_USUARIO, DB_CLAVE, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException) {
        return 'MySQL no responde';
    }

    $consulta = $pdo->prepare('SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = :base ORDER BY TABLE_NAME');
    $consulta->execute(['base' => $base]);
    $tablas = $consulta->fetchAll(PDO::FETCH_KEY_PAIR);

    if ($tablas === []) {
        return 'no existe o no tiene tablas';
    }

    $huella = [];
    foreach ($tablas as $tabla => $siguienteId) {
        $nombre = '`' . str_replace('`', '``', $base) . '`.`' . str_replace('`', '``', $tabla) . '`';
        $filas = (int) $pdo->query("SELECT COUNT(*) FROM $nombre")->fetchColumn();
        $checksum = $pdo->query("CHECKSUM TABLE $nombre")->fetch(PDO::FETCH_NUM)[1];
        $huella[$tabla] = "$filas filas, checksum $checksum, siguiente id $siguienteId";
    }

    return $huella;
}

/**
 * Consulta de solo lectura en la base del usuario (DB_NOMBRE), con su propia
 * conexión. Solo para comprobar que el interruptor se desactiva solo.
 */
function filasBdUsuario(string $sql): array
{
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PUERTO, DB_NOMBRE, DB_CHARSET), DB_USUARIO, DB_CLAVE, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    return $pdo->query($sql)->fetchAll();
}

/**
 * Recrea la base de PRUEBAS con schema.sql, inicial.sql y seed.sql (cargarSchemaYSeed()
 * se niega a tocar otra base). Se puede indicar otro binario con MYSQL_BIN.
 */
function recargarBaseDeDatos(): void
{
    renovarBaseDePruebas();
    cargarSchemaYSeed();
}

/**
 * Con Ctrl+C (o SIGTERM) el script termina con exit(), así se ejecutan las
 * funciones de cierre (cerrar el Chrome de las pruebas, desactivar la base de pruebas).
 */
function terminarAlInterrumpir(): void
{
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, fn() => exit(130));
        pcntl_signal(SIGTERM, fn() => exit(143));
    } elseif (function_exists('sapi_windows_set_ctrl_handler')) {
        sapi_windows_set_ctrl_handler(fn() => exit(130));
    }
}

/**
 * Ejecuta una consulta de verificación directa a la base y devuelve un solo valor.
 */
function valorBd(string $sql, array $parametros = []): mixed
{
    renovarBaseDePruebas();
    $consulta = obtenerConexion()->prepare($sql);
    $consulta->execute($parametros);

    return $consulta->fetchColumn();
}

/**
 * Ejecuta una consulta de verificación y devuelve todas sus filas.
 */
function filasBd(string $sql, array $parametros = []): array
{
    renovarBaseDePruebas();
    $consulta = obtenerConexion()->prepare($sql);
    $consulta->execute($parametros);

    return $consulta->fetchAll();
}

// ---------------------------------------------------------------- Fallo simulado

/**
 * Activa un fallo simulado por unos segundos (ver ARCHIVO_FALLO_SIMULADO en comun/http.php).
 */
function simularFallo(string $servicio, string $modo, ?string $metodo = null, ?int $puerto = null, int $segundos = 30): void
{
    $fallo = array_filter(
        ['servicio' => $servicio, 'metodo' => $metodo, 'modo' => $modo, 'puerto' => $puerto, 'expira' => time() + $segundos],
        fn($valor) => $valor !== null
    );
    escribirArchivoFallo($fallo);
}

function escribirArchivoFallo(array $contenido): void
{
    file_put_contents(ARCHIVO_FALLO_SIMULADO, json_encode($contenido));
}

function quitarFalloSimulado(): void
{
    if (is_file(ARCHIVO_FALLO_SIMULADO)) {
        unlink(ARCHIVO_FALLO_SIMULADO);
    }
}

/**
 * Un puerto de 127.0.0.1 donde nadie escucha (conexión rechazada).
 */
function puertoCerrado(): int
{
    $servidor = stream_socket_server('tcp://127.0.0.1:0');
    $puerto = (int) substr(strrchr(stream_socket_get_name($servidor, false), ':'), 1);
    fclose($servidor);

    return $puerto;
}

/**
 * Abre un socket en 127.0.0.1 que acepta conexiones pero nunca responde:
 * quien le hace una petición espera hasta agotar su tiempo.
 * Devuelve [socket, puerto]; el socket debe seguir abierto durante la prueba.
 */
function socketQueNoResponde(): array
{
    $servidor = stream_socket_server('tcp://127.0.0.1:0');

    return [$servidor, (int) substr(strrchr(stream_socket_get_name($servidor, false), ':'), 1)];
}

// ---------------------------------------------------------------- Red simulada (Servicio de Descubrimiento)

/**
 * Escribe la red simulada por 30 segundos. $datos: ip_propia, puerta_enlace, arp.
 */
function simularRed(array $datos): void
{
    file_put_contents(ARCHIVO_RED_SIMULADA, json_encode($datos + ['expira' => time() + 30]));
}

function quitarRedSimulada(): void
{
    if (is_file(ARCHIVO_RED_SIMULADA)) {
        unlink(ARCHIVO_RED_SIMULADA);
    }
}

/**
 * Tabla ARP de ejemplo en el formato del sistema operativo donde corre Apache
 * (el mismo que el de esta prueba). $entradas: [ip, mac con ":", estado].
 * Los estados FAILED/INCOMPLETE se escriben como el sistema los muestra.
 */
function tablaArpDeEjemplo(array $entradas): string
{
    $lineas = [];
    $formato = formatoArpDelSistema();

    if ($formato === FORMATO_WINDOWS) {
        $lineas[] = '';
        $lineas[] = 'Interfaz: 192.0.2.1 --- 0xb';
        $lineas[] = '  Dirección de Internet          Dirección física      Tipo';
    }

    foreach ($entradas as [$ip, $mac, $estado]) {
        $incompleta = in_array($estado, ['FAILED', 'INCOMPLETE'], true);
        $lineas[] = match ($formato) {
            FORMATO_WINDOWS => $incompleta ? "  $ip            00-00-00-00-00-00     no válido" : sprintf('  %-22s%-22sdinámico', $ip, str_replace(':', '-', $mac)),
            FORMATO_MAC => $incompleta ? "? ($ip) at (incomplete) on en0 ifscope [ethernet]" : "? ($ip) at $mac on en0 ifscope [ethernet]",
            default => $incompleta ? "$ip dev eth0  $estado" : "$ip dev eth0 lladdr $mac $estado",
        };
    }

    return implode("\n", $lineas) . "\n";
}

// ---------------------------------------------------------------- Herramientas de terminal

/**
 * Corre una herramienta PHP del proyecto (ruta relativa a la raíz) con la base de
 * pruebas activa. $entrada es lo que se escribiría en la terminal (por ejemplo
 * "SI\n" para confirmar); sin entrada, la herramienta lee el fin del archivo.
 * Devuelve [código de salida, salida (stdout y stderr)].
 */
function correrHerramienta(string $ruta, string $argumentos = '', string $entrada = ''): array
{
    renovarBaseDePruebas();
    $comando = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . "/$ruta") . " $argumentos 2>&1";
    $proceso = proc_open($comando, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $tuberias);
    fwrite($tuberias[0], $entrada);
    fclose($tuberias[0]);
    $salida = (string) stream_get_contents($tuberias[1]);
    fclose($tuberias[1]);

    return [proc_close($proceso), $salida];
}

/**
 * Respaldos que guardaron las herramientas de la base de PRUEBAS durante la suite.
 * Los respaldos de la base del usuario nunca se tocan.
 */
function respaldosDePrueba(): array
{
    return glob(CARPETA_RESPALDOS . '/' . DB_NOMBRE_PRUEBAS . '-*.sql') ?: [];
}

function borrarRespaldosDePrueba(): void
{
    array_map('unlink', respaldosDePrueba());
}

// ---------------------------------------------------------------- Peticiones

/**
 * Inicia sesión con el usuario del seed a través de POST /auth/login y guarda la
 * cookie: desde aquí, peticion() la envía en cada llamada (como el navegador).
 */
function iniciarSesionDePrueba(string $usuario = 'admin', string $contrasena = 'admin123'): void
{
    $r = peticion('POST', '/auth/login', ['usuario' => $usuario, 'contrasena' => $contrasena], ['sesion' => false]);

    if ($r['codigo'] !== 200 || $r['cookie'] === null) {
        fwrite(STDERR, "No se pudo iniciar sesión como $usuario (HTTP {$r['codigo']}): {$r['texto']}\n");
        exit(2);
    }

    $GLOBALS['cookie_sesion'] = $r['cookie'];
}

/**
 * Petición a un endpoint [Interno], como la haría otro servicio: con la clave
 * X-Servicio-Clave y sin la cookie del navegador.
 */
function peticionInterna(string $metodo, string $ruta, array|string|null $cuerpo = null): array
{
    return peticion($metodo, $ruta, $cuerpo, ['sesion' => false, 'clave' => true]);
}

/**
 * Cabeceras de una petición de prueba. $opciones:
 * - 'sesion': true (por defecto) envía la cookie de iniciarSesionDePrueba(), false
 *   ninguna, o un texto con el valor completo de la cabecera Cookie.
 * - 'clave': true envía la CLAVE_SERVICIO de config.php, o un texto con otra clave.
 * - 'pruebas': false omite la marca X-Pruebas-Automaticas (como el navegador del usuario).
 */
function cabecerasDePrueba(array $opciones): array
{
    $cabeceras = ['Content-Type: application/json', 'Accept: application/json'];
    if ($opciones['pruebas'] ?? true) {
        $cabeceras[] = 'X-Pruebas-Automaticas: 1'; // ver comun/db.php
    }
    $sesion = $opciones['sesion'] ?? true;
    $clave = $opciones['clave'] ?? false;

    if ($sesion === true && $GLOBALS['cookie_sesion'] !== null) {
        $cabeceras[] = 'Cookie: ' . NOMBRE_COOKIE_SESION . '=' . $GLOBALS['cookie_sesion'];
    } elseif (is_string($sesion)) {
        $cabeceras[] = "Cookie: $sesion";
    }

    if ($clave !== false) {
        $cabeceras[] = 'X-Servicio-Clave: ' . ($clave === true ? CLAVE_SERVICIO : $clave);
    }

    return $cabeceras;
}

/**
 * Hace una petición HTTP a un servicio a través de Apache.
 * $cuerpo: arreglo (se envía como JSON), texto (se envía tal cual) o null.
 * $opciones: ver cabecerasDePrueba().
 * Devuelve ['codigo', 'cabeceras', 'cookies' (cada Set-Cookie), 'cookie' (id de
 * sesión que envió el servidor, o null), 'texto', 'json'].
 */
function peticion(string $metodo, string $ruta, array|string|null $cuerpo = null, array $opciones = []): array
{
    renovarBaseDePruebas();
    $contenido = is_array($cuerpo)
        ? json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
        : ($cuerpo ?? '');

    $contexto = stream_context_create(['http' => [
        'method' => $metodo,
        'header' => implode("\r\n", cabecerasDePrueba($opciones)) . "\r\n",
        'content' => $contenido,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);

    $texto = @file_get_contents(URL_SERVICIOS . $ruta, false, $contexto);

    if ($texto === false) {
        fwrite(STDERR, "No se pudo conectar con " . URL_SERVICIOS . "$ruta. ¿Está encendido Apache?\n");
        exit(2);
    }

    $cabeceras = leerCabeceras($http_response_header);

    return [
        'codigo' => $cabeceras['codigo'],
        'cabeceras' => $cabeceras['lista'],
        'cookies' => $cabeceras['cookies'],
        'cookie' => cookieDeSesionEnviada($cabeceras['cookies']),
        'texto' => $texto,
        'json' => json_decode($texto, true),
    ];
}

/**
 * El id de sesión de la última Set-Cookie de la sesión, o null (tampoco si la borra).
 */
function cookieDeSesionEnviada(array $cookies): ?string
{
    $id = null;

    foreach ($cookies as $cookie) {
        if (preg_match('/^' . preg_quote(NOMBRE_COOKIE_SESION, '/') . '=([^;]*)/', $cookie, $coincidencia) === 1) {
            $id = $coincidencia[1] === 'deleted' || $coincidencia[1] === '' ? null : $coincidencia[1];
        }
    }

    return $id;
}

/**
 * Envía varias peticiones al mismo tiempo (curl_multi) y devuelve sus respuestas
 * en el mismo orden, con la forma de peticion(). Requiere la extensión curl:
 * compruébala antes con curlDisponible().
 * Cada petición es [método, ruta, cuerpo]; $opciones como en peticion().
 */
function peticionesSimultaneas(array $peticiones, array $opciones = []): array
{
    renovarBaseDePruebas();
    $multi = curl_multi_init();
    $manejadores = [];

    foreach ($peticiones as $i => [$metodo, $ruta, $cuerpo]) {
        $manejador = curl_init(URL_SERVICIOS . $ruta);
        curl_setopt_array($manejador, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_POSTFIELDS => is_array($cuerpo) ? json_encode($cuerpo, JSON_UNESCAPED_UNICODE) : ($cuerpo ?? ''),
            CURLOPT_HTTPHEADER => [...cabecerasDePrueba($opciones), 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_multi_add_handle($multi, $manejador);
        $manejadores[$i] = $manejador;
    }

    do {
        $estado = curl_multi_exec($multi, $activas);
        if ($activas > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($activas > 0 && $estado === CURLM_OK);

    $respuestas = [];
    foreach ($manejadores as $i => $manejador) {
        $respuestas[$i] = respuestaDeCurl($manejador, (string) curl_multi_getcontent($manejador));
        curl_multi_remove_handle($multi, $manejador);
        curl_close($manejador);
    }
    curl_multi_close($multi);

    return $respuestas;
}

function curlDisponible(): bool
{
    return function_exists('curl_multi_init');
}

/**
 * Convierte la respuesta cruda de curl (cabeceras + cuerpo) a la forma de peticion().
 */
function respuestaDeCurl(CurlHandle $manejador, string $crudo): array
{
    $tamanoCabeceras = (int) curl_getinfo($manejador, CURLINFO_HEADER_SIZE);
    $lineas = array_values(array_filter(explode("\r\n", substr($crudo, 0, $tamanoCabeceras))));
    $texto = substr($crudo, $tamanoCabeceras);
    $cabeceras = leerCabeceras($lineas);

    return [
        'codigo' => $cabeceras['codigo'],
        'cabeceras' => $cabeceras['lista'],
        'cookies' => $cabeceras['cookies'],
        'cookie' => cookieDeSesionEnviada($cabeceras['cookies']),
        'texto' => $texto,
        'json' => json_decode($texto, true),
    ];
}

/**
 * Separa el código HTTP y las cabeceras (con nombre en minúsculas).
 */
function leerCabeceras(array $lineas): array
{
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $lineas[0] ?? '', $coincidencia);
    $lista = [];
    $cookies = [];

    foreach (array_slice($lineas, 1) as $linea) {
        [$nombre, $valor] = array_pad(explode(':', $linea, 2), 2, '');
        $lista[strtolower(trim($nombre))] = trim($valor);
        if (strtolower(trim($nombre)) === 'set-cookie') {
            $cookies[] = trim($valor); // puede haber varias: la lista las conserva todas
        }
    }

    return ['codigo' => (int) ($coincidencia[1] ?? 0), 'lista' => $lista, 'cookies' => $cookies];
}

// ---------------------------------------------------------------- Comprobaciones

/**
 * Registra una comprobación y muestra ✓ o ✗.
 */
function comprobar(bool $condicion, string $descripcion, string $detalle = ''): bool
{
    renovarBaseDePruebas();
    if ($condicion) {
        $GLOBALS['pruebas_correctas']++;
        echo "  \u{2713} $descripcion\n";
    } else {
        $GLOBALS['pruebas_fallidas'][] = $descripcion;
        echo "  \u{2717} $descripcion\n" . ($detalle !== '' ? "      $detalle\n" : '');
    }

    return $condicion;
}

/**
 * Compara con === y muestra ambos valores si no coinciden.
 */
function comprobarIgual(mixed $esperado, mixed $obtenido, string $descripcion): bool
{
    return comprobar(
        $esperado === $obtenido,
        $descripcion,
        'esperado: ' . exportar($esperado) . ' | obtenido: ' . exportar($obtenido)
    );
}

/**
 * Comprueba el código HTTP y el formato común de la respuesta:
 * Content-Type JSON, cuerpo JSON válido y "ok" acorde al código.
 * Si se indica $mensajeError, también comprueba el texto de "error".
 */
function comprobarRespuesta(array $respuesta, int $codigo, string $descripcion, ?string $mensajeError = null): bool
{
    $json = $respuesta['json'];
    $esExito = $codigo < 400;

    $problemas = array_filter([
        $respuesta['codigo'] !== $codigo ? "código {$respuesta['codigo']} (esperado $codigo)" : null,
        ($respuesta['cabeceras']['content-type'] ?? '') !== 'application/json; charset=utf-8'
            ? 'Content-Type: ' . ($respuesta['cabeceras']['content-type'] ?? '(ninguno)') : null,
        !is_array($json) ? 'el cuerpo no es JSON: ' . substr($respuesta['texto'], 0, 120) : null,
        is_array($json) && ($json['ok'] ?? null) !== $esExito ? 'ok = ' . exportar($json['ok'] ?? null) : null,
        is_array($json) && $esExito && !array_key_exists('data', $json) ? 'falta "data"' : null,
        is_array($json) && !$esExito && !is_string($json['error'] ?? null) ? 'falta "error"' : null,
        $mensajeError !== null && ($json['error'] ?? null) !== $mensajeError
            ? 'error = ' . exportar($json['error'] ?? null) . " (esperado \"$mensajeError\")" : null,
    ]);

    return comprobar($problemas === [], "$descripcion → $codigo", implode('; ', $problemas));
}

const MENSAJE_SIN_SESION = 'Tu sesión terminó. Inicia sesión para continuar.';
const MENSAJE_SOLO_SERVICIOS = 'Solo otros servicios pueden usar este endpoint';

/**
 * Comprueba el acceso de las rutas de un servicio (docs/API.md, "Acceso"):
 * - [Interfaz]: sin sesión ni clave → 401; con la clave y sin sesión, pasa.
 * - [Interno]: sin clave → 403 aunque haya sesión; con la clave, pasa.
 * - Con una clave incorrecta → 403 en cualquiera.
 * "Pasa" quiere decir que la respuesta no es 401 ni 403 (puede ser 200, 400, 404…).
 * Cada ruta es [método, ruta, cuerpo].
 */
function comprobarAccesoDeRutas(array $interfaz, array $internas): void
{
    $pasa = fn(array $r): bool => !in_array($r['codigo'], [401, 403], true);

    foreach ($interfaz as [$metodo, $ruta, $cuerpo]) {
        comprobarRespuesta(peticion($metodo, $ruta, $cuerpo, ['sesion' => false]), 401, "[Interfaz] $metodo $ruta sin sesión", MENSAJE_SIN_SESION);
        $r = peticion($metodo, $ruta, $cuerpo, ['sesion' => false, 'clave' => true]);
        comprobar($pasa($r), "  con la clave de servicio y sin sesión, pasa", "código {$r['codigo']}");
        comprobarRespuesta(peticion($metodo, $ruta, $cuerpo, ['clave' => 'clave-incorrecta']), 403, '  con una clave incorrecta (aunque haya sesión)', MENSAJE_SOLO_SERVICIOS);
    }

    foreach ($internas as [$metodo, $ruta, $cuerpo]) {
        comprobarRespuesta(peticion($metodo, $ruta, $cuerpo), 403, "[Interno] $metodo $ruta con sesión pero sin clave", MENSAJE_SOLO_SERVICIOS);
        comprobarRespuesta(peticion($metodo, $ruta, $cuerpo, ['sesion' => false]), 403, '  sin sesión ni clave', MENSAJE_SOLO_SERVICIOS);
        comprobarRespuesta(peticion($metodo, $ruta, $cuerpo, ['sesion' => false, 'clave' => 'clave-incorrecta']), 403, '  con una clave incorrecta', MENSAJE_SOLO_SERVICIOS);
        $r = peticionInterna($metodo, $ruta, $cuerpo);
        comprobar($pasa($r), '  con la clave de servicio, pasa', "código {$r['codigo']}");
    }
}

/**
 * Comprueba un error de validación: código, y el mensaje de un campo en "campos".
 */
function comprobarErrorCampo(array $respuesta, int $codigo, string $campo, string $mensaje, string $descripcion): bool
{
    $bien = comprobarRespuesta($respuesta, $codigo, $descripcion);
    $obtenido = $respuesta['json']['campos'][$campo] ?? null;

    return comprobarIgual($mensaje, $obtenido, "  campos.$campo = \"$mensaje\"") && $bien;
}

/**
 * Compara números sin distinguir entero de decimal: en JSON, 2 y 2.0 son el mismo número.
 */
function comprobarNumero(int|float $esperado, mixed $obtenido, string $descripcion): bool
{
    $esNumero = is_int($obtenido) || is_float($obtenido);

    return comprobar($esNumero && $obtenido == $esperado, $descripcion, 'esperado: ' . exportar($esperado) . ' | obtenido: ' . exportar($obtenido));
}

/**
 * Extrae varios campos de un objeto de la respuesta. A diferencia de ??, distingue
 * un campo que vale null de uno que falta ("(falta)").
 */
function campos(mixed $objeto, array $claves): array
{
    return array_map(
        fn(string $clave) => is_array($objeto) && array_key_exists($clave, $objeto) ? $objeto[$clave] : '(falta)',
        $claves
    );
}

function exportar(mixed $valor): string
{
    return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

/**
 * Registra un aviso (por ejemplo, una prueba omitida): se muestra en el
 * resumen, pero no cuenta como fallo.
 */
function avisar(string $mensaje): void
{
    $GLOBALS['pruebas_avisos'][] = $mensaje;
    echo "  ! $mensaje\n";
}

function seccion(string $titulo): void
{
    echo "\n$titulo\n";
}

/**
 * Muestra el resumen y termina con código 0 si todo pasó, o 1 si algo falló.
 */
function terminarPruebas(): never
{
    seccion('Tu base de datos');
    comprobarIgual($GLOBALS['huella_de_tu_base'], huellaDeTuBase(), 'Tu base (' . DB_NOMBRE . ') quedó idéntica: las pruebas usaron ' . DB_NOMBRE_PRUEBAS);
    $GLOBALS['huella_comprobada'] = true;

    $fallidas = $GLOBALS['pruebas_fallidas'];
    $total = $GLOBALS['pruebas_correctas'] + count($fallidas);

    echo "\n" . str_repeat('─', 60) . "\n";
    echo "Resultado: {$GLOBALS['pruebas_correctas']} de $total comprobaciones correctas.\n";

    foreach ($fallidas as $descripcion) {
        echo "  \u{2717} $descripcion\n";
    }

    foreach ($GLOBALS['pruebas_avisos'] as $aviso) {
        echo "  ! Aviso: $aviso\n";
    }

    exit($fallidas === [] ? 0 : 1);
}
