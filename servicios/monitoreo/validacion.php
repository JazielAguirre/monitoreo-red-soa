<?php
/**
 * Reglas de validación del Servicio de Monitoreo (docs/API.md).
 * Cada función devuelve ['datos' => valores limpios, 'errores' => mensaje por campo].
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/enrutador.php';

const ESTADOS_MONITOREO = ['activo', 'inactivo'];
const POR_PAGINA_POR_DEFECTO = 10;
const POR_PAGINA_MAXIMO = 100;
const LIMITE_RECIENTES_POR_DEFECTO = 10;
const LIMITE_RECIENTES_MAXIMO = 50;
const MENSAJE_FECHA_DIA = 'Fecha no válida, usa el formato YYYY-MM-DD';

/**
 * Valida los filtros de GET /monitoreo. Un filtro vacío equivale a no filtrar.
 */
function validarFiltrosHistorial(array $consulta): array
{
    $dispositivo = filtroOpcional($consulta, 'dispositivo');
    $estado = filtroOpcional($consulta, 'estado');
    $desde = filtroOpcional($consulta, 'desde');
    $hasta = filtroOpcional($consulta, 'hasta');
    $pagina = filtroOpcional($consulta, 'pagina');
    $porPagina = filtroOpcional($consulta, 'por_pagina');

    $errores = array_filter([
        'dispositivo' => $dispositivo === null || (is_string($dispositivo) && esIdValido($dispositivo))
            ? null : 'Id de dispositivo no válido',
        'estado' => $estado === null || in_array($estado, ESTADOS_MONITOREO, true) ? null : 'Estado no válido',
        'desde' => $desde === null || esFechaDia($desde) ? null : MENSAJE_FECHA_DIA,
        'hasta' => $hasta === null || esFechaDia($hasta) ? null : MENSAJE_FECHA_DIA,
        'pagina' => $pagina === null || esEnteroEnRango($pagina, 1, 999999999)
            ? null : 'La página debe ser un número entero mayor o igual a 1',
        'por_pagina' => $porPagina === null || esEnteroEnRango($porPagina, 1, POR_PAGINA_MAXIMO)
            ? null : 'Debe ser un número entre 1 y ' . POR_PAGINA_MAXIMO,
    ]);

    if (!isset($errores['desde']) && !isset($errores['hasta']) && $desde !== null && $hasta !== null && $desde > $hasta) {
        $errores['hasta'] = 'La fecha final debe ser igual o posterior a la inicial';
    }

    $datos = [
        'dispositivo' => $dispositivo === null ? null : (int) $dispositivo,
        'estado' => $estado,
        'desde' => $desde,
        'hasta' => $hasta,
        'pagina' => $pagina === null ? 1 : (int) $pagina,
        'por_pagina' => $porPagina === null ? POR_PAGINA_POR_DEFECTO : (int) $porPagina,
    ];

    return ['datos' => $datos, 'errores' => $errores];
}

/**
 * Valida el parámetro limite de GET /monitoreo/recientes.
 */
function validarLimiteRecientes(array $consulta): array
{
    $limite = filtroOpcional($consulta, 'limite');

    $errores = $limite === null || esEnteroEnRango($limite, 1, LIMITE_RECIENTES_MAXIMO)
        ? []
        : ['limite' => 'El límite debe ser un número entre 1 y ' . LIMITE_RECIENTES_MAXIMO];

    return ['datos' => ['limite' => $limite === null ? LIMITE_RECIENTES_POR_DEFECTO : (int) $limite], 'errores' => $errores];
}

/**
 * Indica si el texto es un entero sin signo (sin ceros a la izquierda) dentro del rango.
 */
function esEnteroEnRango(mixed $valor, int $minimo, int $maximo): bool
{
    return is_string($valor) && preg_match('/^[1-9][0-9]{0,8}$/', $valor) === 1
        && (int) $valor >= $minimo && (int) $valor <= $maximo;
}

/**
 * Indica si el valor es un día real con formato YYYY-MM-DD.
 */
function esFechaDia(mixed $valor): bool
{
    if (!is_string($valor)) {
        return false;
    }

    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

    return $fecha !== false && $fecha->format('Y-m-d') === $valor;
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
