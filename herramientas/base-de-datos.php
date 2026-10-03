<?php
/**
 * Herramienta de base de datos compartida por las pruebas (pruebas/ayudas.php),
 * la preparación de la demo (herramientas/preparar-demo.php) y la creación de la
 * base de uso diario (herramientas/crear-base.php). Usa el cliente mysql y
 * mysqldump de XAMPP.
 *
 * Archivos de database/:
 * - schema.sql:  las tablas (no crea ni borra la base).
 * - inicial.sql: el usuario admin. La base de uso diario es schema + inicial.
 * - seed.sql:    datos de ejemplo, solo para la base de pruebas o, a pedido,
 *                con preparar-demo.php --datos-de-ejemplo.
 *
 * herramientas/ es, como schema.sql y seed.sql, una herramienta de base de datos:
 * puede tocar todas las tablas. La regla de que cada servicio solo toca las suyas
 * sigue valiendo para servicios/ (CLAUDE.md, "Reglas SOA").
 *
 * Solo línea de comandos. La carpeta no es accesible por HTTP (.htaccess de la raíz).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../servicios/comun/config.php';
require_once __DIR__ . '/../servicios/comun/db.php'; // nombreDeLaBase(): la de pruebas solo si las suites la activaron

const ARCHIVO_SCHEMA = __DIR__ . '/../database/schema.sql';
const ARCHIVO_INICIAL = __DIR__ . '/../database/inicial.sql';
const ARCHIVO_SEED = __DIR__ . '/../database/seed.sql';
const CARPETA_RESPALDOS = __DIR__ . '/../respaldos';
const TABLAS_DE_LA_BASE = ['dispositivos', 'monitoreos', 'alertas', 'usuarios'];

/**
 * Recrea la base de PRUEBAS con schema.sql, inicial.sql y seed.sql (datos de
 * ejemplo). Candado: si la base de pruebas no está activa (pruebas/ayudas.php la
 * activa), se niega y termina con código 2: nunca borra la base del usuario.
 */
