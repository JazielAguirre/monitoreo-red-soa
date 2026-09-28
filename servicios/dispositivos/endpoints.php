<?php
/**
 * Endpoints del Servicio de Dispositivos (docs/API.md, "Servicio de Dispositivos").
 * Cada función atiende una ruta y termina respondiendo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/respuesta.php';
require_once __DIR__ . '/../comun/peticion.php';
require_once __DIR__ . '/datos.php';
require_once __DIR__ . '/validacion.php';

const MENSAJE_DATOS_INVALIDOS = 'Revisa los datos del formulario';
const MENSAJE_IP_DUPLICADA = 'Ya existe un dispositivo con esa IP';
const MENSAJE_NO_EXISTE = 'El dispositivo no existe';

/**
 * GET /dispositivos [Interfaz]: lista ordenada por nombre, con filtros estado y tipo.
 */
function listarDispositivos(): never
{
    $validacion = validarFiltrosDispositivos($_GET);
    responderSiHayErrores($validacion['errores'], 'Filtros no válidos');

    $filtros = $validacion['datos'];
    $filas = consultarDispositivos($filtros['estado'], $filtros['tipo']);

    responder(array_map('formatearDispositivo', $filas));
}

/**
 * GET /dispositivos/{id} [Interfaz].
 */
function obtenerDispositivo(int $id): never
{
    responder(formatearDispositivo(buscarDispositivoOResponder404($id)));
}

/**
 * POST /dispositivos [Interfaz]: registra un dispositivo en estado desconocido.
 */
function crearDispositivo(): never
{
    $validacion = validarDatosDispositivo(leerCuerpoJson());
    responderSiHayErrores($validacion['errores'], MENSAJE_DATOS_INVALIDOS);

    $datos = $validacion['datos'];
    comprobarIpDisponible($datos['ip'], null);

    try {
        $id = insertarDispositivo($datos, date('Y-m-d H:i:s'));
    } catch (PDOException $error) {
        responderSiIpDuplicada($error);
    }

    responder(formatearDispositivo(consultarDispositivo($id)), 201);
}

/**
 * PUT /dispositivos/{id} [Interfaz]: si la IP cambia, el dispositivo vuelve a
 * desconocido y se borran los datos del último monitoreo (eran de otra IP).
 */
function actualizarDispositivo(int $id): never
{
    $actual = buscarDispositivoOResponder404($id);

    $validacion = validarDatosDispositivo(leerCuerpoJson());
    responderSiHayErrores($validacion['errores'], MENSAJE_DATOS_INVALIDOS);

    $datos = $validacion['datos'];
    comprobarIpDisponible($datos['ip'], $id);

    try {
        guardarDatosDispositivo($id, $datos, $datos['ip'] !== $actual['ip']);
    } catch (PDOException $error) {
        responderSiIpDuplicada($error);
    }

    responder(formatearDispositivo(consultarDispositivo($id)));
}

/**
 * DELETE /dispositivos/{id} [Interfaz]: sus monitoreos y alertas se borran por cascada.
 */
function eliminarDispositivo(int $id): never
{
    buscarDispositivoOResponder404($id);
    borrarDispositivo($id);

    responder(['id' => $id]);
}

/**
 * PATCH /dispositivos/{id}/estado [Interno]: resultado del último ping,
 * enviado por el Servicio de Monitoreo.
 */
function actualizarEstadoDispositivo(int $id): never
{
    buscarDispositivoOResponder404($id);

    $validacion = validarCambioEstado(leerCuerpoJson());
    responderSiHayErrores($validacion['errores'], MENSAJE_DATOS_INVALIDOS);

    $datos = $validacion['datos'];
    guardarEstadoDispositivo($id, $datos['estado'], $datos['tiempo_respuesta_ms'], $datos['fecha']);

    responder(formatearDispositivo(consultarDispositivo($id)));
}

/**
 * Devuelve el dispositivo o responde 404 si no existe.
 */
function buscarDispositivoOResponder404(int $id): array
{
    return consultarDispositivo($id) ?? responderError(MENSAJE_NO_EXISTE, 404);
}

/**
 * Responde 400 con los errores por campo, si los hay.
 */
function responderSiHayErrores(array $errores, string $mensaje): void
{
    if ($errores !== []) {
        responderError($mensaje, 400, $errores);
    }
}

/**
 * Responde 409 si otro dispositivo ya usa la IP.
 */
function comprobarIpDisponible(string $ip, ?int $excluirId): void
{
    if (existeIpEnOtroDispositivo($ip, $excluirId)) {
        responderIpDuplicada();
    }
}

/**
 * Responde 409 si el error es por IP duplicada (dos peticiones casi al mismo
 * tiempo); si es otro error, lo vuelve a lanzar.
 */
function responderSiIpDuplicada(PDOException $error): never
{
    if (esErrorIpDuplicada($error)) {
        responderIpDuplicada();
    }

    throw $error;
}

function responderIpDuplicada(): never
{
    responderError(MENSAJE_IP_DUPLICADA, 409, ['ip' => MENSAJE_IP_DUPLICADA]);
}
