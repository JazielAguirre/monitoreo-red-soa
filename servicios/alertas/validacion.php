<?php
/**
 * Reglas de validación del Servicio de Alertas (docs/API.md).
 * Cada función devuelve ['datos' => valores limpios, 'errores' => mensaje por campo].
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/enrutador.php';
require_once __DIR__ . '/../comun/peticion.php';

const TIPOS_ALERTA = ['sin_respuesta'];
const LONGITUD_MAXIMA_MENSAJE = 255;
const LIMITE_ALERTAS_POR_DEFECTO = 50;
const LIMITE_ALERTAS_MAXIMO = 100;
const ID_MAXIMO = 4294967295; // INT UNSIGNED.

/**
 * Valida los filtros de GET /alertas. Un filtro vacío equivale a no filtrar.
 */
function validarFiltrosAlertas(array $consulta): array
{
    $atendida = filtroOpcional($consulta, 'atendida');
    $dispositivo = filtroOpcional($consulta, 'dispositivo');
    $limite = filtroOpcional($consulta, 'limite');

    $limiteValido = is_string($limite) && preg_match('/^\d{1,3}$/', $limite) === 1
        && (int) $limite >= 1 && (int) $limite <= LIMITE_ALERTAS_MAXIMO;

    $errores = array_filter([
        'atendida' => $atendida === null || $atendida === '0' || $atendida === '1'
            ? null : 'El filtro atendida debe ser 0 o 1',
        'dispositivo' => $dispositivo === null || (is_string($dispositivo) && esIdValido($dispositivo))
            ? null : 'Id de dispositivo no válido',
        'limite' => $limite === null || $limiteValido
            ? null : 'El límite debe ser un número entre 1 y ' . LIMITE_ALERTAS_MAXIMO,
    ]);

    $datos = [
        'atendida' => $atendida === null ? null : (int) $atendida,
        'dispositivo' => $dispositivo === null ? null : (int) $dispositivo,
        'limite' => $limite === null ? LIMITE_ALERTAS_POR_DEFECTO : (int) $limite,
    ];

    return ['datos' => $datos, 'errores' => $errores];
}

/**
 * Valida el cuerpo de POST /alertas.
 */
function validarNuevaAlerta(array $cuerpo): array
{
    $dispositivoId = $cuerpo['dispositivo_id'] ?? null;
    $tipo = $cuerpo['tipo'] ?? null;
    $mensaje = is_string($cuerpo['mensaje'] ?? null) ? trim($cuerpo['mensaje']) : null;

    $errores = array_filter([
        'dispositivo_id' => is_int($dispositivoId) && $dispositivoId >= 1 && $dispositivoId <= ID_MAXIMO
            ? null : 'Id de dispositivo no válido',
        'tipo' => in_array($tipo, TIPOS_ALERTA, true) ? null : 'Tipo de alerta no válido',
        'mensaje' => validarMensaje($mensaje),
    ]);

    return [
        'datos' => ['dispositivo_id' => $dispositivoId, 'tipo' => $tipo, 'mensaje' => $mensaje],
        'errores' => $errores,
    ];
}

/**
 * Mensaje de error del texto de la alerta (1 a 255 caracteres), o null si es válido.
 */
function validarMensaje(?string $mensaje): ?string
{
    if ($mensaje === null || $mensaje === '') {
        return 'El mensaje es obligatorio';
    }

    if (mb_strlen($mensaje, 'UTF-8') > LONGITUD_MAXIMA_MENSAJE) {
        return 'Máximo ' . LONGITUD_MAXIMA_MENSAJE . ' caracteres';
    }

    return null;
}
