<?php
/**
 * Acceso a la tabla monitoreos (la única tabla de este servicio).
 * Los datos de dispositivos y alertas se piden por HTTP (clientes.php), nunca por SQL.
 * Todas las consultas son preparadas; los valores nunca se concatenan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/db.php';

const COLUMNAS_MONITOREO = 'id, dispositivo_id, ip, estado, tiempo_respuesta_ms, fecha';

/**
 * Guarda un monitoreo y devuelve su id.
 *
 * @throws PDOException 1452 si el dispositivo ya no existe (llave foránea).
 */
function insertarMonitoreo(int $dispositivoId, string $ip, string $estado, ?float $tiempoMs, string $fecha): int
{
    $conexion = obtenerConexion();
    $consulta = $conexion->prepare(
        'INSERT INTO monitoreos (dispositivo_id, ip, estado, tiempo_respuesta_ms, fecha)
         VALUES (:dispositivo_id, :ip, :estado, :tiempo, :fecha)'
    );
    $consulta->execute([
        'dispositivo_id' => $dispositivoId,
        'ip' => $ip,
        'estado' => $estado,
        'tiempo' => $tiempoMs,
        'fecha' => $fecha,
    ]);

    return (int) $conexion->lastInsertId();
}

function consultarMonitoreo(int $id): ?array
{
    $consulta = obtenerConexion()->prepare('SELECT ' . COLUMNAS_MONITOREO . ' FROM monitoreos WHERE id = :id');
    $consulta->execute(['id' => $id]);

    return $consulta->fetch() ?: null;
}

/**
 * Arma el WHERE del historial. $incluirEstado es false para el resumen, que
 * ignora el filtro de estado (si no, la disponibilidad con "Activos" siempre sería 100 %).
 * $filtros: dispositivo (?int), estado (?string), desde y hasta (?string YYYY-MM-DD).
 */
function condicionesHistorial(array $filtros, bool $incluirEstado): array
{
    $condiciones = [];
    $parametros = [];

    if ($filtros['dispositivo'] !== null) {
        $condiciones[] = 'dispositivo_id = :dispositivo';
        $parametros['dispositivo'] = $filtros['dispositivo'];
    }

    if ($incluirEstado && $filtros['estado'] !== null) {
        $condiciones[] = 'estado = :estado';
        $parametros['estado'] = $filtros['estado'];
    }

    if ($filtros['desde'] !== null) {
        $condiciones[] = 'fecha >= :desde';
        $parametros['desde'] = $filtros['desde'] . ' 00:00:00';
    }

    if ($filtros['hasta'] !== null) {
        // "hasta" es inclusivo: hasta el inicio del día siguiente (aprovecha el índice de fecha).
        $condiciones[] = 'fecha < :antes_de';
        $parametros['antes_de'] = (new DateTimeImmutable($filtros['hasta']))->modify('+1 day')->format('Y-m-d 00:00:00');
    }

    $donde = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

    return [$donde, $parametros];
}

function contarMonitoreos(array $filtros): int
{
    [$donde, $parametros] = condicionesHistorial($filtros, true);
    $consulta = obtenerConexion()->prepare('SELECT COUNT(*) FROM monitoreos' . $donde);
    $consulta->execute($parametros);

    return (int) $consulta->fetchColumn();
}

/**
 * Una página del historial, del más reciente al más antiguo.
 */
function consultarPaginaMonitoreos(array $filtros, int $porPagina, int $desplazamiento): array
{
    [$donde, $parametros] = condicionesHistorial($filtros, true);
    $consulta = obtenerConexion()->prepare(
        'SELECT ' . COLUMNAS_MONITOREO . ' FROM monitoreos' . $donde
        . ' ORDER BY fecha DESC, id DESC LIMIT :limite OFFSET :desplazamiento'
    );

    foreach ($parametros as $nombre => $valor) {
        $consulta->bindValue($nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $consulta->bindValue('limite', $porPagina, PDO::PARAM_INT);
    $consulta->bindValue('desplazamiento', $desplazamiento, PDO::PARAM_INT);
    $consulta->execute();

    return $consulta->fetchAll();
}

/**
 * Resumen calculado en SQL con los filtros dispositivo, desde y hasta
 * (ignora estado y paginación). Promedio y máximo solo de los activos.
 */
function calcularResumen(array $filtros): array
{
    [$donde, $parametros] = condicionesHistorial($filtros, false);
    $consulta = obtenerConexion()->prepare(
        "SELECT
            ROUND(AVG(CASE WHEN estado = 'activo' THEN tiempo_respuesta_ms END), 2) AS promedio_ms,
            MAX(CASE WHEN estado = 'activo' THEN tiempo_respuesta_ms END) AS maximo_ms,
            ROUND(SUM(estado = 'activo') * 100 / NULLIF(COUNT(*), 0), 1) AS disponibilidad
         FROM monitoreos" . $donde
    );
    $consulta->execute($parametros);
    $fila = $consulta->fetch();

    return [
        'promedio_ms' => numeroONulo($fila['promedio_ms']),
        'maximo_ms' => numeroONulo($fila['maximo_ms']),
        'disponibilidad' => numeroONulo($fila['disponibilidad']),
    ];
}

/**
 * Los últimos $limite monitoreos de cada dispositivo, agrupados por dispositivo
 * (orden de dispositivo_id) y en orden cronológico ascendente.
 */
function consultarRecientes(int $limite): array
{
    $consulta = obtenerConexion()->prepare(
        'SELECT ' . COLUMNAS_MONITOREO . ' FROM (
            SELECT ' . COLUMNAS_MONITOREO . ',
                   ROW_NUMBER() OVER (PARTITION BY dispositivo_id ORDER BY fecha DESC, id DESC) AS posicion
            FROM monitoreos
         ) AS numerados
         WHERE posicion <= :limite
         ORDER BY dispositivo_id, fecha, id'
    );
    $consulta->bindValue('limite', $limite, PDO::PARAM_INT);
    $consulta->execute();

    $grupos = [];
    foreach ($consulta->fetchAll() as $fila) {
        $grupos[(int) $fila['dispositivo_id']][] = formatearMonitoreo($fila);
    }

    $resultado = [];
    foreach ($grupos as $dispositivoId => $monitoreos) {
        $resultado[] = ['dispositivo_id' => $dispositivoId, 'monitoreos' => $monitoreos];
    }

    return $resultado;
}

/**
 * Código de error de MySQL de una PDOException (1452...), o null.
 */
function codigoErrorMysql(PDOException $error): ?int
{
    return $error->errorInfo[1] ?? null;
}

/**
 * Convierte una fila al objeto monitoreo de la API: DECIMAL → número, null se conserva.
 */
function formatearMonitoreo(array $fila): array
{
    return [
        'id' => (int) $fila['id'],
        'dispositivo_id' => (int) $fila['dispositivo_id'],
        'ip' => $fila['ip'],
        'estado' => $fila['estado'],
        'tiempo_respuesta_ms' => numeroONulo($fila['tiempo_respuesta_ms']),
        'fecha' => $fila['fecha'],
    ];
}

function numeroONulo(mixed $valor): ?float
{
    return $valor === null ? null : (float) $valor;
}
