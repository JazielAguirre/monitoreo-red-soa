# Verificación en Windows

El proyecto se desarrolló en Linux. El **20 de octubre de 2026** se probó por primera vez
en un Windows real y se corrigió lo que fallaba (rama `windows-y-guia`, etiqueta `v1.4`).
Este documento dice qué se comprobó, qué se corrigió y qué falta, y trae una lista corta
para la primera vez que el proyecto corre en la computadora de otro integrante.

La instalación paso a paso está en la [Guía del equipo](GUIA-EQUIPO.md#3-instalación-en-windows-desde-cero).

## La computadora de referencia

| | |
|---|---|
| Sistema | Windows 11 Home (compilación 26200), en español de México (es-MX); consola en CP850 |
| XAMPP | 8.2.12 en `C:\xampp`: PHP 8.2.12 (ZTS x64), MariaDB 10.4.32, Apache 2.4.58. Apache y MySQL como programas, no como servicios |
| Otros | Git 2.55, Chrome 156, Edge 155, VS Code 1.142, Tailscale 1.104.2 |
| Red | WiFi de casa (192.168.0.0/24, red Privada) y Tailscale (red Privada) |

## Qué se comprobó

| Parte | Resultado |
|---|---|
| Apache, `.htaccess` y módulos | `AllowOverride All`, `mod_rewrite` y `mod_headers` activos. Los `.htaccess` funcionan aunque git los deje con CRLF |
| Lo que no debe verse por HTTP | 404 (o 403) en `CLAUDE.md`, `.git/`, `database/`, `docs/`, `pruebas/`, `herramientas/`, `respaldos/` y `servicios/comun/`, también con mayúsculas (`/Database/Schema.sql`), nombres cortos 8.3 (`HERRAM~1`), `::$DATA`, punto o espacio al final y `..%5c`. phpMyAdmin de XAMPP solo responde en la propia computadora |
| Base de datos | `crear-base.php` crea la base vacía; los `.sql` con CRLF se cargan bien. `restaurar-respaldo.php` lista los respaldos |
| **Ping** (`servicios/comun/ping.php`) | Salidas reales en español: `tiempo<1m` → 0; `tiempo=3ms` → 3; «Tiempo de espera agotado para esta solicitud» en ~0.8 s → inactivo; **«Respuesta desde 192.168.0.19: Host de destino inaccesible»** con **código de salida 0** y «recibidos = 1» → inactivo (no trae `TTL=`). La salida en CP850 se convierte bien a UTF-8. Probado también a través de Apache |
| Red local | `route print -4` da la puerta de enlace y `arp -a` en español se lee bien (descarta broadcast y multicast) |
| **Escáner** | Real, a través de Apache, en la red de casa: 7 equipos en 7.1 s (lote 1 en 2.4 s, los demás en ~1.5 s). Nombres: el módem por UPnP («Technicolor CGA2121»), dos equipos por NetBIOS; los teléfonos, sin nombre. Las respuestas de nombres llegan con el Firewall activo (red Privada) |
| Herramientas | `preparar-demo.php` (sin cambiar la base), `ver-llamadas.php` en vivo y `monitorear-rondas.php` (en las pruebas, con Ctrl+C) |
| **Navegador de las pruebas** | Chrome y Edge (`CHROME_BIN`): abren con un perfil temporal y, al cerrar, no queda ningún proceso y el perfil se borra |
| **Las 6 suites** | Con `C:\xampp\php\php.exe`: dispositivos 263, alertas 155, monitoreo 328, auth 127, descubrimiento 203, interfaz 372. Un solo aviso, justificado: sin `/etc/resolv.conf` no hay un DNS local que conozca `127.0.0.1` |
| `iniciar-servicios.bat` | Con Apache y MySQL encendidos (8 s), con los dos apagados (los enciende), con otro programa en el puerto 80 y en el 3306 (dice cuál, con su PID), con Tailscale conectado («Todo encendido.») |
| Celular por Tailscale | Con datos móviles (WiFi apagado), `http://<IP 100.x>/monitoreo-red-soa/web/` cargó la pantalla de inicio de sesión |

## Qué se corrigió

| Problema en Windows | Corrección |
|---|---|
| El XAMPP de Windows no trae `C:\xampp\php\logs`: el registro de llamadas no se escribía y **`ver-llamadas.php` no mostraba nada** | `comun/http.php` crea la carpeta si no existe |
| Un pipe de `proc_open` **no se puede leer sin bloquear**: `stream_select()` lo da por listo aunque esté vacío y la lectura espera hasta ~32 s o a que el proceso termine | Las pruebas que leen a un proceso que sigue corriendo (el respondedor falso de nombres, `monitorear-rondas.php`) usan un archivo temporal. `descubrimiento.php` pasó de 110 s a 26 s |
| El manejador de **Ctrl+C** de PHP solo corre cuando termina el `usleep()` en curso: en `monitorear-rondas.php`, Ctrl+C tardaba hasta 60 s (o 5 minutos con `--cada=300`) | Espera en pasos de 0.2 s. Se prueba con Ctrl+Break, que antes se omitía en Windows |
| Conectar a un puerto cerrado de `127.0.0.1` tarda ~2 s (Windows reintenta la conexión) | La prueba del 502 del escáner descuenta ese tiempo |
| `escapeshellarg()` cambia las comillas dobles por espacios: `php -r "…"` fallaba | Las pruebas usan un script temporal |
| En una computadora nueva, las suites se caían con un error fatal si tu base no existía | Se detienen al empezar y dicen cómo crearla |
| No había ninguna prueba de la lectura de la salida del ping | `pruebas/monitoreo.php` §15, con las salidas reales de arriba y las de Windows en inglés, Linux y macOS |
| Encender todo dependía del panel de XAMPP | `herramientas\iniciar-servicios.bat` y `.ps1` (doble clic) |
| `iniciar-servicios.sh` quedaba con CRLF en Windows (bash no lo acepta) | `.gitattributes`: `.sh` con LF; `.ps1` y `.bat` con CRLF |

## Qué falta comprobar

- **La guía seguida en una copia recién clonada** dentro de `C:\xampp\htdocs` (habría que
  renombrar la carpeta de trabajo). Un clon nuevo fuera de `htdocs` sí se revisó: `.sh`
  con LF, `.ps1` y `.bat` con CRLF, el `.ps1` con BOM, sin errores de sintaxis y con
  `git status` limpio.
- **Linux** después de estos cambios: la computadora con Linux estaba fuera de servicio.
  Los cambios no quitan ramas de código de Linux, pero las 6 suites no se han vuelto a
  correr ahí.
- **Una red pública** (por ejemplo, un hotspot nuevo) con la regla del Firewall para redes
  públicas. En la computadora de referencia, Apache solo estaba permitido en redes
  privadas.
- **Live Server** (no estaba instalado) e **iniciar sesión** desde el celular por
  Tailscale (solo se cargó la pantalla).
- **Compartir la computadora** con la cuenta de Tailscale de otro integrante (*Share*).
- **Windows en otro idioma:** el ping en inglés solo con salidas de ejemplo. En otro idioma
  (por ejemplo, portugués: `tempo=`) el equipo saldría Activo, pero sin tiempo.
- La computadora de otro integrante (otra versión de Windows, antivirus, otro XAMPP).

## Lista para la primera vez en otra computadora

Todo en **PowerShell**, desde la carpeta del proyecto (`cd C:\xampp\htdocs\monitoreo-red-soa`),
con la instalación de la guía hecha (XAMPP, el repositorio clonado y la base creada).

- [ ] **1. Todo de una vez.** Doble clic en `herramientas\iniciar-servicios.bat`. Debe
  terminar en «Todo encendido.»; si no, cada ✗ dice qué hacer. Sin Tailscale, el único
  ✗ es el de Tailscale.

- [ ] **2. Sintaxis.** No debe imprimir nada:
  ```powershell
  Get-ChildItem -Recurse -Filter *.php servicios, herramientas, pruebas | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName } | Select-String -NotMatch "No syntax errors"
  ```

- [ ] **3. Ping desde PHP.** Debe decir `responde: true` con un tiempo y luego `responde: false`:
  ```powershell
  C:\xampp\php\php.exe -r "require 'servicios/comun/ping.php'; foreach (['127.0.0.1', '192.0.2.20'] as `$ip) { `$r = hacerPing(`$ip); echo `$ip, ': responde: ', json_encode(`$r['responde']), ', tiempo: ', (`$r['tiempo_respuesta_ms'] ?? 'null'), PHP_EOL, `$r['salida'], PHP_EOL, PHP_EOL; }"
  ```

- [ ] **4. Las 6 suites** (unos 7 minutos). Cada una debe terminar en
  `Resultado: N de N comprobaciones correctas.`:
  ```powershell
  [Console]::OutputEncoding = [Text.Encoding]::UTF8; foreach ($s in 'dispositivos','alertas','monitoreo','auth','descubrimiento','interfaz') { "== $s"; C:\xampp\php\php.exe pruebas\$s.php | Select-String '^Resultado|^\s*✗|! Aviso' }
  ```

- [ ] **5. Desde el celular**, en la misma WiFi, con la dirección que imprime el paso 1.
  Si no carga: el comando del Firewall que muestra el mismo resumen.

- [ ] **6. Buscar dispositivos en la red.** **Solo en tu red de casa o en el hotspot del
  equipo.** En Dispositivos, pulsa *Buscar dispositivos en la red*: en unos 7 s debe
  mostrar al menos el router y «Esta computadora». Compara con `arp -a`: las IPs con
  «dinámico» de tu red deben estar en la lista.

## Si algo falla, envía esto

1. La salida completa del paso que falló (copiar y pegar el texto, no una foto).
2. Para el ping: la `salida` del paso 3 y el idioma de Windows. Para el escáner: la
   salida de `arp -a` (puedes borrar las MAC).
3. Las últimas líneas de `C:\xampp\php\logs\php_error_log`, de
   `C:\xampp\apache\logs\error.log` y, si es MySQL, de `C:\xampp\mysql\data\mysql_error.log`.

## Notas

- **El aviso `aria_log_control … (Errcode: 9 "Bad file descriptor")`** en
  `mysql_error.log` al apagar MySQL es normal en el XAMPP de Windows; también aparece al
  apagarlo desde el panel.
- **MySQL lanzado sin consola.** En las pruebas de este documento, un `mysqld` encendido
  desde un `Start-Job` de PowerShell (un proceso en segundo plano sin consola) terminó con
  «InnoDB: Operating system error number 6» o se quedó colgado al apagarlo. Encendido desde
  una consola, como lo hacen el doble clic, la terminal y el panel de XAMPP, cerró limpio
  las 6 veces. No enciendas MySQL con `Start-Job` ni desde servicios sin consola.
- **Finales de línea.** Con `core.autocrlf=true` (lo normal en Git para Windows), los
  archivos de texto quedan con CRLF en la carpeta y LF en el repositorio. PHP, SQL y
  `.htaccess` funcionan igual; `.gitattributes` fija los de los scripts.
