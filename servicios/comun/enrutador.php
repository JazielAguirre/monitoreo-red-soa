<?php
/**
 * Enrutador de los servicios: elige la función que atiende la petición según
 * el método y los segmentos de la ruta, o responde 404 o 405 en JSON.
 *
 * Cada ruta es [método, patrón, función, acceso]. El patrón une los segmentos con "/":
 * ''  →  /servicio     '{id}'  →  /servicio/5     '{id}/estado'  →  /servicio/5/estado
 * {id} solo acepta enteros positivos, y la función lo recibe como int.
 * El acceso (ACCESO_INTERFAZ, ACCESO_INTERNO o ACCESO_PUBLICO de comun/auth.php)
 * se comprueba solo cuando la ruta y el método coinciden, justo antes de la función.
 */

declare(strict_types=1);

require_once __DIR__ . '/respuesta.php';
require_once __DIR__ . '/auth.php';

/**
 * Atiende la petición con la ruta que coincide y termina.
 */
function despachar(array $rutas, string $metodo, array $segmentos): never
{
    $metodosPermitidos = [];

    foreach ($rutas as [$metodoRuta, $patron, $funcion, $acceso]) {
        $parametros = coincidirPatron($patron, $segmentos);

        if ($parametros === null) {
            continue;
        }

        if ($metodoRuta === $metodo) {
            verificarAcceso($acceso);
            $funcion(...$parametros);
            // Las funciones de los endpoints terminan respondiendo; esto es un respaldo.
            throw new LogicException("La función $funcion no envió respuesta");
        }

        $metodosPermitidos[] = $metodoRuta;
    }

    if ($metodosPermitidos !== []) {
        responderMetodoNoPermitido($metodosPermitidos);
    }

    responderError('La ruta no existe', 404);
}

/**
 * Compara los segmentos con un patrón. Devuelve los parámetros ({id}) si
 * coincide, o null si no.
 */
function coincidirPatron(string $patron, array $segmentos): ?array
{
    $partes = $patron === '' ? [] : explode('/', $patron);

    if (count($partes) !== count($segmentos)) {
        return null;
    }

    $parametros = [];

    foreach ($partes as $i => $parte) {
        if ($parte === '{id}') {
            if (!esIdValido($segmentos[$i])) {
                return null;
            }
            $parametros[] = (int) $segmentos[$i];
        } elseif ($parte !== $segmentos[$i]) {
            return null;
        }
    }

    return $parametros;
}

/**
 * Un id es un entero positivo sin ceros a la izquierda, de hasta 10 dígitos
 * (cabe en INT UNSIGNED).
 */
function esIdValido(string $segmento): bool
{
    return preg_match('/^[1-9][0-9]{0,9}$/', $segmento) === 1 && (int) $segmento <= 4294967295;
}

/**
 * Responde 405 indicando en la cabecera Allow los métodos que sí acepta la ruta.
 */
function responderMetodoNoPermitido(array $metodos): never
{
    header('Allow: ' . implode(', ', array_unique($metodos)));
    responderError('Método no permitido', 405);
}
