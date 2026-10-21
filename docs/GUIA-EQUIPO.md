# Guía del equipo

Esta guía es para quien nunca ha visto el proyecto. Sigue los pasos en orden y copia
los comandos tal cual. Primero va **Windows** (lo que usa la mayoría del equipo) y
después **Linux**.

Si algo no sale como dice aquí, ve a [Problemas comunes](#9-problemas-comunes). Si no
está ahí, pregunta al equipo y manda el texto completo del error (copiado, no una foto).

## Contenido

0. [Cómo usar esta guía](#0-cómo-usar-esta-guía)
1. [Qué es el proyecto](#1-qué-es-el-proyecto)
2. [Requisitos](#2-requisitos)
3. [Instalación en Windows, desde cero](#3-instalación-en-windows-desde-cero)
4. [Instalación en Linux](#4-instalación-en-linux)
5. [Recorrido de prueba](#5-recorrido-de-prueba)
6. [Entrar desde el celular en otra red con Tailscale](#6-entrar-desde-el-celular-en-otra-red-con-tailscale)
7. [Encender todo en un clic](#7-encender-todo-en-un-clic)
8. [Pruebas automáticas](#8-pruebas-automáticas)
9. [Problemas comunes](#9-problemas-comunes)
10. [Mapa del repositorio](#10-mapa-del-repositorio)
11. [Trabajar en equipo con git](#11-trabajar-en-equipo-con-git)
12. [Qué se comprobó y qué falta](#12-qué-se-comprobó-y-qué-falta)

---

## 0. Cómo usar esta guía

- **PowerShell** es la terminal de Windows. Para abrirla **en la carpeta del proyecto**:
  abre `C:\xampp\htdocs\monitoreo-red-soa` en el Explorador de archivos, haz clic en la
  barra de dirección, escribe `powershell` y pulsa Enter. También puedes abrir
  PowerShell desde el menú Inicio y escribir:

  ```powershell
  cd C:\xampp\htdocs\monitoreo-red-soa
  ```

- Cada bloque de código es **un comando listo para copiar**: cópialo, pégalo en
  PowerShell (clic derecho o Ctrl+V) y pulsa Enter. Las líneas que empiezan con `#` son
  comentarios: no hace falta copiarlas.
- **"Como administrador"** significa: menú Inicio → escribe `PowerShell` → clic derecho
  en *Windows PowerShell* → **Ejecutar como administrador**. Solo se pide para cambiar
  el Firewall o detener servicios de Windows. Nada del proyecto lo necesita.
- Las herramientas del proyecto marcan cada comprobación así: **✓** bien, **⚠** aviso
  (no impide trabajar, pero lee qué dice) y **✗** algo falló. Cada ✗ va con una línea
  **«Qué hacer»**: hazle caso.
- En PowerShell se escribe `curl.exe`, no `curl` (en PowerShell, `curl` es otro comando).

---

## 1. Qué es el proyecto

El **Sistema de Monitoreo de Red SOA** es una aplicación web para vigilar los equipos de
una red: registras dispositivos (computadoras, servidores, impresoras, routers), el
sistema les hace **ping** para ver si responden, guarda el **historial** de cada
comprobación y crea una **alerta** cuando uno deja de responder.

Es un proyecto escolar para mostrar una **Arquitectura Orientada a Servicios (SOA)**: la
aplicación está partida en servicios pequeños, cada uno con una sola tarea y su propia
tabla, que se hablan entre sí por HTTP.

### 1.1 Qué hace cada pantalla

| Pantalla | Archivo | Para qué sirve |
|---|---|---|
| **Inicio de sesión** | `web/login.html` | Entrar con usuario y contraseña (`admin` / `admin123`). La sesión dura 8 horas |
| **Dashboard** | `web/index.html` | Resumen: cuántos equipos hay activos e inactivos, la tarjeta de cada uno, la gráfica de los últimos tiempos de respuesta, las últimas alertas y el botón **Monitorear todos** |
| **Dispositivos** | `web/dispositivos.html` | La lista de equipos: **Agregar dispositivo**, **Editar**, **Eliminar** (siempre pide confirmación) y **Monitorear** uno. Aquí está **Buscar dispositivos en la red**, que encuentra los equipos conectados a tu red y los agrega con un clic |
| **Monitoreos** | `web/monitoreos.html` | El historial de comprobaciones, con filtros por dispositivo, estado y fechas, su gráfica y la disponibilidad (% de veces que respondió) |
| **Alertas** | `web/alertas.html` | Las alertas **Pendientes** y **Atendidas**. **Marcar como atendida** la pasa a Atendidas. El número rojo del menú cuenta las pendientes |
| **Componentes** | `web/componentes.html` | La página de muestra del sistema de diseño (colores, botones, tablas…). No pide sesión ni datos |

### 1.2 La arquitectura SOA

```
   Navegador (computadora o celular)
   web/: HTML, CSS y JavaScript. Todo lo pide por web/js/api.js
                │
                │  HTTP + JSON, con la cookie de sesión
                ▼
   ┌───────────────────────── Apache + PHP (XAMPP) ───────────────────────────┐
   │                                                                            │
   │   Autenticación    Dispositivos ◄──HTTP── Monitoreo ──HTTP──► Alertas      │
   │                          ▲                                                 │
   │                          └────────HTTP──────── Descubrimiento              │
   │                    (entre servicios: cabecera X-Servicio-Clave)            │
   └──────┬──────────────────┬──────────────────┬──────────────┬──────────────┘
          ▼                  ▼                  ▼              ▼
       usuarios         dispositivos       monitoreos       alertas
   └──────────────────── MySQL (MariaDB): base monitoreo_red_soa ────────────────┘
          Descubrimiento no tiene tabla: usa el ping y la tabla ARP del sistema
```

| Servicio | Carpeta | Qué hace | Su tabla |
|---|---|---|---|
| Autenticación | `servicios/auth/` | Inicia y cierra la sesión del navegador | `usuarios` |
| Dispositivos | `servicios/dispositivos/` | Registrar, editar, borrar y listar equipos, y guardar su último estado | `dispositivos` |
| Monitoreo | `servicios/monitoreo/` | Hacer el ping, guardar el historial y avisar a los otros servicios | `monitoreos` |
| Alertas | `servicios/alertas/` | Crear alertas (una sola pendiente por equipo) y marcarlas como atendidas | `alertas` |
| Descubrimiento | `servicios/descubrimiento/` | Buscar los equipos de la red local (ping en paralelo, tabla ARP y nombres) | ninguna |

**La regla de oro:** cada servicio solo lee y escribe **su propia tabla**. Si necesita
algo de otro, se lo pide por HTTP. Por ejemplo, al pulsar **Monitorear** en un equipo:

```
Navegador → Monitoreo: POST /monitoreo/7
  Monitoreo → Dispositivos: GET /dispositivos/7             ← la IP y el nombre
  (Monitoreo hace el ping y guarda el resultado en su tabla monitoreos)
  Monitoreo → Dispositivos: PATCH /dispositivos/7/estado    ← el nuevo estado
  Monitoreo → Alertas: POST /alertas                        ← solo si no respondió
```

Puedes ver esas llamadas en vivo con `herramientas\ver-llamadas.php` (sección 7). El
contrato completo de cada servicio está en [`docs/API.md`](API.md).

---

## 2. Requisitos

| Programa | Dónde se descarga | Versión probada | Para qué |
|---|---|---|---|
| **XAMPP** con PHP 8.2 | <https://www.apachefriends.org/download.html> (la de **PHP 8.2**; versiones anteriores en <https://sourceforge.net/projects/xampp/files/XAMPP%20Windows/8.2.12/>) | 8.2.12 (PHP 8.2.12, MariaDB 10.4.32, Apache 2.4.58) | Apache sirve la aplicación y los servicios; MySQL guarda los datos |
| **Git para Windows** | <https://git-scm.com/downloads/win> | 2.55 | Clonar el repositorio y subir cambios |
| o **GitHub Desktop** | <https://desktop.github.com/download/> | — | Lo mismo, con ventanas en lugar de comandos |
| **Visual Studio Code** | <https://code.visualstudio.com/download> | 1.142 | Editar el código |
| Extensión **Live Server** | En VS Code: Extensiones (Ctrl+Shift+X) → busca *Live Server* (de Ritwick Dey) → Instalar. También en <https://marketplace.visualstudio.com/items?itemName=ritwickdey.LiveServer> | 5.7.10 (no se probó: ver sección 12) | Ver `componentes.html` mientras editas estilos (opcional) |
| **Google Chrome** (o Microsoft Edge) | <https://www.google.com/chrome/> | Chrome 156, Edge 155 | Usar la aplicación; las pruebas de interfaz abren su propio Chrome sin ventana |
| **Tailscale** | <https://tailscale.com/download> (Windows, Android e iPhone) | 1.104.2 | Entrar desde el celular aunque esté en otra red (opcional) |

- Windows 10 u 11. Todo se probó en **Windows 11** en español.
- No hace falta Composer, npm ni internet para usar la aplicación: las librerías de la
  interfaz ya vienen en `web/vendor/`.
- La carpeta del proyecto **debe llamarse `monitoreo-red-soa`** y estar en
  `C:\xampp\htdocs\`. Las direcciones de los servicios usan ese nombre.

---

## 3. Instalación en Windows, desde cero

### 3.1 Instalar XAMPP

1. Descarga el instalador de XAMPP con **PHP 8.2** para Windows (sección 2) y ábrelo.
2. Si Windows avisa del Control de cuentas de usuario (UAC), pulsa **Aceptar**: solo
   pide que no lo instales en `C:\Program Files`.
3. En **Select Components** deja marcados al menos **Apache**, **MySQL**, **PHP** y
   **phpMyAdmin**. Los demás no hacen falta.
4. En la carpeta de instalación deja **`C:\xampp`** (la de siempre). El proyecto y sus
   herramientas buscan `C:\xampp\php\php.exe` y `C:\xampp\mysql\bin\mysql.exe` ahí.
5. Termina el instalador. **No** instales Apache ni MySQL como *servicio* de Windows
   (las casillas *Service* del panel): el proyecto los enciende como programas normales.

Comprueba que quedó bien. Debe decir `PHP 8.2.…`:

```powershell
C:\xampp\php\php.exe -v
```

### 3.2 Clonar el repositorio

Clonar = descargar el proyecto con su historial, para poder actualizarlo con `git pull`.
**Elige una de las dos formas.** No descargues el ZIP de GitHub: así no puedes actualizar
ni subir cambios.

**A) Con Git, en PowerShell:**

```powershell
git clone https://github.com/JazielAguirre/monitoreo-red-soa.git C:\xampp\htdocs\monitoreo-red-soa
```

Si es la primera vez que usas git en esa computadora, dile quién eres (con tu nombre y
el correo de tu cuenta de GitHub). Sin esto, git no deja hacer commits:

```powershell
git config --global user.name "Tu Nombre"
```

```powershell
git config --global user.email "tucorreo@ejemplo.com"
```

**B) Con GitHub Desktop:**

1. Abre GitHub Desktop e inicia sesión con tu cuenta de GitHub.
2. **File → Clone repository… → URL**.
3. En *Repository URL* pega `https://github.com/JazielAguirre/monitoreo-red-soa`.
4. En *Local path* escribe exactamente `C:\xampp\htdocs\monitoreo-red-soa`.
5. Pulsa **Clone**.

### 3.3 Encender Apache y MySQL

La forma más fácil: **doble clic en `C:\xampp\htdocs\monitoreo-red-soa\herramientas\iniciar-servicios.bat`**.
Enciende los dos (solo si no están encendidos), comprueba todo y al final muestra un
resumen. Lo explica la [sección 7](#7-encender-todo-en-un-clic). Si aún no tienes
Tailscale, marcará ✗ en Tailscale: para usar la aplicación en tu computadora no importa.

También puedes usar el **panel de XAMPP** (`C:\xampp\xampp-control.exe`): botón **Start**
de *Apache* y de *MySQL*. Los dos nombres deben quedar en verde.

### 3.4 Permitir Apache en el Firewall de Windows

Esto solo hace falta para entrar **desde el celular**. En la computadora la aplicación
funciona sin este paso.

La primera vez que se enciende Apache, Windows muestra la ventana *«El Firewall de
Windows Defender bloqueó algunas características de esta aplicación»*:

1. Marca **Redes privadas** **y también** **Redes públicas**. Un hotspot de teléfono
   nuevo suele quedar como red pública, y sin esa casilla el celular no carga la página.
2. Pulsa **Permitir** (o *Permitir acceso*; pide la contraseña de administrador).

Si la ventana ya no aparece porque se respondió antes, `iniciar-servicios.bat` revisa el
Firewall y, si falta un tipo de red, muestra el comando exacto. Es este, para **las dos**
redes, en PowerShell **como administrador**:

```powershell
New-NetFirewallRule -DisplayName "Apache HTTP Server (XAMPP, redes privadas y públicas)" -Direction Inbound -Action Allow -Program "C:\xampp\apache\bin\httpd.exe" -Protocol TCP -LocalPort 80 -Profile Private,Public
```

Solo deja entrar a Apache por el puerto 80. phpMyAdmin y el panel de XAMPP siguen
abiertos solo para la propia computadora.

### 3.5 Crear la base de datos

La base de uso diario, `monitoreo_red_soa`, empieza **vacía**: solo el usuario `admin`,
sin dispositivos. Con MySQL encendido, en PowerShell desde la carpeta del proyecto:

```powershell
C:\xampp\php\php.exe herramientas\crear-base.php
```

Debe decir `✓ Base monitoreo_red_soa creada`. Si la base ya existía, dice cuántos
dispositivos tiene y **no la toca**: se puede correr las veces que quieras.

### 3.6 Abrir la aplicación e iniciar sesión

1. En el navegador abre <http://localhost/monitoreo-red-soa/web/>.
2. Debe aparecer **Iniciar sesión**. Entra con usuario **`admin`** y contraseña
   **`admin123`**.
3. Llegas al **Dashboard**, vacío: todavía no hay dispositivos. Sigue con el
   [recorrido de prueba](#5-recorrido-de-prueba).

Para comprobar los servicios desde PowerShell (sin sesión, la respuesta correcta es un
401 en JSON):

```powershell
curl.exe -s http://localhost/monitoreo-red-soa/servicios/auth/sesion
```

Debe imprimir `{"ok":false,"error":"No hay una sesión activa"}`.

### 3.7 Comprobar todo

```powershell
C:\xampp\php\php.exe herramientas\preparar-demo.php
```

**No cambia tu base.** Revisa Apache, los servicios, tu base, el router y esta
computadora, y te da la dirección para el celular en la misma WiFi. Con la base recién
creada es normal ver ⚠ («Tu base no tiene dispositivos todavía»). Lo importante es que
termine sin ✗.

---

## 4. Instalación en Linux

El proyecto se desarrolló en Linux con XAMPP en `/opt/lampp`. Los pasos completos están
en el [README, «Instalación en Linux»](../README.md#2-instalación-en-linux). En resumen:

1. Instala XAMPP con PHP 8.2 en `/opt/lampp`.
2. Clona el repositorio en `~/Proyectos/monitoreo-red-soa` y **enlázalo** desde htdocs:
   `ln -s ~/Proyectos/monitoreo-red-soa /opt/lampp/htdocs/monitoreo-red-soa`.
3. **Permisos:** Apache corre como el usuario `daemon` y necesita permiso de paso (`x`)
   en cada carpeta del camino. Revísalo con `namei -l /opt/lampp/htdocs/monitoreo-red-soa/web/index.html`
   y, si hace falta, `setfacl -m u:daemon:x ~`. Sin esto, Apache responde **403**.
4. **MySQL con pam_tmpdir** (Ubuntu, Debian): agrega `tmpdir = /tmp` debajo de `[mysqld]`
   en `/opt/lampp/etc/my.cnf`. Sin esto, MySQL no arranca.
5. Enciende todo con `sudo bash herramientas/iniciar-servicios.sh` (sección 7).
6. Para el celular, si usas **ufw**: `sudo ufw allow from 192.168.0.0/24 to any port 80 proto tcp`
   (con tu red) y, para Tailscale, `sudo ufw allow in on tailscale0`.
7. Crea la base: `/opt/lampp/bin/php herramientas/crear-base.php`.

En Linux, los comandos de esta guía cambian así:

| Windows | Linux |
|---|---|
| `C:\xampp\php\php.exe` | `/opt/lampp/bin/php` |
| `C:\xampp\mysql\bin\mysql.exe` | `/opt/lampp/bin/mysql` |
| `curl.exe` | `curl` |
| `herramientas\iniciar-servicios.bat` | `sudo bash herramientas/iniciar-servicios.sh` |

---

## 5. Recorrido de prueba

Un recorrido corto para ver que todo funciona, en tu propia base. Usa solo direcciones que
no salen a la red: `127.0.0.1` (tu propia computadora, siempre responde) y `192.0.2.20`
(una dirección reservada para documentación, nunca responde).

| Paso | Qué haces | Qué debes ver |
|---|---|---|
| 1. Agregar a mano | **Dispositivos → Agregar dispositivo**. Nombre `Mi PC`, IP `127.0.0.1`, tipo *Computadora*, ubicación `Casa`. **Agregar dispositivo** | La fila *Mi PC* con el estado **Sin monitorear** (punto gris). Si dejas la ubicación vacía, aparece «La ubicación es obligatoria» debajo del campo: el mensaje viene del servicio, no del navegador |
| 2. Otro que no responde | Agrega `Impresora de prueba`, IP `192.0.2.20`, tipo *Impresora*, ubicación `Casa` | La segunda fila, también **Sin monitorear** |
| 3. Buscar con el escáner | **Dispositivos → Buscar dispositivos en la red**. **Solo en una red propia** (tu casa o el hotspot del equipo), nunca en la de la escuela | El panel *Equipos en la red* avanza por lotes («Lote 1 de 4» … «Lote 4 de 4», unos 7 s). Aparece tu router («Responde al ping» o «Solo en ARP»), esta computadora («Esta computadora») y los demás equipos, con **Agregar** en los que no están registrados. Los teléfonos suelen salir sin nombre («—») |
| 4. Monitorear | En la fila *Mi PC*, **Monitorear** | El punto pulsa mientras hace el ping; queda **Activo** con un tiempo como «< 1 ms» y la notificación «Mi PC responde» |
| 5. Ver la alerta | En *Impresora de prueba*, **Monitorear** | Queda **Inactivo**, la notificación «Impresora de prueba no respondió · Se generó una alerta pendiente» y el contador rojo de **Alertas** en el menú sube a 1. Si la monitoreas otra vez: «Ya tenía una alerta pendiente» y el contador no cambia |
| 6. Atenderla | **Alertas → Pendientes → Marcar como atendida** | La alerta pasa a **Atendidas** y el contador del menú baja a 0 |
| 7. Historial | **Monitoreos**, elige *Mi PC* en el filtro de dispositivo | La tabla con sus comprobaciones, la gráfica de tiempos y la disponibilidad. Con *Impresora de prueba*, los puntos rojos son las veces que no respondió |
| 8. Eliminar | **Dispositivos → Eliminar** en *Impresora de prueba* | El modal **¿Eliminar dispositivo?**; al confirmar desaparece, y con él su historial y sus alertas (la base los borra en cascada) |

Al terminar puedes dejar *Mi PC* o eliminarlo. El **Dashboard** ya muestra sus
indicadores y la gráfica. **Monitorear todos** monitorea todos los equipos uno tras otro.

---

## 6. Entrar desde el celular en otra red con Tailscale

En la **misma WiFi** no hace falta Tailscale: abre en el celular
`http://<IP de la computadora>/monitoreo-red-soa/web/` (la imprime `preparar-demo.php`).
Tailscale sirve para entrar **desde otra red**, por ejemplo con datos móviles. Crea una
red privada entre tus equipos: solo entran los equipos de esa red, y la aplicación no
queda abierta a internet.

### 6.1 Instalar Tailscale e iniciar sesión

1. **En la computadora:** descarga Tailscale para Windows (<https://tailscale.com/download/windows>),
   instálalo (pide administrador) y pulsa **Log in** en su icono, junto al reloj. Entra
   con tu cuenta (Google, Microsoft o GitHub).
2. **En el celular:** instala *Tailscale* desde la Play Store o la App Store, ábrelo,
   entra **con la misma cuenta** y activa la conexión (el interruptor).
3. Comprueba en PowerShell:

   ```powershell
   tailscale status
   ```

   La primera línea es esta computadora y su IP `100.x.y.z`. Tu celular debe aparecer
   en la lista. `iniciar-servicios.bat` también la muestra.

### 6.2 Compartir la computadora con un compañero

Si un compañero quiere entrar a **tu** computadora con **su** cuenta de Tailscale:

1. Abre <https://login.tailscale.com/admin/machines> con tu cuenta.
2. En la fila de tu computadora, abre el menú **…** → **Share…**.
3. Copia el enlace de invitación y envíaselo (o invítalo por correo).
4. Tu compañero lo abre, acepta con su cuenta y tu computadora aparece en su lista de
   Tailscale con la **misma IP 100.x**.

Solo compartes esa computadora, no el resto de tus equipos. Puedes dejar de compartirla
desde el mismo menú.

### 6.3 Las direcciones

Cambia `100.x.y.z` por la IP de Tailscale de la computadora (la de `tailscale status`):

| Qué | Dirección en el celular |
|---|---|
| La aplicación, con Apache | `http://100.x.y.z/monitoreo-red-soa/web/` |
| `componentes.html` con Live Server | `http://100.x.y.z:5500/componentes.html` |

**Escribe `http://` completo.** Si Chrome lo cambia a `https://`, no carga (ver
[Problemas comunes](#9-problemas-comunes)).

**Live Server**, para ver `componentes.html` mientras editas estilos:

1. En VS Code, **Archivo → Abrir carpeta…** → `C:\xampp\htdocs\monitoreo-red-soa\web`.
2. Por defecto Live Server **solo acepta conexiones de la propia computadora**
   (`127.0.0.1`). Para que entre el celular: **Archivo → Preferencias → Configuración**
   (Ctrl+,), busca `liveServer.settings.host` y cambia `127.0.0.1` por `0.0.0.0`.
3. Abre `componentes.html` y pulsa **Go Live** (abajo a la derecha). La primera vez,
   Windows pregunta si deja entrar a *Visual Studio Code*: marca redes privadas y
   públicas.
4. En la computadora: <http://localhost:5500/componentes.html>.

Con Live Server conviene abrir solo `componentes.html`: las demás pantallas necesitan los
servicios, y esos los sirve Apache, no Live Server.

### 6.4 La prueba con datos móviles

1. En el celular, **apaga el WiFi** (solo datos móviles) y deja Tailscale conectado.
2. Abre `http://100.x.y.z/monitoreo-red-soa/web/`.
3. Debe aparecer **Iniciar sesión**. Entra con `admin` / `admin123`.

Si no carga: revisa que el interruptor de Tailscale del celular esté activado, que la
computadora esté encendida con Apache, y la fila «el celular no carga» de
[Problemas comunes](#9-problemas-comunes).

---

## 7. Encender todo en un clic

Después de reiniciar la computadora, un solo paso enciende y revisa todo.

**Windows:** doble clic en `herramientas\iniciar-servicios.bat`. No necesita
administrador. Hace esto, en orden:

1. **Apache y MySQL de XAMPP:** los enciende solo si no están encendidos y confirma que de
   verdad contestan. Si otro programa ocupa el puerto 80, 443 o 3306, dice cuál (con su
   PID) y cómo detenerlo.
2. **Tailscale:** si está instalado y conectado, muestra la IP `100.x.y.z` y comprueba que
   Apache responde en ella. Si falta iniciar sesión o conectar, dice cómo.
3. **Firewall de Windows:** revisa si Apache puede recibir conexiones en las redes
   privadas y públicas. Si no, muestra el comando exacto para PowerShell como
   administrador, **pero no lo ejecuta**.
4. **Comprobación previa** de `preparar-demo.php`. **No cambia tu base.**
5. **Resumen:** el estado de cada cosa y las direcciones para entrar (computadora, celular
   en la misma WiFi, celular por Tailscale y Live Server). Si algo falló, lo repite con su
   «Qué hacer».

Al final espera una tecla para que puedas leer el resumen antes de cerrar la ventana.
Nunca se queda esperando otra cosa: cada paso tiene un tiempo máximo. Ejemplo de un
resumen con todo bien:

```
Resumen
  Apache ............... encendido
  MySQL (XAMPP) ........ encendido
  Tailscale ............ conectado (100.101.102.103)
  Firewall ............. permite Apache en redes privadas y públicas
  Comprobación previa .. sin ✗

Para entrar
  En la computadora ................ http://localhost/monitoreo-red-soa/web/
  En el celular, misma WiFi ........ http://192.168.1.20/monitoreo-red-soa/web/
  En el celular, con Tailscale ..... http://100.101.102.103/monitoreo-red-soa/web/
  En el celular, con Live Server ... http://100.101.102.103:5500/componentes.html

Todo encendido.
```

Desde PowerShell también funciona (y no espera la tecla del final):

```powershell
herramientas\iniciar-servicios.bat
```

**Linux:**

```bash
sudo bash herramientas/iniciar-servicios.sh
```

Hace lo mismo con ufw en lugar del Firewall de Windows, y si Tailscale no está conectado
ejecuta `tailscale up` (README, sección 9).

**Durante la demo**, en otra ventana de PowerShell, deja corriendo el registro de llamadas
entre servicios (Ctrl+C para salir):

```powershell
C:\xampp\php\php.exe herramientas\ver-llamadas.php
```

---

## 8. Pruebas automáticas

Hay 6 suites de pruebas. **Nunca tocan tu base:** usan su propia base,
`monitoreo_red_soa_pruebas`, y al terminar comprueban que la tuya quedó idéntica.
Necesitan Apache y MySQL encendidos y tu base creada (paso 3.5). Desde la carpeta del
proyecto, una por una:

```powershell
C:\xampp\php\php.exe pruebas\dispositivos.php
```

```powershell
C:\xampp\php\php.exe pruebas\alertas.php
```

```powershell
C:\xampp\php\php.exe pruebas\monitoreo.php
```

```powershell
C:\xampp\php\php.exe pruebas\auth.php
```

```powershell
C:\xampp\php\php.exe pruebas\descubrimiento.php
```

```powershell
C:\xampp\php\php.exe pruebas\interfaz.php
```

O las 6 seguidas (unos 7 minutos), mostrando solo el resultado de cada una y lo que falle.
La primera parte hace que PowerShell lea bien los ✓ y ✗ que escriben las pruebas:

```powershell
[Console]::OutputEncoding = [Text.Encoding]::UTF8; foreach ($s in 'dispositivos','alertas','monitoreo','auth','descubrimiento','interfaz') { "== $s"; C:\xampp\php\php.exe pruebas\$s.php | Select-String '^Resultado|^\s*✗|! Aviso' }
```

| Suite | Qué prueba | Tiempo en Windows |
|---|---|---|
| `dispositivos.php` | Los endpoints de Dispositivos, la base de pruebas, `crear-base.php` y `restaurar-respaldo.php` | 8 s |
| `alertas.php` | Alertas: una sola pendiente por equipo, también con peticiones simultáneas | 3 s |
| `monitoreo.php` | El monitoreo entre servicios, los 502, la regla SOA, el registro de llamadas, `preparar-demo.php`, `monitorear-rondas.php`, la lectura del ping (con salidas reales de Windows) e `iniciar-servicios.bat` | 1 min |
| `auth.php` | Login, cookie, 401/403 y «Salir» en el navegador | 50 s |
| `descubrimiento.php` | El escáner con una red simulada: **nunca envía nada a tu red** | 26 s |
| `interfaz.php` | Las pantallas en un Chrome sin ventana: celular, proyector, teclado y el ensayo de la demo | 4 min |

**Cómo leer el resultado:**

- `✓` es una comprobación correcta y `✗` una que falló (debajo dice qué se esperaba y qué
  salió).
- `!` es un **aviso**: algo que no se pudo probar en esta computadora, con el motivo. En
  Windows, `descubrimiento.php` da uno: no hay un DNS local que conozca `127.0.0.1`.
- Cada suite termina con **`✓ Tu base (monitoreo_red_soa) quedó idéntica`** y
  **`Resultado: N de N comprobaciones correctas.`** Si los dos números son iguales, pasó.
  Si algo falla, el código de salida es distinto de 0.
- Mientras corre una suite, la aplicación del navegador muestra los datos de ejemplo de la
  base de pruebas. Al terminar vuelve sola a la tuya.
- Si dice `✗ Tu base (monitoreo_red_soa) no existe todavía`, crea la base (paso 3.5) y
  vuelve a correrla.
- Las pruebas del navegador buscan Chrome o Edge en su carpeta de siempre. Si lo tienes en
  otra, indícalo antes de correrlas: `$env:CHROME_BIN = 'C:\ruta\a\chrome.exe'`.

---

## 9. Problemas comunes

| Síntoma | Causa | Solución |
|---|---|---|
| Apache o MySQL no encienden y `iniciar-servicios.bat` dice **«el puerto 80 (o 443, o 3306) lo ocupa…»** | Otro programa usa ese puerto: IIS (*Servicio de publicación World Wide Web*), otro MySQL instalado aparte (servicio *MySQL80*), Docker, VMware o Skype | Sigue el «Qué hacer» del script: dice qué programa es, su PID y el comando para detenerlo (los servicios se detienen en PowerShell como administrador, por ejemplo `Stop-Service -Name W3SVC`). Para ver quién usa un puerto a mano: `netstat -ano \| findstr :80` y luego `tasklist /FI "PID eq NÚMERO"` |
| **MySQL de XAMPP no arranca en Linux** | `pam_tmpdir`: al encenderlo con sudo, MariaDB hereda una carpeta temporal donde no puede escribir | Agrega `tmpdir = /tmp` debajo de `[mysqld]` en `/opt/lampp/etc/my.cnf` (README, paso 4) |
| El panel de XAMPP dice **«MySQL shutdown unexpectedly»** (Windows) | Casi siempre, otro programa en el puerto 3306 o una computadora que se apagó con MySQL encendido | 1) Corre `iniciar-servicios.bat`: si es el puerto, lo dice. 2) Lee el final de `C:\xampp\mysql\data\mysql_error.log`. 3) Antes de borrar o mover nada en `C:\xampp\mysql\data`, **copia esa carpeta completa** a otro lugar y pide ayuda al equipo |
| **403 Forbidden** al abrir la página (Linux) | Apache (`daemon`) no puede entrar a tu carpeta personal | `namei -l /opt/lampp/htdocs/monitoreo-red-soa/web/index.html` y `setfacl -m u:daemon:x ~` (README, paso 3) |
| **404** en `/servicios/...` o en toda la aplicación | La carpeta no se llama `monitoreo-red-soa` o no está en `C:\xampp\htdocs\` | Renómbrala o vuelve a clonar en `C:\xampp\htdocs\monitoreo-red-soa` (paso 3.2) |
| Las pantallas dicen **«No se pudo conectar con el servidor»** | Apache está apagado | `iniciar-servicios.bat` o **Start** de Apache en el panel de XAMPP |
| Las pantallas dicen **«Error interno del servidor»** | MySQL está apagado, o la base no existe | Enciende MySQL; si la base no existe: `C:\xampp\php\php.exe herramientas\crear-base.php` |
| **El celular no carga** la página | El Firewall de Windows no deja entrar a Apache en ese tipo de red (un hotspot nuevo suele ser **red pública**), el celular está en otra red, o Tailscale está apagado en el celular | Corre `iniciar-servicios.bat`: si falta permiso en el Firewall, muestra el comando para PowerShell como administrador (paso 3.4). Revisa que el celular esté en la misma WiFi o con Tailscale conectado, y usa la dirección que da el resumen |
| El navegador muestra **una versión vieja** de la interfaz | Caché del navegador | Recarga forzada **Ctrl+Shift+R**; en el celular, cierra la pestaña y ábrela de nuevo. Las páginas se revalidan solas desde la Fase 8, así que es raro |
| **Chrome cambia `http://` por `https://`** y lo muestra **tachado** (o «La conexión no es privada») | La opción *Usar siempre conexiones seguras* de Chrome. La aplicación solo funciona con `http://` | Escribe la dirección con **`http://` completo**. Si Chrome insiste: **Configuración → Privacidad y seguridad → Seguridad → desactiva «Usar siempre conexiones seguras»** (en el celular: Chrome → Configuración → Privacidad y seguridad). En la página de aviso también puedes elegir *Continuar al sitio* |
| Al abrir `iniciar-servicios.ps1`: **«no se puede cargar el archivo… la ejecución de scripts está deshabilitada en este sistema»** | La política de ejecución de PowerShell bloquea los scripts (en Windows viene así) | Usa **`iniciar-servicios.bat`**: ejecuta el `.ps1` con `-ExecutionPolicy Bypass` solo para ese script, sin cambiar Windows. O, en PowerShell: `powershell -ExecutionPolicy Bypass -File herramientas\iniciar-servicios.ps1` |
| `ver-llamadas.php` no muestra nada | No se ha monitoreado nada desde que lo abriste (solo muestra lo nuevo) | Monitorea un dispositivo en la interfaz. Para ver también lo anterior: `C:\xampp\php\php.exe herramientas\ver-llamadas.php --todo`. El registro está en `C:\xampp\php\logs\llamadas-servicios.log` (la carpeta se crea sola) |
| Las pruebas dicen **«Tu base (monitoreo_red_soa) no existe todavía»** | No se creó la base de uso diario | `C:\xampp\php\php.exe herramientas\crear-base.php` |
| `mysql_error.log` dice **«Error on close of aria_log_control (Errcode: 9)»** al apagar MySQL | Un aviso de MariaDB en Windows al cerrarse | Es normal en el XAMPP de Windows: también aparece al apagar MySQL desde el panel. No hace falta hacer nada |
| «Buscar dispositivos en la red» dice **«No se detectó una red local»** o **«Solo se pueden escanear redes privadas»** | La computadora no está conectada a una red privada (casa, hotspot) | Conéctala a tu WiFi o al hotspot del equipo y pulsa **Buscar de nuevo** |
| La sesión se cierra sola | Pasaron 8 horas desde el login | Vuelve a entrar: te regresa a la pantalla donde estabas |

---

## 10. Mapa del repositorio

```
monitoreo-red-soa/
├── web/            La interfaz: login.html, index.html (Dashboard), dispositivos.html,
│                   monitoreos.html, alertas.html, componentes.html
│   ├── css/        tokens.css (el ÚNICO archivo con colores), app.css (componentes)
│   ├── js/         api.js (la única puerta a los servicios), un archivo por pantalla
│   │   └── modulos/  piezas compartidas: notificaciones, gráficas, búsqueda en la red…
│   └── vendor/     Bootstrap, Bootstrap Icons, Chart.js y las fuentes (sin internet)
├── servicios/      Los 5 servicios, cada uno en su carpeta:
│   ├── comun/      Infraestructura compartida: configuración, conexión, respuestas,
│   │               cliente HTTP entre servicios, sesión, ping
│   ├── auth/  dispositivos/  monitoreo/  alertas/  descubrimiento/
│   │               Cada uno: index.php (rutas), endpoints.php, validacion.php, datos.php (SQL)
├── database/       schema.sql (tablas), inicial.sql (usuario admin), seed.sql (datos de ejemplo)
├── herramientas/   Solo terminal: crear-base, restaurar-respaldo, preparar-demo,
│                   monitorear-rondas, ver-llamadas, iniciar-servicios (.bat/.ps1 y .sh)
├── pruebas/        Las 6 suites y sus ayudas
└── docs/           Esta guía, API.md, PLAN.md, DEMO.md, WINDOWS.md y diseno/
```

Qué leer para entender cada parte:

| Quieres entender… | Lee |
|---|---|
| Qué endpoints hay y qué responden | [`docs/API.md`](API.md) |
| Cómo se construyó el proyecto, fase por fase | [`docs/PLAN.md`](PLAN.md) |
| Las reglas del proyecto (SOA, estilo, diseño) | [`CLAUDE.md`](../CLAUDE.md) |
| Un servicio completo, el modelo de los demás | `servicios/dispositivos/`: `index.php` → `endpoints.php` → `validacion.php` → `datos.php` |
| Cómo se hablan los servicios | `servicios/comun/http.php` y `servicios/monitoreo/clientes.php` |
| El ping y el escáner | `servicios/comun/ping.php` y `servicios/descubrimiento/` |
| Cómo una pantalla pide sus datos | `web/js/api.js` y, por ejemplo, `web/js/dispositivos.js` |
| Los colores y el diseño | `web/css/tokens.css` y la página `componentes.html` |
| La demo, con el guion y el reparto | [`docs/DEMO.md`](DEMO.md) |
| Lo que se comprobó en Windows | [`docs/WINDOWS.md`](WINDOWS.md) |

---

## 11. Trabajar en equipo con git

Para subir cambios necesitas permiso en el repositorio: pídele al dueño que te agregue en
GitHub (**Settings → Collaborators**).

1. **Antes de empezar, trae lo último** (en la rama `main`):

   ```powershell
   git switch main
   ```

   ```powershell
   git pull
   ```

2. **Una rama por cambio**, con un nombre que diga qué haces:

   ```powershell
   git switch -c corrige-filtro-alertas
   ```

3. Trabaja y, antes de guardar, mira qué cambió:

   ```powershell
   git status
   ```

4. **Commits pequeños**, en español, que digan qué cambió. Agrega solo los archivos de tu
   cambio:

   ```powershell
   git add web/js/alertas.js
   ```

   ```powershell
   git commit -m "Corrige el filtro de alertas atendidas"
   ```

5. Corre las pruebas que tocan tu cambio (sección 8) y **sube la rama**:

   ```powershell
   git push -u origin corrige-filtro-alertas
   ```

6. En GitHub, abre un **Pull Request** de tu rama hacia `main` para que el equipo lo
   revise antes de fusionarlo.

Con GitHub Desktop es lo mismo: **Fetch/Pull origin**, **Current branch → New branch**,
marca los archivos, escribe el mensaje y **Commit**, luego **Push origin** y **Create Pull
Request**.

**Nunca subas:**

- La carpeta **`respaldos/`** ni ningún respaldo `.sql` de tu base: tienen tus datos.
- Datos personales: contraseñas, IPs o MACs de equipos de tu casa, capturas con datos
  reales.
- Los archivos temporales de las pruebas (`pruebas/.*.json`) ni registros (`*.log`).

`.gitignore` ya ignora `respaldos/`, los registros y los archivos de las pruebas. Aun así,
revisa `git status` antes de cada commit. Si git dice que un archivo está «modificado»
sin que lo hayas tocado, puede ser por los finales de línea: no lo agregues.

---

## 12. Qué se comprobó y qué falta

**Comprobado en Windows 11 (20 de octubre de 2026)**, en una computadora con XAMPP 8.2.12
ya instalado:

- Clonar con git, crear la base vacía, iniciar sesión y que las carpetas privadas (como
  `database/`, `docs/`, `pruebas/`, `.git/`) no se vean por HTTP.
- El ping real de Windows en español (`tiempo<1m`, «Tiempo de espera agotado», «Host de
  destino inaccesible») y el escáner real en una red de casa: 7 equipos en unos 7 s, con
  nombres por UPnP y NetBIOS.
- Las 6 suites con Chrome, y la de autenticación también con Edge.
- `iniciar-servicios.bat`: con Apache y MySQL ya encendidos, con los dos apagados (los
  encendió) y con otro programa en el puerto 80 y en el 3306 (dijo cuál).
- Tailscale en la computadora, y el celular con **datos móviles** cargando la pantalla de
  inicio de sesión por la IP 100.x.

**No comprobado todavía** (no se afirma que funcione):

- **Esta guía seguida en una copia recién clonada** dentro de `C:\xampp\htdocs`. Sí se
  revisó un clon nuevo fuera de `htdocs`: finales de línea, el BOM del `.ps1` y la
  sintaxis de todos los archivos.
- **Linux** después de estos cambios: la computadora con Linux estaba fuera de servicio.
- **Live Server**: no estaba instalado. Lo de `liveServer.settings.host` sale del código
  de la extensión.
- **Una red pública** (hotspot nuevo) con la regla del Firewall para redes públicas.
- **Iniciar sesión** desde el celular por Tailscale (solo cargó la pantalla) y **compartir
  la computadora** con la cuenta de un compañero (*Share*).
- **GitHub Desktop** y la instalación de XAMPP desde cero (en esta computadora ya estaba).
- Windows en otro idioma: el ping en inglés solo se probó con salidas de ejemplo.
