<?php
/**
 * Conexión PDO a MySQL con los datos de config.php.
 * Cada servicio la usa solo para sus propias tablas.
 *
 * Tipos: PDO devuelve DECIMAL como texto y TINYINT como entero; cada servicio
 * los convierte a número y booleano antes de responder.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

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
            DB_NOMBRE,
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
