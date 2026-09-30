<?php
/**
 * Endpoints del Servicio de Monitoreo (docs/API.md, "Servicio de Monitoreo").
 * Cada función atiende una ruta y termina respondiendo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/datos.php';
require_once __DIR__ . '/validacion.php';
require_once __DIR__ . '/clientes.php';
require_once __DIR__ . '/../comun/ping.php';

const ERROR_MYSQL_FK = 1452;

/**
 * POST /monitoreo/{id} [Interfaz]: hace ping al dispositivo y registra el resultado.
 * Si falla el paso 4 o 5 (502), el monitoreo ya quedó guardado.
 */
function monitorearDispositivo(int $id): never
{
    // 1. La IP y el nombre se piden al Servicio de Dispositivos.
    $dispositivo = obtenerDispositivoRemoto($id);

    // 2. Ping.
    $ping = hacerPing($dispositivo['ip']);
    $estado = $ping['responde'] ? 'activo' : 'inactivo';
    $tiempoMs = $ping['tiempo_respuesta_ms'];

    // 3. La fecha se genera una sola vez: la usan el monitoreo y el PATCH /estado.
    $fecha = date('Y-m-d H:i:s');
    $idMonitoreo = guardarMonitoreo($id, $dispositivo['ip'], $estado, $tiempoMs, $fecha);

    // 4. El dispositivo guarda una copia del último resultado.
    informarEstadoDispositivo($id, $estado, $tiempoMs, $fecha);

    // 5. Si no respondió, se pide una alerta (Alertas evita las repetidas).
    $alertaCreada = $estado === 'inactivo' ? crearAlertaRemota($id, $dispositivo['nombre']) : false;

    responder([
        'monitoreo' => formatearMonitoreo(consultarMonitoreo($idMonitoreo)),
        'alerta_creada' => $alertaCreada,
    ], 201);
}

/**
 * GET /monitoreo [Interfaz]: historial paginado con resumen.
 */
function listarMonitoreos(): never
{
    $validacion = validarFiltrosHistorial($_GET);

    if ($validacion['errores'] !== []) {
        responderError('Filtros no válidos', 400, $validacion['errores']);
    }

    $filtros = $validacion['datos'];
    $total = contarMonitoreos($filtros);
    $desplazamiento = ($filtros['pagina'] - 1) * $filtros['por_pagina'];

    responder([
        'items' => array_map('formatearMonitoreo', consultarPaginaMonitoreos($filtros, $filtros['por_pagina'], $desplazamiento)),
        'total' => $total,
        'pagina' => $filtros['pagina'],
        'por_pagina' => $filtros['por_pagina'],
        'total_paginas' => (int) ceil($total / $filtros['por_pagina']),
        'resumen' => calcularResumen($filtros),
    ]);
}

/**
 * GET /monitoreo/recientes [Interfaz]: últimas comprobaciones de cada dispositivo.
 */
function listarRecientes(): never
{
    $validacion = validarLimiteRecientes($_GET);

    if ($validacion['errores'] !== []) {
        responderError('Filtros no válidos', 400, $validacion['errores']);
    }

    responder(consultarRecientes($validacion['datos']['limite']));
}

/**
 * Paso 3: guarda el monitoreo. Si el dispositivo se eliminó entre el paso 1
 * y este (la llave foránea responde 1452), responde 404.
 */
function guardarMonitoreo(int $id, string $ip, string $estado, ?float $tiempoMs, string $fecha): int
{
    try {
        return insertarMonitoreo($id, $ip, $estado, $tiempoMs, $fecha);
    } catch (PDOException $error) {
        if (codigoErrorMysql($error) === ERROR_MYSQL_FK) {
            responderError(MENSAJE_DISPOSITIVO_NO_EXISTE, 404);
        }
        throw $error;
    }
}
