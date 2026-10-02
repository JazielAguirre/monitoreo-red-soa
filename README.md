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
                                        ├── alertas       → tabla alertas
                                        └── descubrimiento → sin tablas (ping y tabla ARP)
```

Cada servicio solo lee y escribe su propia tabla. Cuando uno necesita algo de otro,
se lo pide por HTTP: por ejemplo, Monitoreo le pide la IP a Dispositivos, le avisa
el resultado y le pide a Alertas que cree una alerta.

Desde la Fase 9, la pantalla Dispositivos también puede **buscar los equipos
conectados a la red local** (Servicio de Descubrimiento) y agregarlos con un clic.
Úsalo solo en redes propias o con permiso: ver [Buscar dispositivos en la
red](#8-buscar-dispositivos-en-la-red).

---

## Contenido

1. [Requisitos](#1-requisitos)
2. [Instalación en Linux](#2-instalación-en-linux)
3. [Instalación en Windows](#3-instalación-en-windows)
4. [Crear la base de datos](#4-crear-la-base-de-datos)
5. [Comprobar que funciona](#5-comprobar-que-funciona)
6. [Pruebas automáticas](#6-pruebas-automáticas)
7. [Preparar la demo](#7-preparar-la-demo)
8. [Buscar dispositivos en la red](#8-buscar-dispositivos-en-la-red)
9. [Problemas comunes](#9-problemas-comunes)
10. [Estructura del proyecto](#10-estructura-del-proyecto)

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

Sigue en [Crear la base de datos](#4-crear-la-base-de-datos).

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

## 4. Crear la base de datos

La base de uso diario, `monitoreo_red_soa`, **empieza vacía**: solo el usuario
`admin`, sin dispositivos, monitoreos ni alertas. Ahí solo aparece lo que tú agregues
(a mano o con [Buscar dispositivos en la red](#8-buscar-dispositivos-en-la-red)).

```bash
# Linux, desde la carpeta del proyecto
/opt/lampp/bin/php herramientas/crear-base.php
```

```powershell
# Windows (PowerShell)
C:/xampp/php/php.exe herramientas/crear-base.php
```

- Si la base ya existe, **no la toca**: dice cuántos dispositivos tiene y termina.
  Puedes correrlo cuantas veces quieras.
- `--reemplazar` la vacía para empezar de cero: primero guarda un **respaldo** en
  `respaldos/` y pide escribir `SI`. Para volver atrás:
  `php herramientas/restaurar-respaldo.php` (sin argumentos, lista los respaldos;
  con el nombre de uno, lo restaura, también respaldando antes y pidiendo `SI`).

Archivos de `database/`:

| Archivo | Qué tiene | Dónde se usa |
|---|---|---|
| `schema.sql` | Las 4 tablas. No crea ni borra la base: correrlo por error no borra nada | Todas las bases |
| `inicial.sql` | El usuario `admin` | Base de uso diario y base de pruebas |
| `seed.sql` | Datos de **ejemplo** (6 dispositivos, 56 monitoreos, 4 alertas) | Solo la base de pruebas, o a pedido con `preparar-demo.php --datos-de-ejemplo` |

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
/opt/lampp/bin/php pruebas/descubrimiento.php
/opt/lampp/bin/php pruebas/interfaz.php
```

| Suite | Qué prueba | Tiempo aprox. |
|---|---|---|
| `dispositivos.php` | Los 6 endpoints de Dispositivos, validaciones, 404/405, acceso con sesión y con clave | 2 s |
| `alertas.php` | Endpoints de Alertas, una sola alerta pendiente por dispositivo (también con peticiones simultáneas) | 3 s |
| `monitoreo.php` | Flujo de monitoreo por HTTP entre servicios, 502 cuando otro servicio no responde, regla SOA, registro de llamadas y preparación de la demo | 15 s |
| `auth.php` | Login, cookie, 401/403, mismo tiempo exista o no el usuario, sesión sin bloqueo, redirecciones y «Salir» en el navegador | 1 min |
| `descubrimiento.php` | Escáner de red: red y lotes, tabla ARP de Linux, Windows (inglés y español) y macOS, DNS inverso, pings en paralelo, 503/502, regla SOA. Usa una red simulada: **nunca envía nada a tu red** | 12 s |
| `interfaz.php` | Las pantallas en un Chrome sin ventana: estados de carga/vacío/error, celular (390 px), proyector (1024 y 1280 px), teclado, caché y «Buscar dispositivos en la red» | 4 min |

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
ping al router. Si algo falla, dice qué hacer. Si el router bloquea el ping pero
aparece en la tabla ARP (pasa con muchos hotspots), sale ⚠ «El router bloquea el
ping, pero está conectado (aparece en ARP)»: es un aviso, no un fallo.

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