function cargarSchemaYSeed(): void
{
    if (!baseDePruebasActiva()) {
        fwrite(STDERR, '✗ Solo se recarga la base de pruebas (' . DB_NOMBRE_PRUEBAS . '), y no está activa: no se tocó ' . DB_NOMBRE . ".
");
        exit(2);
    }

    recrearBase(DB_NOMBRE_PRUEBAS, [ARCHIVO_SCHEMA, ARCHIVO_INICIAL, ARCHIVO_SEED]);
}

/**
 * Borra la base $base (si existe), la vuelve a crear vacía y carga los archivos.
 * Termina con código 2 si falla.
 */
function recrearBase(string $base, array $archivos): void
{
    ejecutarSentencias(
        null,
        'DROP DATABASE IF EXISTS ' . identificador($base) . '; '
        . 'CREATE DATABASE ' . identificador($base) . ' CHARACTER SET ' . DB_CHARSET . ' COLLATE ' . DB_COLACION . ';'
    );

    foreach ($archivos as $archivo) {
        cargarArchivoSql($base, $archivo);
    }
}

/**
 * Crea la base $base vacía (schema.sql + inicial.sql). Si ya existe, no la toca y
 * devuelve false.
 */
function crearBaseInicial(string $base): bool
{
    if (existeBase($base)) {
        return false;
    }

    ejecutarSentencias(null, 'CREATE DATABASE ' . identificador($base) . ' CHARACTER SET ' . DB_CHARSET . ' COLLATE ' . DB_COLACION . ';');
    cargarArchivoSql($base, ARCHIVO_SCHEMA);
    cargarArchivoSql($base, ARCHIVO_INICIAL);

    return true;
}

/**
 * Indica si el servidor MySQL acepta conexiones (sin elegir base).
 */
function mysqlResponde(): bool
{
    try {
        new PDO(sprintf('mysql:host=%s;port=%d', DB_HOST, DB_PUERTO), DB_USUARIO, DB_CLAVE, [PDO::ATTR_TIMEOUT => 3]);
        return true;
    } catch (PDOException) {
        return false;
    }
}

function existeBase(string $base): bool
{
    $salida = ejecutarSentencias(null, 'SHOW DATABASES LIKE ' . "'" . str_replace('_', '\\_', $base) . "'" . ';', true);

    return trim($salida) === $base;
}

/**
 * Filas de cada tabla de la base ([tabla => número]); null si la base no existe.
 */
function contarFilas(string $base): ?array
{
    if (!existeBase($base)) {
        return null;
    }

    $conteos = [];
    foreach (TABLAS_DE_LA_BASE as $tabla) {
        $conteos[$tabla] = (int) trim(ejecutarSentencias($base, "SELECT COUNT(*) FROM $tabla;", true));
    }

    return $conteos;
}

/**
 * "1 dispositivo, 40 monitoreos, 2 alertas" (o "no existe" si $conteos es null).
 */
function describirConteos(?array $conteos): string
{
    if ($conteos === null) {
        return 'no existe';
    }

    $plural = fn(int $n, string $uno, string $varios): string => $n . ' ' . ($n === 1 ? $uno : $varios);

    return $plural($conteos['dispositivos'], 'dispositivo', 'dispositivos') . ', '
        . $plural($conteos['monitoreos'], 'monitoreo', 'monitoreos') . ', '
        . $plural($conteos['alertas'], 'alerta', 'alertas');
}

/**
 * Respaldo completo de la base con mysqldump en respaldos/ (fuera de git y sin
 * acceso por HTTP). Devuelve la ruta del archivo. Termina con código 2 si falla:
 * sin respaldo no se borra nada.
 *
 * El respaldo no incluye CREATE DATABASE: se restaura en la base que se elija
 * (herramientas/restaurar-respaldo.php).
 */
function respaldarBase(string $base, string $motivo): string
{
    if (!is_dir(CARPETA_RESPALDOS) && !mkdir(CARPETA_RESPALDOS, 0700, true)) {
        fwrite(STDERR, "✗ No se pudo crear la carpeta de respaldos.\n");
        exit(2);
    }

    // Nunca sobrescribe un respaldo: dos en el mismo segundo llevan -2, -3…
    $inicio = realpath(CARPETA_RESPALDOS) . DIRECTORY_SEPARATOR . "$base-" . date('Ymd-His') . "-$motivo";
    $archivo = "$inicio.sql";
    for ($n = 2; file_exists($archivo); $n++) {
        $archivo = "$inicio-$n.sql";
    }
    $comando = escapeshellarg(binarioDeXampp('mysqldump')) . ' ' . opcionesDeConexion()
        . ' --single-transaction ' . escapeshellarg($base) . ' > ' . escapeshellarg($archivo) . ' 2>&1';
    exec($comando, $salida, $codigo);

    if ($codigo !== 0 || !is_file($archivo) || !str_contains((string) file_get_contents($archivo), '-- Dump completed')) {
        fwrite(STDERR, "✗ No se pudo respaldar la base $base (no se borró nada):\n" . implode("\n", $salida) . "\n");
        exit(2);
    }

    return $archivo;
}

/**
 * El PHP de XAMPP, como en el README, para escribir comandos que se puedan copiar tal
 * cual (no "php" a secas: el de XAMPP es el mismo que usa Apache).
 */
function comandoPhp(): string
{
    return PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/php/php.exe' : '/opt/lampp/bin/php';
}

/**
 * Pregunta en la terminal y devuelve true solo si se escribe exactamente SI.
 */
function pedirConfirmacion(string $pregunta): bool
{
    echo "$pregunta\nEscribe SI para continuar (cualquier otra cosa cancela): ";
    $respuesta = fgets(STDIN);

    return is_string($respuesta) && trim($respuesta) === 'SI';
}

/**
 * Carga un archivo .sql en la base. Termina con código 2 si falla.
 */
function cargarArchivoSql(string $base, string $archivo): void
{
    $comando = comandoMysql() . ' ' . escapeshellarg($base) . ' < ' . escapeshellarg($archivo) . ' 2>&1';
    exec($comando, $salida, $codigo);

    if ($codigo !== 0) {
        fwrite(STDERR, 'No se pudo cargar ' . basename($archivo) . " en $base:\n" . implode("\n", $salida) . "\n");
        exit(2);
    }
}

/**
 * Ejecuta sentencias SQL fijas (sin datos del usuario) con el cliente mysql, en la
 * base indicada o sin base. Devuelve la salida (solo los valores si $soloValores).
 * Termina con código 2 si falla.
 */
function ejecutarSentencias(?string $base, string $sql, bool $soloValores = false): string
{
    $comando = comandoMysql() . ($soloValores ? ' -N -B' : '') . ($base === null ? '' : ' ' . escapeshellarg($base))
        . ' -e ' . escapeshellarg($sql) . ' 2>&1';
    exec($comando, $salida, $codigo);

    if ($codigo !== 0) {
        fwrite(STDERR, "Error de MySQL:\n" . implode("\n", $salida) . "\n");
        exit(2);
    }

    return implode("\n", $salida);
}

/**
 * Nombre de base entre comillas invertidas. Solo letras minúsculas, números y _:
 * los nombres vienen de config.php, nunca del usuario.
 */
function identificador(string $base): string
{
    if (preg_match('/^[a-z0-9_]+$/', $base) !== 1) {
        throw new InvalidArgumentException("Nombre de base no válido: $base");
    }

    return "`$base`";
}

/**
 * Arma el comando del cliente mysql con los datos de config.php.
 * Se puede indicar otro binario con la variable de entorno MYSQL_BIN.
 */
function comandoMysql(): string
{
    return escapeshellarg(getenv('MYSQL_BIN') ?: binarioDeXampp('mysql'))
        . ' --default-character-set=' . DB_CHARSET . ' ' . opcionesDeConexion();
}

function opcionesDeConexion(): string
{
    $opciones = '-h ' . escapeshellarg(DB_HOST) . ' -P ' . (int) DB_PUERTO . ' -u ' . escapeshellarg(DB_USUARIO);

    return DB_CLAVE !== '' ? $opciones . ' -p' . escapeshellarg(DB_CLAVE) : $opciones;
}

/**
 * Ruta de mysql o mysqldump de XAMPP (Linux o Windows).
 */
function binarioDeXampp(string $nombre): string
{
    return PHP_OS_FAMILY === 'Windows' ? "C:/xampp/mysql/bin/$nombre.exe" : "/opt/lampp/bin/$nombre";
}
