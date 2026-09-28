<?php
/**
 * Acceso a la tabla alertas (la única tabla de este servicio).
 * Todas las consultas son preparadas; los valores nunca se concatenan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/db.php';

// dispositivo_pendiente es interna: no se selecciona ni viaja en la API.
const COLUMNAS_ALERTA = 'id, dispositivo_id, tipo, mensaje, fecha, atendida, fecha_atendida';

/**
 * Lista las alertas de la más reciente a la más antigua, con filtros opcionales.
 */
function consultarAlertas(?int $atendida, ?int $dispositivoId, int $limite): array
{
    $condiciones = [];
    $parametros = [];

    if ($atendida !== null) {
        $condiciones[] = 'atendida = :atendida';
        $parametros['atendida'] = $atendida;
    }

    if ($dispositivoId !== null) {
        $condiciones[] = 'dispositivo_id = :dispositivo_id';
        $parametros['dispositivo_id'] = $dispositivoId;
    }

    $donde = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);
    $consulta = obtenerConexion()->prepare(
        'SELECT ' . COLUMNAS_ALERTA . ' FROM alertas' . $donde . ' ORDER BY fecha DESC, id DESC LIMIT :limite'
    );

    foreach ($parametros as $nombre => $valor) {
        $consulta->bindValue($nombre, $valor, PDO::PARAM_INT);
    }
    $consulta->bindValue('limite', $limite, PDO::PARAM_INT);
    $consulta->execute();

    return $consulta->fetchAll();
}

/**
 * Cuenta las alertas pendientes y atendidas.
 */
function contarAlertas(): array
{
    $fila = obtenerConexion()->query(
        'SELECT COALESCE(SUM(atendida = 0), 0) AS pendientes, COALESCE(SUM(atendida = 1), 0) AS atendidas FROM alertas'
    )->fetch();

    return ['pendientes' => (int) $fila['pendientes'], 'atendidas' => (int) $fila['atendidas']];
}

/**
 * Devuelve la alerta con ese id, o null si no existe.
 */
function consultarAlerta(int $id): ?array
{
    $consulta = obtenerConexion()->prepare('SELECT ' . COLUMNAS_ALERTA . ' FROM alertas WHERE id = :id');
    $consulta->execute(['id' => $id]);

    return $consulta->fetch() ?: null;
}

/**
 * Devuelve la alerta pendiente del dispositivo, o null si no tiene.
 */
function consultarAlertaPendiente(int $dispositivoId): ?array
{
    $consulta = obtenerConexion()->prepare(
        'SELECT ' . COLUMNAS_ALERTA . ' FROM alertas WHERE dispositivo_id = :dispositivo_id AND atendida = 0'
    );
    $consulta->execute(['dispositivo_id' => $dispositivoId]);

    return $consulta->fetch() ?: null;
}

/**
 * Registra una alerta pendiente y devuelve su id.
 *
 * @throws PDOException 1062 si el dispositivo ya tiene una pendiente
 *                      (uq_alertas_dispositivo_pendiente), 1452 si no existe.
 */
function insertarAlerta(array $datos, string $fecha): int
{
    $conexion = obtenerConexion();
    $consulta = $conexion->prepare(
        'INSERT INTO alertas (dispositivo_id, tipo, mensaje, fecha, atendida)
         VALUES (:dispositivo_id, :tipo, :mensaje, :fecha, 0)'
    );
    $consulta->execute([
        'dispositivo_id' => $datos['dispositivo_id'],
        'tipo' => $datos['tipo'],
        'mensaje' => $datos['mensaje'],
        'fecha' => $fecha,
    ]);

    return (int) $conexion->lastInsertId();
}

/**
 * Marca la alerta como atendida. Si ya lo estaba, no cambia nada
 * (conserva su fecha_atendida original).
 */
function marcarAlertaAtendida(int $id, string $fecha): void
{
    $consulta = obtenerConexion()->prepare(
        'UPDATE alertas SET atendida = 1, fecha_atendida = :fecha WHERE id = :id AND atendida = 0'
    );
    $consulta->execute(['fecha' => $fecha, 'id' => $id]);
}

/**
 * Código de error de MySQL de una PDOException (1062, 1452...), o null.
 */
function codigoErrorMysql(PDOException $error): ?int
{
    return $error->errorInfo[1] ?? null;
}

/**
 * Convierte una fila de la base al objeto alerta de la API:
 * TINYINT llega como 1/0 y se envía como booleano.
 */
function formatearAlerta(array $fila): array
{
    return [
        'id' => (int) $fila['id'],
        'dispositivo_id' => (int) $fila['dispositivo_id'],
        'tipo' => $fila['tipo'],
        'mensaje' => $fila['mensaje'],
        'fecha' => $fila['fecha'],
        'atendida' => (bool) $fila['atendida'],
        'fecha_atendida' => $fila['fecha_atendida'],
    ];
}
