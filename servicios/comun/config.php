<?php
/**
 * Configuración compartida por todos los servicios: URLs base, conexión a
 * MySQL y clave interna para las peticiones entre servicios.
 * Valores de desarrollo local; no usar en producción.
 */

declare(strict_types=1);

// Zona horaria de todas las fechas. Las genera PHP, nunca NOW() de MySQL.
// El php.ini de XAMPP puede traer otra zona (Europe/Berlin).
date_default_timezone_set('America/Mazatlan');

// URL base de la interfaz web.
const URL_INTERFAZ = 'http://localhost/monitoreo-red-soa/web';

// URLs base de cada servicio (sin barra final).
const URL_SERVICIOS = 'http://localhost/monitoreo-red-soa/servicios';
const URL_DISPOSITIVOS = URL_SERVICIOS . '/dispositivos';
const URL_ALERTAS = URL_SERVICIOS . '/alertas';
const URL_MONITOREO = URL_SERVICIOS . '/monitoreo';
const URL_AUTH = URL_SERVICIOS . '/auth';

// Conexión a MySQL/MariaDB (usuario root sin contraseña, solo desarrollo).
const DB_HOST = '127.0.0.1';
const DB_PUERTO = 3306;
const DB_NOMBRE = 'monitoreo_red_soa';
const DB_USUARIO = 'root';
const DB_CLAVE = '';
const DB_CHARSET = 'utf8mb4';
const DB_COLACION = 'utf8mb4_unicode_ci';

// Valor de la cabecera X-Servicio-Clave para peticiones entre servicios.
const CLAVE_SERVICIO = 'clave-desarrollo-cambiar';

// Tiempo máximo de espera (segundos) de una petición entre servicios (comun/http.php).
const TIEMPO_ESPERA_SERVICIOS = 3;
