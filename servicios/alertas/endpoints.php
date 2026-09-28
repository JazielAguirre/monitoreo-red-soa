<?php
/**
 * Endpoints del Servicio de Alertas (docs/API.md, "Servicio de Alertas").
 * Cada función atiende una ruta y termina respondiendo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/../comun/peticion.php';
require_once __DIR__ . '/datos.php';
require_once __DIR__ . '/validacion.php';

const ERROR_MYSQL_DUPLICADO = 1062;
const ERROR_MYSQL_FK = 1452;

/**
 * GET /alertas [Interfaz]: de la más reciente a la más antigua, con filtros
 * atendida, dispositivo y limite.
 */
function listarAlertas(): never
{
    $validacion = validarFiltrosAlertas($_GET);

    if ($validacion['errores'] !== []) {
        responderError('Filtros no válidos', 400, $validacion['errores']);
    }

    $filtros = $validacion['datos'];
    $filas = consultarAlertas($filtros['atendida'], $filtros['dispositivo'], $filtros['limite']);

    responder(array_map('formatearAlerta', $filas));
}

/**
 * GET /alertas/conteo [Interfaz]: { pendientes, atendidas }.
 */
function contarAlertasPorEstado(): never
{
    responder(contarAlertas());
}

/**
 * POST /alertas [Interno]: la usa Monitoreo cuando un dispositivo no responde.
 * Si el dispositivo ya tiene una alerta pendiente, no se crea otra: responde
 * 200 con la existente y creada = false.
 */
function crearAlerta(): never
{
    $validacion = validarNuevaAlerta(leerCuerpoJson());

    if ($validacion['errores'] !== []) {
        responderError('Revisa los datos de la alerta', 400, $validacion['errores']);
    }

    $datos = $validacion['datos'];
    responderSiYaHayPendiente($datos['dispositivo_id']);

    try {
        $id = insertarAlerta($datos, date('Y-m-d H:i:s'));
    } catch (PDOException $error) {
        manejarErrorAlInsertar($error, $datos['dispositivo_id']);
    }

    responder(['alerta' => formatearAlerta(consultarAlerta($id)), 'creada' => true], 201);
}

/**
 * PATCH /alertas/{id}/atender [Interfaz]: idempotente; si ya estaba atendida,
 * responde 200 sin cambiarla.
 */
function atenderAlerta(int $id): never
{
    if (consultarAlerta($id) === null) {
        responderError('La alerta no existe', 404);
    }

    marcarAlertaAtendida($id, date('Y-m-d H:i:s'));

    responder(formatearAlerta(consultarAlerta($id)));
}

/**
 * Si el dispositivo ya tiene una alerta pendiente, responde 200 con ella.
 */
function responderSiYaHayPendiente(int $dispositivoId): void
{
    $pendiente = consultarAlertaPendiente($dispositivoId);

    if ($pendiente !== null) {
        responder(['alerta' => formatearAlerta($pendiente), 'creada' => false]);
    }
}

/**
 * 1062: otra petición creó la pendiente entre la consulta y el INSERT (la
 * frena uq_alertas_dispositivo_pendiente); se responde con esa alerta.
 * 1452: la llave foránea indica que el dispositivo no existe.
 */
function manejarErrorAlInsertar(PDOException $error, int $dispositivoId): never
{
    $codigo = codigoErrorMysql($error);

    if ($codigo === ERROR_MYSQL_DUPLICADO) {
        error_log("[alertas] POST simultáneos: ya existía una pendiente para el dispositivo $dispositivoId (1062)");
        responderSiYaHayPendiente($dispositivoId);
    }

    if ($codigo === ERROR_MYSQL_FK) {
        responderError('El dispositivo no existe', 404);
    }

    throw $error;
}
