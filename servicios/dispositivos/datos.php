<?php
/**
 * Acceso a la tabla dispositivos (la única tabla de este servicio).
 * Todas las consultas son preparadas; los valores nunca se concatenan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/db.php';

const COLUMNAS_DISPOSITIVO = 'id, nombre, ip, tipo, ubicacion, estado, ultimo_tiempo_ms, '
    . 'ultima_comprobacion, fecha_registro';

/**
 * Lista los dispositivos ordenados por nombre, con filtros opcionales.
 */
function consultarDispositivos(?string $estado, ?string $tipo): array
{
    $condiciones = [];
    $parametros = [];

    if ($estado !== null) {
        $condiciones[] = 'estado = :estado';
        $parametros['estado'] = $estado;
    }

    if ($tipo !== null) {
        $condiciones[] = 'tipo = :tipo';
        $parametros['tipo'] = $tipo;
    }

    $donde = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);
    $consulta = obtenerConexion()->prepare(
        'SELECT ' . COLUMNAS_DISPOSITIVO . ' FROM dispositivos' . $donde . ' ORDER BY nombre, id'
    );
    $consulta->execute($parametros);

    return $consulta->fetchAll();
}

/**
 * Devuelve el dispositivo con ese id, o null si no existe.
 */
function consultarDispositivo(int $id): ?array
{
    $consulta = obtenerConexion()->prepare(
        'SELECT ' . COLUMNAS_DISPOSITIVO . ' FROM dispositivos WHERE id = :id'
    );
    $consulta->execute(['id' => $id]);

    return $consulta->fetch() ?: null;
}

/**
 * Indica si la IP ya la usa otro dispositivo (distinto de $excluirId).
 */
function existeIpEnOtroDispositivo(string $ip, ?int $excluirId): bool
{
    $consulta = obtenerConexion()->prepare(
        'SELECT 1 FROM dispositivos WHERE ip = :ip AND id <> :excluir_id LIMIT 1'
    );
    $consulta->execute(['ip' => $ip, 'excluir_id' => $excluirId ?? 0]);

    return $consulta->fetchColumn() !== false;
}

/**
 * Registra un dispositivo nuevo (estado desconocido, sin monitoreos) y devuelve su id.
 */
function insertarDispositivo(array $datos, string $fechaRegistro): int
{
    $conexion = obtenerConexion();
    $consulta = $conexion->prepare(
        'INSERT INTO dispositivos (nombre, ip, tipo, ubicacion, estado, fecha_registro)
         VALUES (:nombre, :ip, :tipo, :ubicacion, \'desconocido\', :fecha_registro)'
    );
    $consulta->execute([
        'nombre' => $datos['nombre'],
        'ip' => $datos['ip'],
        'tipo' => $datos['tipo'],
        'ubicacion' => $datos['ubicacion'],
        'fecha_registro' => $fechaRegistro,
    ]);

    return (int) $conexion->lastInsertId();
}

/**
 * Guarda nombre, ip, tipo y ubicación. Con $reiniciarEstado (la IP cambió),
 * el dispositivo vuelve a desconocido y se borran los datos del último monitoreo.
 */
function guardarDatosDispositivo(int $id, array $datos, bool $reiniciarEstado): void
{
    $reinicio = $reiniciarEstado
        ? ', estado = \'desconocido\', ultimo_tiempo_ms = NULL, ultima_comprobacion = NULL'
        : '';

    $consulta = obtenerConexion()->prepare(
        'UPDATE dispositivos SET nombre = :nombre, ip = :ip, tipo = :tipo, ubicacion = :ubicacion'
        . $reinicio . ' WHERE id = :id'
    );
    $consulta->execute([
        'nombre' => $datos['nombre'],
        'ip' => $datos['ip'],
        'tipo' => $datos['tipo'],
        'ubicacion' => $datos['ubicacion'],
        'id' => $id,
    ]);
}

/**
 * Guarda el resultado del último monitoreo.
 */
function guardarEstadoDispositivo(int $id, string $estado, ?float $tiempoMs, string $fecha): void
{
    $consulta = obtenerConexion()->prepare(
        'UPDATE dispositivos
         SET estado = :estado, ultimo_tiempo_ms = :tiempo, ultima_comprobacion = :fecha
         WHERE id = :id'
    );
    $consulta->execute(['estado' => $estado, 'tiempo' => $tiempoMs, 'fecha' => $fecha, 'id' => $id]);
}

/**
 * Borra el dispositivo. Sus monitoreos y alertas los borra la base de datos
 * por las llaves foráneas (ON DELETE CASCADE).
 */
function borrarDispositivo(int $id): void
{
    $consulta = obtenerConexion()->prepare('DELETE FROM dispositivos WHERE id = :id');
    $consulta->execute(['id' => $id]);
}

/**
 * Indica si el error de PDO es por la llave única de la IP.
 */
function esErrorIpDuplicada(PDOException $error): bool
{
    return ($error->errorInfo[1] ?? null) === 1062;
}

/**
 * Convierte una fila de la base al objeto dispositivo de la API:
 * DECIMAL llega como texto ("0.06") y se envía como número; null se conserva.
 */
function formatearDispositivo(array $fila): array
{
    return [
        'id' => (int) $fila['id'],
        'nombre' => $fila['nombre'],
        'ip' => $fila['ip'],
        'tipo' => $fila['tipo'],
        'ubicacion' => $fila['ubicacion'],
        'estado' => $fila['estado'],
        'ultimo_tiempo_ms' => $fila['ultimo_tiempo_ms'] === null ? null : (float) $fila['ultimo_tiempo_ms'],
        'ultima_comprobacion' => $fila['ultima_comprobacion'],
        'fecha_registro' => $fila['fecha_registro'],
    ];
}
