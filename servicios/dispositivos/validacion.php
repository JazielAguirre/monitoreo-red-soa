<?php
/**
 * Reglas de validación del Servicio de Dispositivos (docs/API.md).
 * Cada función devuelve ['datos' => valores limpios, 'errores' => mensaje por campo].
 */

declare(strict_types=1);

const TIPOS_DISPOSITIVO = ['computadora', 'servidor', 'impresora', 'router'];
const ESTADOS_DISPOSITIVO = ['activo', 'inactivo', 'desconocido'];
const ESTADOS_MONITOREO = ['activo', 'inactivo'];
const LONGITUD_MAXIMA_TEXTO = 100;
const TIEMPO_MAXIMO_MS = 99999.99; // Límite de DECIMAL(7,2).

/**
 * Valida el cuerpo de POST y PUT. Solo toma nombre, ip, tipo y ubicacion:
 * cualquier otro campo (estado, id, ultimo_*...) se ignora.
 */
function validarDatosDispositivo(array $cuerpo): array
{
    $datos = [
        'nombre' => textoRecortado($cuerpo, 'nombre'),
        'ip' => textoRecortado($cuerpo, 'ip'),
        'tipo' => textoRecortado($cuerpo, 'tipo'),
        'ubicacion' => textoRecortado($cuerpo, 'ubicacion'),
    ];

    $errores = array_filter([
        'nombre' => validarTextoObligatorio($datos['nombre'], 'El nombre es obligatorio'),
        'ip' => esIpv4($datos['ip']) ? null : 'Dirección IP no válida',
        'tipo' => in_array($datos['tipo'], TIPOS_DISPOSITIVO, true) ? null : 'Tipo de dispositivo no válido',
        'ubicacion' => validarTextoObligatorio($datos['ubicacion'], 'La ubicación es obligatoria'),
    ]);

    return ['datos' => $datos, 'errores' => $errores];
}

/**
 * Valida los filtros de GET /dispositivos. Un filtro vacío equivale a no filtrar.
 */
function validarFiltrosDispositivos(array $consulta): array
{
    $estado = filtroOpcional($consulta, 'estado');
    $tipo = filtroOpcional($consulta, 'tipo');

    $errores = array_filter([
        'estado' => $estado === null || in_array($estado, ESTADOS_DISPOSITIVO, true) ? null : 'Estado no válido',
        'tipo' => $tipo === null || in_array($tipo, TIPOS_DISPOSITIVO, true) ? null : 'Tipo de dispositivo no válido',
    ]);

    return ['datos' => ['estado' => $estado, 'tipo' => $tipo], 'errores' => $errores];
}

/**
 * Valida el cuerpo de PATCH /dispositivos/{id}/estado.
 * activo → tiempo numérico (entero o decimal) ≥ 0; inactivo → tiempo null.
 */
function validarCambioEstado(array $cuerpo): array
{
    $estado = $cuerpo['estado'] ?? null;
    $tiempo = $cuerpo['tiempo_respuesta_ms'] ?? null;
    $fecha = $cuerpo['fecha'] ?? null;

    $errores = array_filter([
        'estado' => in_array($estado, ESTADOS_MONITOREO, true) ? null : 'Estado no válido',
        'tiempo_respuesta_ms' => validarTiempoSegunEstado($estado, $tiempo),
        'fecha' => esFechaValida($fecha) ? null : 'Fecha no válida, usa el formato YYYY-MM-DD HH:MM:SS',
    ]);

    $datos = [
        'estado' => $estado,
        'tiempo_respuesta_ms' => is_int($tiempo) || is_float($tiempo) ? (float) $tiempo : null,
        'fecha' => $fecha,
    ];

    return ['datos' => $datos, 'errores' => $errores];
}

/**
 * Devuelve el campo recortado, o null si falta o no es texto.
 */
function textoRecortado(array $cuerpo, string $campo): ?string
{
    $valor = $cuerpo[$campo] ?? null;

    return is_string($valor) ? trim($valor) : null;
}

/**
 * Devuelve el filtro recortado, o null si falta o está vacío.
 * Un filtro que no es texto (por ejemplo estado[]=x) se devuelve tal cual para que falle.
 */
function filtroOpcional(array $consulta, string $campo): mixed
{
    $valor = $consulta[$campo] ?? null;

    if (is_string($valor)) {
        $valor = trim($valor);
        return $valor === '' ? null : $valor;
    }

    return $valor;
}

/**
 * Mensaje de error de un texto obligatorio de 1 a 100 caracteres, o null si es válido.
 */
function validarTextoObligatorio(?string $valor, string $mensajeVacio): ?string
{
    if ($valor === null || $valor === '') {
        return $mensajeVacio;
    }

    if (mb_strlen($valor, 'UTF-8') > LONGITUD_MAXIMA_TEXTO) {
        return 'Máximo ' . LONGITUD_MAXIMA_TEXTO . ' caracteres';
    }

    return null;
}

/**
 * Indica si el valor es una dirección IPv4 válida.
 */
function esIpv4(?string $ip): bool
{
    return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

/**
 * Mensaje de error del tiempo de respuesta según el estado, o null si es válido.
 * Si el estado no es válido, el error ya se reporta en el campo estado.
 */
function validarTiempoSegunEstado(mixed $estado, mixed $tiempo): ?string
{
    if ($estado === 'activo') {
        $esNumero = is_int($tiempo) || is_float($tiempo);

        return $esNumero && $tiempo >= 0 && $tiempo <= TIEMPO_MAXIMO_MS
            ? null
            : 'El tiempo de respuesta debe ser un número mayor o igual a 0';
    }

    if ($estado === 'inactivo' && $tiempo !== null) {
        return 'Con estado inactivo, el tiempo de respuesta debe ser null';
    }

    return null;
}

/**
 * Indica si el valor es una fecha real con formato YYYY-MM-DD HH:MM:SS.
 */
function esFechaValida(mixed $valor): bool
{
    if (!is_string($valor)) {
        return false;
    }

    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $valor);

    return $fecha !== false && $fecha->format('Y-m-d H:i:s') === $valor;
}
