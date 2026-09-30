<?php
/**
 * Reglas de validación del Servicio de Autenticación (docs/API.md).
 * Devuelve ['datos' => valores limpios, 'errores' => mensaje por campo].
 */

declare(strict_types=1);

const LONGITUD_MAXIMA_USUARIO = 50;      // VARCHAR(50) de usuarios.usuario
const LONGITUD_MAXIMA_CONTRASENA = 72;   // bcrypt solo usa los primeros 72 bytes

/**
 * Valida el cuerpo de POST /auth/login: los dos campos, como texto y no vacíos.
 * Las longitudes no se reportan como error de campo: un usuario o una contraseña
 * demasiado largos no pueden ser correctos y se responden como credenciales
 * incorrectas (credencialesPosibles()), sin dar pistas.
 */
function validarLogin(array $cuerpo): array
{
    $usuario = is_string($cuerpo['usuario'] ?? null) ? trim($cuerpo['usuario']) : '';
    $contrasena = is_string($cuerpo['contrasena'] ?? null) ? $cuerpo['contrasena'] : '';

    $errores = array_filter([
        'usuario' => $usuario === '' ? 'Escribe tu usuario' : null,
        'contrasena' => $contrasena === '' ? 'Escribe tu contraseña' : null,
    ]);

    return ['datos' => ['usuario' => $usuario, 'contrasena' => $contrasena], 'errores' => $errores];
}

/**
 * Un usuario y una contraseña que caben en la tabla y en bcrypt.
 */
function credencialesPosibles(string $usuario, string $contrasena): bool
{
    return mb_strlen($usuario) <= LONGITUD_MAXIMA_USUARIO && strlen($contrasena) <= LONGITUD_MAXIMA_CONTRASENA;
}
