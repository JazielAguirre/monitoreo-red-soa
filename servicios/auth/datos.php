<?php
/**
 * Acceso a la tabla usuarios (la única tabla de este servicio).
 * Todas las consultas son preparadas; los valores nunca se concatenan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../comun/db.php';

/**
 * Busca un usuario por su nombre de usuario. Devuelve la fila (con su hash) o null.
 */
function consultarUsuarioPorNombre(string $usuario): ?array
{
    $consulta = obtenerConexion()->prepare(
        'SELECT id, usuario, nombre, contrasena_hash FROM usuarios WHERE usuario = :usuario'
    );
    $consulta->execute(['usuario' => $usuario]);
    $fila = $consulta->fetch();

    return $fila === false ? null : $fila;
}

/**
 * El usuario tal como viaja en la API y se guarda en la sesión: sin el hash.
 */
function formatearUsuario(array $fila): array
{
    return ['id' => (int) $fila['id'], 'usuario' => $fila['usuario'], 'nombre' => $fila['nombre']];
}