## 8. Buscar dispositivos en la red

En **Dispositivos → Buscar dispositivos en la red**, el sistema encuentra los equipos
conectados a la red local de esta computadora, aunque no estén registrados, y muestra
para cada uno su IP, MAC, nombre (si lo tiene), si respondió al ping o solo apareció
en la tabla ARP, y si ya está registrado. **Agregar** abre el formulario de siempre
con la IP y el nombre ya escritos.

> **Uso responsable.** Escanear una red es enviar paquetes a todos sus equipos.
> Úsalo **solo en redes propias o con permiso** de quien las administra. En la demo se
> usa en el **hotspot de un teléfono del equipo**, nunca en la red de la escuela. Si
> la computadora está conectada a una red ajena, no pulses el botón.

Cómo funciona (Servicio de Descubrimiento, `servicios/descubrimiento/`):

- **Solo la red de esta computadora:** la /24 de su IP (por ejemplo `192.168.137.0/24`,
  254 direcciones), calculada en el servidor. El rango nunca viene del navegador, así
  que nadie puede usar el servicio para escanear otras redes, y solo se aceptan redes
  privadas (`10.x`, `172.16–31.x`, `192.168.x`).
- **Ping en paralelo, por lotes:** la interfaz pide 4 lotes de 64 direcciones, uno tras
  otro, para mostrar el progreso; cada lote hace sus pings a la vez y tarda 1–2 s.
  La red completa, unos 5 s.
- **Tabla ARP:** después del ping se lee la tabla ARP del sistema (`ip neigh` en Linux,
  `arp -a` en Windows, `arp -an` en macOS). Así aparecen también los equipos que
  bloquean el ping, como muchas computadoras con Windows o el propio hotspot
  («Solo en ARP»), con su dirección MAC.
- **Nombre:** por DNS inverso, con 0.8 s como máximo; si no hay, se muestra «—».
- **Registrados:** el servicio le pregunta a Dispositivos (`GET /dispositivos`) por
  HTTP; para registrar, la interfaz usa el `POST /dispositivos` de siempre. Ningún
  otro servicio cambió para agregar el escáner.

Límites conocidos:

- Si la red es más grande que una /24 (por ejemplo una /16 de una escuela), solo se
  busca en la /24 de esta computadora.
- La tabla ARP guarda los equipos unos minutos: uno que se desconectó hace poco puede
  seguir apareciendo como «Solo en ARP».
- En Windows y macOS, la lectura de la tabla ARP solo se probó con salidas de ejemplo.

Probarlo desde la terminal (solo en una red propia: el lote 1 hace ping a 64 direcciones):

```bash
curl -s -c cookies.txt -H 'Content-Type: application/json' \
  -d '{"usuario":"admin","contrasena":"admin123"}' \
  http://localhost/monitoreo-red-soa/servicios/auth/login
curl -s -b cookies.txt http://localhost/monitoreo-red-soa/servicios/descubrimiento/red
curl -s -b cookies.txt -X POST http://localhost/monitoreo-red-soa/servicios/descubrimiento/red/lotes/1
```

---

## 9. Problemas comunes

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
| «Buscar dispositivos en la red» dice **"No se detectó una red local"** | La computadora no tiene red (sin WiFi ni cable) | Conéctala a la red (en la demo, al hotspot) y vuelve a buscar |
| Dice **"Solo se pueden escanear redes privadas"** | La IP de la computadora no es de una red local privada | Es a propósito: el escáner nunca recorre redes públicas. Conéctala a una red local |
| **Un equipo no aparece** en la búsqueda | No respondió al ping ni a ARP en ese momento (teléfono con la pantalla apagada, otra red WiFi) | Despiértalo, confirma que esté en la misma red y pulsa «Buscar de nuevo» |

---

## 10. Estructura del proyecto

```
database/       schema.sql (las tablas), inicial.sql (usuario admin), seed.sql (datos de ejemplo)
docs/           API.md (contrato de los endpoints), PLAN.md (fases), DEMO.md (guion),
                WINDOWS.md (verificación en Windows), diseno/ (referencia visual)
herramientas/   crear-base.php, restaurar-respaldo.php, preparar-demo.php, ver-llamadas.php y sus
                funciones (solo línea de comandos)
pruebas/        las 6 suites y sus ayudas (solo línea de comandos)
servicios/      comun/ (infraestructura) y un servicio por carpeta: auth, dispositivos, monitoreo,
                alertas y descubrimiento
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
