# Sistema de Monitoreo de Red SOA

Aplicación web local para registrar dispositivos de red (computadoras, servidores,
impresoras, routers), comprobar con ping si responden, guardar el historial de
monitoreos y generar alertas cuando un dispositivo no responde.

Proyecto escolar para demostrar una Arquitectura Orientada a Servicios (SOA):
la interfaz web se comunica por HTTP + JSON con servicios independientes
(dispositivos, monitoreo, alertas y autenticación), y cada servicio es dueño de sus tablas.

## Requisitos

- [XAMPP](https://www.apachefriends.org/) con PHP 8 y MySQL/MariaDB.
- Apache y MySQL encendidos.

## Instalación

La carpeta del proyecto debe quedar dentro de `htdocs` de XAMPP con el nombre `monitoreo-red-soa`:

| Sistema | Ruta |
|---|---|
| Windows | `C:\xampp\htdocs\monitoreo-red-soa` |
| Linux | `/opt/lampp/htdocs/monitoreo-red-soa` (puede ser un enlace simbólico) |

En Linux, Apache corre como el usuario `daemon`: si el proyecto vive en tu carpeta
personal, `daemon` necesita permiso de paso (`x`) en cada carpeta del camino.

## Uso

- Interfaz: <http://localhost/monitoreo-red-soa/web/>
- Servicios: `http://localhost/monitoreo-red-soa/servicios/{servicio}/`

## Librerías del frontend

Se guardan en `web/vendor/` (sin CDN, para que la demo funcione sin internet):

| Librería | Versión |
|---|---|
| Bootstrap (CSS y JS bundle) | 5.3.8 |
| Bootstrap Icons | 1.13.1 |
| Chart.js | 4.5.1 |
| Inter (woff2: 400, 500, 600, 700) | 4.1 |
