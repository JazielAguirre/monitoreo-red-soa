<?php
/**
 * Conexión PDO a MySQL con los datos de config.php.
 * Cada servicio la usa solo para sus propias tablas.
 *
 * Base de pruebas: mientras exista pruebas/.base-de-pruebas.json vigente, la
 * conexión usa DB_NOMBRE_PRUEBAS en lugar de DB_NOMBRE. Así las suites nunca
 * tocan la base del usuario. Sigue el modelo del fallo simulado (comun/http.php):
 * - Solo lo crean las suites (pruebas/ayudas.php); ningún endpoint escribe archivos
 *   y pruebas/ no es accesible por HTTP, así que no se puede activar desde una petición.
 * - Caduca solo: se ignora si "expira" ya pasó o está a más de 60 s en el futuro.
 *   Las suites lo renuevan mientras corren; si se interrumpen, en menos de un
 *   minuto todo vuelve a la base del usuario.
 * - No elige la base: solo existe un destino posible, DB_NOMBRE_PRUEBAS.
 *
 * Tipos: PDO devuelve DECIMAL como texto y TINYINT como entero; cada servicio
 * los convierte a número y booleano antes de responder.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const ARCHIVO_BASE_DE_PRUEBAS = __DIR__ . '/../../pruebas/.base-de-pruebas.json';
const VIGENCIA_MAXIMA_BASE_DE_PRUEBAS = 60;

/**
 * Devuelve la conexión a la base de datos, creándola la primera vez que se
 * pide en la petición.
 *
 * @throws PDOException si no se puede conectar.
 */
function obtenerConexion(): PDO
{
    static $conexion = null;

    if ($conexion === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PUERTO,
            nombreDeLaBase(),
            DB_CHARSET
        );

        $conexion = new PDO($dsn, DB_USUARIO, DB_CLAVE, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // La misma colación que las tablas, para no mezclar colaciones al comparar.
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . DB_CHARSET . ' COLLATE ' . DB_COLACION,
        ]);
    }

    return $conexion;
}

/**
 * La base que se usa: la de pruebas si las suites la activaron, si no la del usuario.
 */
function nombreDeLaBase(): string
{
    return baseDePruebasActiva() ? DB_NOMBRE_PRUEBAS : DB_NOMBRE;
}

/**
 * Indica si pruebas/.base-de-pruebas.json existe y está vigente.
 */
function baseDePruebasActiva(): bool
{
    if (!is_file(ARCHIVO_BASE_DE_PRUEBAS)) {
        return false;
    }

    $contenido = json_decode((string) @file_get_contents(ARCHIVO_BASE_DE_PRUEBAS), true);
    $expira = is_array($contenido) ? ($contenido['expira'] ?? null) : null;
    $ahora = time();

    return is_int($expira) && $expira >= $ahora && $expira <= $ahora + VIGENCIA_MAXIMA_BASE_DE_PRUEBAS;
}
