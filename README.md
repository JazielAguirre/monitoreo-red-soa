# Sistema de Monitoreo de Red SOA

Aplicación web local para registrar dispositivos de red (computadoras, servidores,
impresoras, routers), comprobar con ping si responden, guardar el historial de
monitoreos y generar alertas cuando un dispositivo no responde.

Es un proyecto escolar para demostrar una **Arquitectura Orientada a Servicios (SOA)**:

```
Interfaz web (web/)  ──HTTP + JSON──►  Servicios (servicios/)  ──►  MySQL
                                        ├── auth          → tabla usuarios
                                        ├── dispositivos  → tabla dispositivos
                                        ├── monitoreo     → tabla monitoreos
                                        └── alertas       → tabla alertas
```

Cada servicio solo lee y escribe su propia tabla. Cuando uno necesita algo de otro,
se lo pide por HTTP: por ejemplo, Monitoreo le pide la IP a Dispositivos, le avisa
el resultado y le pide a Alertas que cree una alerta.

---

## Contenido

1. [Requisitos](#1-requisitos)
2. [Instalación en Linux](#2-instalación-en-linux)
3. [Instalación en Windows](#3-instalación-en-windows)
4. [Cargar la base de datos](#4-cargar-la-base-de-datos)
5. [Comprobar que funciona](#5-comprobar-que-funciona)
6. [Pruebas automáticas](#6-pruebas-automáticas)
7. [Preparar la demo](#7-preparar-la-demo)
8. [Problemas comunes](#8-problemas-comunes)
9. [Estructura del proyecto](#9-estructura-del-proyecto)

---

## 1. Requisitos

| Qué | Para qué |
|---|---|
| [XAMPP](https://www.apachefriends.org/) con PHP 8.2 y MariaDB (Apache + MySQL) | Servir la aplicación y la base de datos |
| git | Clonar el repositorio |
| Google Chrome, Chromium o Microsoft Edge | Solo para `pruebas/interfaz.php` y parte de `pruebas/auth.php` (si no hay, esas pruebas se omiten con un aviso) |

No hace falta Composer, npm ni internet: las librerías del frontend están en
`web/vendor/`.

La carpeta del proyecto **debe llamarse `monitoreo-red-soa`** y quedar dentro de
`htdocs` de XAMPP (en Linux puede ser un enlace simbólico). Las URLs de
`servicios/comun/config.php` usan ese nombre.

---

## 2. Instalación en Linux

Los ejemplos suponen XAMPP en `/opt/lampp` y el proyecto en `~/Proyectos`.

**1. Instala XAMPP** (si no lo tienes):

```bash
chmod +x xampp-linux-x64-8.2.*-installer.run
sudo ./xampp-linux-x64-8.2.*-installer.run
```

**2. Clona el repositorio y enlázalo desde `htdocs`:**

```bash
mkdir -p ~/Proyectos && cd ~/Proyectos
git clone https://github.com/JazielAguirre/monitoreo-red-soa.git monitoreo-red-soa
ln -s ~/Proyectos/monitoreo-red-soa /opt/lampp/htdocs/monitoreo-red-soa
# Si htdocs es de root: sudo ln -s ...
```

**3. Da a Apache permiso de paso hasta el proyecto.** Apache corre como el usuario
`daemon`, no como tú: necesita el permiso `x` (entrar, sin poder listar ni leer)
en **cada carpeta del camino**. Comprueba cuáles le faltan:

```bash
namei -l /opt/lampp/htdocs/monitoreo-red-soa/web/index.html
```

Si tu carpeta personal aparece como `drwxr-x---` o `drwx------`, dale paso solo a
`daemon` (recomendado) o, si no tienes `setfacl`, a todos:

```bash
setfacl -m u:daemon:x ~          # solo daemon puede entrar (no listar)
# o bien:
chmod o+x ~                       # cualquier usuario puede entrar (no listar)
```

No cambies permisos de otras carpetas si `namei` no lo pide. Sin este paso, Apache
responde **403 Forbidden**.

**4. Configura MySQL para que arranque.** En sistemas con `pam_tmpdir` (Ubuntu,
Debian y derivados), MariaDB hereda al arrancar con `sudo` una carpeta temporal
donde el usuario `mysql` no puede escribir, y no arranca. Agrega esta línea
debajo de `[mysqld]` en `/opt/lampp/etc/my.cnf`:

```ini
[mysqld]
tmpdir = /tmp
```

**5. Enciende Apache y MySQL:**

```bash
sudo /opt/lampp/lampp startapache
sudo /opt/lampp/lampp startmysql
```

**6. (Para usarla desde el celular) Abre el puerto 80 en el cortafuegos.** Si usas
`ufw`, permite solo tu red local (cambia `192.168.0.0/24` por la tuya):

```bash
sudo ufw status
sudo ufw allow from 192.168.0.0/24 to any port 80 proto tcp
```

Sigue en [Cargar la base de datos](#4-cargar-la-base-de-datos).

---

## 3. Instalación en Windows

**1. Instala XAMPP** en `C:\xampp` (la ruta por defecto).

**2. Clona el repositorio dentro de `htdocs`** (en PowerShell o Git Bash):

```powershell
git clone https://github.com/JazielAguirre/monitoreo-red-soa.git C:\xampp\htdocs\monitoreo-red-soa
```

**3. Enciende Apache y MySQL** desde el panel de XAMPP (botón *Start* de cada uno).
La primera vez, Windows pregunta si Apache puede usar la red: **permítelo en redes
privadas** (hace falta para entrar desde el celular).

**4. En PowerShell usa `curl.exe`**, no `curl`: `curl` es otro comando de PowerShell.

En Windows, los comandos de este README se escriben con los binarios de XAMPP:

| Linux | Windows |
|---|---|
| `/opt/lampp/bin/php` | `C:/xampp/php/php.exe` |
| `/opt/lampp/bin/mysql` | `C:/xampp/mysql/bin/mysql.exe` |
| `curl` | `curl.exe` |

Si es la primera vez que el proyecto corre en una computadora con Windows, sigue
también la lista de [`docs/WINDOWS.md`](docs/WINDOWS.md).

---

## 4. Cargar la base de datos

Desde la carpeta del proyecto. `schema.sql` **borra y recrea** la base
`monitoreo_red_soa`; `seed.sql` carga los datos de prueba (6 dispositivos,
56 monitoreos, 4 alertas y el usuario `admin`).

```bash
# Linux
cd ~/Proyectos/monitoreo-red-soa
/opt/lampp/bin/mysql -u root < database/schema.sql
/opt/lampp/bin/mysql -u root monitoreo_red_soa < database/seed.sql
```

```powershell
# Windows (PowerShell): el operador < no existe, se usa cmd /c
cd C:\xampp\htdocs\monitoreo-red-soa
cmd /c "C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql"
cmd /c "C:\xampp\mysql\bin\mysql.exe -u root monitoreo_red_soa < database\seed.sql"
```

La base usa el usuario `root` sin contraseña (solo desarrollo local), como viene
XAMPP.

---

## 5. Comprobar que funciona

1. Abre <http://localhost/monitoreo-red-soa/web/>. Debe aparecer la pantalla de
   inicio de sesión.
2. Entra con **usuario `admin`** y **contraseña `admin123`**.
3. Desde la terminal, los servicios deben responder en JSON:

```bash
curl -s http://localhost/monitoreo-red-soa/servicios/auth/sesion
# {"ok":false,"error":"No hay una sesión activa"}    ← correcto: aún no hay sesión
```

Para entrar desde el celular (misma red WiFi): `http://<IP de la computadora>/monitoreo-red-soa/web/`.
`herramientas/preparar-demo.php` te dice cuál es (sección 7).

---

## 6. Pruebas automáticas

Cada suite **recarga la base al empezar y la deja con el seed limpio al terminar**
(también si falla o si la interrumpes con Ctrl+C): **borra lo que hayas agregado a
mano**. Necesitan Apache y MySQL encendidos.

```bash
cd ~/Proyectos/monitoreo-red-soa
/opt/lampp/bin/php pruebas/dispositivos.php
/opt/lampp/bin/php pruebas/alertas.php
/opt/lampp/bin/php pruebas/monitoreo.php
/opt/lampp/bin/php pruebas/auth.php
/opt/lampp/bin/php pruebas/interfaz.php
```

| Suite | Qué prueba | Tiempo aprox. |
|---|---|---|
| `dispositivos.php` | Los 6 endpoints de Dispositivos, validaciones, 404/405, acceso con sesión y con clave | 2 s |
| `alertas.php` | Endpoints de Alertas, una sola alerta pendiente por dispositivo (también con peticiones simultáneas) | 3 s |
| `monitoreo.php` | Flujo de monitoreo por HTTP entre servicios, 502 cuando otro servicio no responde, regla SOA, registro de llamadas y preparación de la demo | 15 s |
| `auth.php` | Login, cookie, 401/403, mismo tiempo exista o no el usuario, sesión sin bloqueo, redirecciones y «Salir» en el navegador | 1 min |
| `interfaz.php` | Las pantallas en un Chrome sin ventana: estados de carga/vacío/error, celular (390 px), proyector (1024 y 1280 px), teclado, caché | 6 min |

Cada suite termina con `Resultado: N de N comprobaciones correctas.` y con código
de salida distinto de 0 si algo falla. Las pruebas de navegador abren su propio
Chrome con un perfil temporal: nunca tocan el Chrome que tengas abierto. Si Chrome
no está en la ruta de siempre, indícalo con la variable `CHROME_BIN`.

---

## 7. Preparar la demo

Un solo comando deja todo listo: recarga la base, mueve las fechas del seed para
que la última ronda de monitoreo sea de **hace 10 minutos** (las alertas atendidas
siguen siendo de días anteriores), pone las IPs del **router** y de **PC Recepción**
de la red donde se presenta y comprueba que todo responde.

```bash
/opt/lampp/bin/php herramientas/preparar-demo.php
```

- Sin opciones, **detecta** la puerta de enlace (el router) y la IP de esta
  computadora (hace de PC Recepción y es la dirección para el celular).
- `--router=10.0.0.1 --pc=10.0.0.57` usa esas IPs en lugar de detectarlas.
- `--sin-red` es el plan B sin WiFi: el router pasa a `192.0.2.30` (no responde:
  sirve para mostrar una alerta) y PC Recepción a `127.0.0.2` (siempre responde).

Al final muestra un resumen (fechas nuevas, IPs, URL para el celular) y una
**comprobación previa** con ✓ o ✗ en cada línea: Apache, servicios, base de datos y
ping al router. Si algo falla, dice qué hacer.

Durante la parte de SOA de la demo, en otra terminal:

```bash
/opt/lampp/bin/php herramientas/ver-llamadas.php
```

muestra en vivo cómo se hablan los servicios:

```
[10:05:12] Navegador → Monitoreo: POST /monitoreo/7
[10:05:12]   Monitoreo → Dispositivos: GET /dispositivos/7 (200, 8 ms)
[10:05:13]   Monitoreo → Dispositivos: PATCH /dispositivos/7/estado (200, 12 ms)
[10:05:13]   Monitoreo → Alertas: POST /alertas (201, 10 ms)
```

El guion completo, con el reparto entre integrantes y el plan B, está en
[`docs/DEMO.md`](docs/DEMO.md).

---

## 8. Problemas comunes

| Síntoma | Causa | Solución |
|---|---|---|
| **403 Forbidden** al abrir la página (Linux) | Apache (`daemon`) no puede entrar a tu carpeta personal | Paso 3 de la instalación en Linux: `namei -l …` y `setfacl -m u:daemon:x ~` |
| **MySQL no arranca** (Linux) | `pam_tmpdir`: carpeta temporal sin permiso para `mysql` | Paso 4: `tmpdir = /tmp` en `/opt/lampp/etc/my.cnf` |
| **404** en `/servicios/...` | La carpeta no se llama `monitoreo-red-soa` o Apache no lee los `.htaccess` | Renombra la carpeta; en XAMPP `htdocs` ya trae `AllowOverride All` |
| Todas las pantallas dicen **"No se pudo conectar con el servidor"** | Apache apagado | `sudo /opt/lampp/lampp startapache` o el panel de XAMPP |
| Las pantallas muestran **"Error interno del servidor"** | MySQL apagado | `sudo /opt/lampp/lampp startmysql` o el panel de XAMPP |
| **El celular no carga** la página | Cortafuegos, otra red WiFi o IP equivocada | `ufw allow` (Linux) o permitir Apache en el Firewall de Windows; misma red; la URL que da `preparar-demo.php` |
| El navegador muestra **una versión vieja** | Caché de antes de la Fase 8 | Recarga forzada (Ctrl+Shift+R). Desde la Fase 8 las páginas se revalidan solas (`web/.htaccess`) |
| Después de cambiar a dónde apunta el enlace de `htdocs`, hay errores 500 o fallan las pruebas del fallo simulado | PHP recuerda la ruta anterior (`realpath_cache`) en cada proceso de Apache | Reinicia Apache (`sudo /opt/lampp/lampp restartapache` o el panel de XAMPP), o espera unos 2 minutos (`realpath_cache_ttl` es 120 s). No borres la carpeta anterior hasta entonces |
| La sesión se cierra | Pasaron 8 horas desde el login | Vuelve a iniciar sesión; te regresa a la pantalla donde estabas |
| Un dispositivo siempre sale **inactivo** aunque responda | Ping bloqueado para Apache o salida del ping en otro idioma | `docs/WINDOWS.md` (Windows) o prueba `curl -X POST …/servicios/monitoreo/1` y revisa `/opt/lampp/logs/php_error_log` |

---

## 9. Estructura del proyecto

```
database/       schema.sql (recrea la base), seed.sql (datos de prueba)
docs/           API.md (contrato de los endpoints), PLAN.md (fases), DEMO.md (guion),
                WINDOWS.md (verificación en Windows), diseno/ (referencia visual)
herramientas/   preparar-demo.php, ver-llamadas.php y sus funciones (solo línea de comandos)
pruebas/        las 5 suites y sus ayudas (solo línea de comandos)
servicios/      comun/ (infraestructura) y un servicio por carpeta: auth, dispositivos, monitoreo, alertas
web/            interfaz: HTML, css/ (tokens.css es el único archivo con colores), js/, vendor/
```

Por HTTP solo se sirven `web/` y los puntos de entrada de los servicios; el resto
del repositorio responde 404 (`.htaccess` de la raíz).

Documentación para el equipo:

- [`docs/API.md`](docs/API.md): contrato de la API (la fuente de verdad).
- [`docs/PLAN.md`](docs/PLAN.md): fases del proyecto y qué se probó en cada una.
- [`CLAUDE.md`](CLAUDE.md): reglas del proyecto, convenciones y estado actual.

### Librerías del frontend

Guardadas en `web/vendor/`, sin CDN, para que la demo funcione sin internet:

| Librería | Versión |
|---|---|
| Bootstrap (CSS y JS bundle) | 5.3.8 |
| Bootstrap Icons | 1.13.1 |
| Chart.js | 4.5.1 |
| Inter (woff2: 400, 500, 600, 700) | 4.1 |
| JetBrains Mono (woff2: 400, 500, 600) | 2.304 |
