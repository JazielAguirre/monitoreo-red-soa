# Verificación en Windows

El proyecto se desarrolló en Linux. El ping (`servicios/comun/ping.php`) solo se
probó en Windows con salidas de ejemplo, nunca en un Windows real. Esta lista es
para el primer integrante que lo corra en Windows: son unos 15 minutos.

Antes: instalación del [README](../README.md#3-instalación-en-windows) (XAMPP en
`C:\xampp`, proyecto en `C:\xampp\htdocs\monitoreo-red-soa`, Apache y MySQL encendidos
y la base cargada). Todo se escribe en **PowerShell**, desde la carpeta del proyecto:

```powershell
cd C:\xampp\htdocs\monitoreo-red-soa
```

## Lista

- [ ] **1. Sintaxis.** No debe imprimir ningún error:
  ```powershell
  Get-ChildItem -Recurse -Filter *.php servicios, herramientas, pruebas | ForEach-Object { C:/xampp/php/php.exe -l $_.FullName } | Select-String -NotMatch "No syntax errors"
  ```

- [ ] **2. Ping desde PHP**, sin Apache (debe decir `responde: true` con un tiempo, y `responde: false`):
  ```powershell
  C:/xampp/php/php.exe -r "require 'servicios/comun/ping.php'; foreach (['127.0.0.1', '192.0.2.20'] as `$ip) { `$r = hacerPing(`$ip); echo `$ip, ': responde: ', json_encode(`$r['responde']), ', tiempo: ', (`$r['tiempo_respuesta_ms'] ?? 'null'), PHP_EOL, `$r['salida'], PHP_EOL, PHP_EOL; }"
  ```
  - 127.0.0.1 → `responde: true`, `tiempo: 0` (Windows escribe `tiempo<1m`) o un número.
  - 192.0.2.20 → `responde: false`, `tiempo: null`, en unos 1–2 segundos.

- [ ] **3. Ping a través de Apache** (así lo hace la aplicación; Apache puede tener otros permisos):
  ```powershell
  C:/xampp/php/php.exe herramientas/preparar-demo.php --sin-red
  ```
  Debe terminar con todas las líneas de la comprobación previa en ✓ (el router
  `192.0.2.30` "no responde, como se espera"). Después, en el navegador
  (<http://localhost/monitoreo-red-soa/web/>, `admin` / `admin123`), pulsa
  **Monitorear todos** en el Dashboard: *Mi PC* y *PC Recepción* deben quedar
  **Activo** con un tiempo, y *Router principal* **Inactivo** con una alerta nueva.

- [ ] **4. Detección de la red.** Sin opciones, el script debe encontrar tu router y tu IP:
  ```powershell
  C:/xampp/php/php.exe herramientas/preparar-demo.php
  ```
  Compara las IPs del resumen con las de `ipconfig` ("Puerta de enlace predeterminada" e "IPv4").

- [ ] **5. Las 5 suites.** Cada una debe terminar en `Resultado: N de N comprobaciones correctas.`:
  ```powershell
  foreach ($s in 'dispositivos','alertas','monitoreo','auth','interfaz') { C:/xampp/php/php.exe pruebas/$s.php | Select-String 'Resultado|✗' }
  ```
  `interfaz.php` y la última parte de `auth.php` usan Chrome o Edge; tardan varios minutos.

- [ ] **6. Desde el celular.** Con la URL que imprimió el paso 4, en la misma WiFi.
  Si no carga: Firewall de Windows → permitir *Apache HTTP Server* en redes privadas.

## Si algo falla, envía esto

1. La salida completa del paso que falló (copiar y pegar el texto, no una foto).
2. Para el ping: la `salida` del paso 2 (el texto que imprime `ping`) y el idioma de Windows.
3. Las últimas líneas de `C:\xampp\php\logs\php_error_log` y de `C:\xampp\apache\logs\error.log`.

## Lo que más probablemente falle en Windows

| Parte | Por qué | Dónde |
|---|---|---|
| **Tiempo del ping** | Se extrae de la salida con `time`/`tiempo` y `=`/`<`. Un Windows en otro idioma (por ejemplo portugués: `tempo=`) respondería bien pero guardaría el tiempo como `null` ("Sin respuesta" en la interfaz aunque el estado sea Activo) | `extraerTiempoMs()` en `ping.php` |
| **Acentos de la salida** | La consola de Windows escribe en CP850; se convierte a UTF-8 suponiendo esa página de códigos. Con otra (CP437, CP1252) un mensaje con acentos podría verse mal en la `salida` (no afecta al estado, que solo busca `TTL=`) | `convertirAUtf8()` en `ping.php` |
| **"Host de destino inaccesible"** | Según la red, el router contesta por el dispositivo y `ping` termina con código 0. El código ya lo trata (el dispositivo está activo solo si hay `TTL=`), pero nunca se vio con una salida real | `salidaIndicaRespuesta()` en `ping.php` |
| **Chrome de las pruebas** | Rutas de `chrome.exe`/`msedge.exe`, cierre del proceso y borrado del perfil temporal, que Windows puede mantener bloqueado un momento después de cerrar Chrome | `pruebas/navegador.php` |
| **Comandos con redirección** | `exec()` de PHP pasa por `cmd.exe`: las comillas de `escapeshellarg()` y el `<` para cargar el SQL se escribieron para funcionar ahí, pero no se han visto correr | `herramientas/base-de-datos.php` |
| **Socket que no responde** | La prueba del 502 por tiempo agotado abre un socket local que nunca contesta; Windows maneja la cola de conexiones de otra forma y podría rechazar en vez de esperar | `socketQueNoResponde()` en `pruebas/ayudas.php` |
| **Finales de línea** | Con `core.autocrlf=true`, git convierte los archivos a CRLF. PHP, SQL y `.htaccess` funcionan igual; solo puede hacer que `git status` muestre archivos "modificados" | configuración de git |
