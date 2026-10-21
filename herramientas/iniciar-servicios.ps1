<#
Enciende lo que necesita la aplicación en Windows con XAMPP, también para entrar desde
el celular por Tailscale (desde otra red). Es el equivalente de iniciar-servicios.sh.

  Doble clic en herramientas\iniciar-servicios.bat
  o, en PowerShell, desde la carpeta del proyecto:
  powershell -ExecutionPolicy Bypass -File herramientas\iniciar-servicios.ps1

1. Apache y MySQL de XAMPP: los enciende solo si no están encendidos y confirma que de
   verdad quedaron encendidos (Apache contesta por HTTP; MySQL, a mysqladmin ping). Si
   otro programa ocupa el puerto 80, 443 o 3306, dice cuál y qué hacer.
2. Tailscale: comprueba que esté instalado y conectado y muestra su IP (100.x.y.z).
3. Firewall de Windows: comprueba que Apache pueda recibir conexiones en las redes
   privadas y públicas. Si no, muestra el comando para permitirlo, pero no lo ejecuta
   (necesita administrador).
4. La comprobación previa de herramientas/preparar-demo.php: no cambia tu base.
5. Resumen con las direcciones para entrar.

No necesita administrador. Nunca se queda esperando: cada paso tiene un tope de tiempo
y, si algo falla, dice qué falló y qué hacer. Termina con 0 si todo quedó encendido y
1 si algo falló.

Con -SoloFunciones solo carga las funciones (las pruebas lo usan así:
. herramientas\iniciar-servicios.ps1 -SoloFunciones).
#>

param([switch]$SoloFunciones)

$XAMPP = 'C:\xampp'
$HTTPD = Join-Path $XAMPP 'apache\bin\httpd.exe'
$MYSQLD = Join-Path $XAMPP 'mysql\bin\mysqld.exe'
$MYSQLADMIN = Join-Path $XAMPP 'mysql\bin\mysqladmin.exe'
$MY_INI = Join-Path $XAMPP 'mysql\bin\my.ini'
$PHP = Join-Path $XAMPP 'php\php.exe'
$DIR_PROYECTO = Split-Path -Parent $PSScriptRoot
$RUTA_WEB = '/monitoreo-red-soa/web/'
$PUERTO_LIVE_SERVER = 5500
$ESPERA_APACHE = 15        # segundos para que Apache conteste
$ESPERA_MYSQL = 30         # MariaDB tarda más la primera vez
$ESPERA_TAILSCALE = 10
$ESPERA_COMPROBACION = 90  # preparar-demo.php hace pings y peticiones con tope propio
$PERFILES_NECESARIOS = @('Private', 'Public')
$NOMBRE_PERFIL = @{ Private = 'privadas'; Public = 'públicas'; Domain = 'de dominio' }

$script:Problemas = New-Object System.Collections.Generic.List[string]
$script:ResumenFirewall = 'sin revisar'
$script:ResumenComprobacion = 'sin ejecutar'

# ---------------------------------------------------------------- Mensajes

function Titulo([string]$texto) { Write-Host ''; Write-Host $texto }

function Bien([string]$texto) { Write-Host "  ✓ $texto" -ForegroundColor Green }

# ✗ con sus líneas de «Qué hacer»: cuenta como fallo y se repite en el resumen.
function Mal([string]$texto, [string[]]$pasos = @()) {
    Write-Host "  ✗ $texto" -ForegroundColor Red
    $script:Problemas.Add("✗ $texto")
    foreach ($paso in $pasos) {
        Write-Host "      $paso"
        $script:Problemas.Add("    $paso")
    }
}

# ⚠ solo avisa: no cuenta como fallo.
function Aviso([string]$texto, [string[]]$pasos = @()) {
    Write-Host "  ⚠ $texto" -ForegroundColor Yellow
    foreach ($paso in $pasos) { Write-Host "      $paso" }
}

# ---------------------------------------------------------------- Comandos con tope de tiempo

# Ejecuta un programa sin ventana y espera como máximo $segundos. Devuelve
# @{ Termino; Codigo; Salida } (stdout y stderr juntos). Si no termina, lo cierra.
function EjecutarConTope([string]$programa, [string]$argumentos, [int]$segundos) {
    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = $programa
    $info.Arguments = $argumentos
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.RedirectStandardInput = $true
    try {
        $proceso = [System.Diagnostics.Process]::Start($info)
    } catch {
        return @{ Termino = $true; Codigo = -1; Salida = $_.Exception.Message }
    }
    $proceso.StandardInput.Close()
    $salida = $proceso.StandardOutput.ReadToEndAsync()
    $errores = $proceso.StandardError.ReadToEndAsync()
    if (-not $proceso.WaitForExit($segundos * 1000)) {
        try { $proceso.Kill() } catch { }
        return @{ Termino = $false; Codigo = -1; Salida = '' }
    }
    $proceso.WaitForExit()
    return @{ Termino = $true; Codigo = $proceso.ExitCode; Salida = ($salida.Result + $errores.Result).Trim() }
}

# Código HTTP de la URL (0 si no contesta en $segundos).
function CodigoHttp([string]$url, [int]$segundos = 5) {
    try {
        $peticion = [System.Net.HttpWebRequest]::Create($url)
        $peticion.Timeout = $segundos * 1000
        $peticion.Proxy = $null
        $peticion.AllowAutoRedirect = $false
        $respuesta = $peticion.GetResponse()
        $codigo = [int]$respuesta.StatusCode
        $respuesta.Close()
        return $codigo
    } catch [System.Net.WebException] {
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode }
        return 0
    }
}

# ---------------------------------------------------------------- Puertos

# Quién escucha en el puerto TCP: @{ Pid; Nombre; Ruta; Servicio; NombreServicio }, o $null.
function ProcesoEnPuerto([int]$puerto) {
    $conexion = Get-NetTCPConnection -State Listen -LocalPort $puerto -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $conexion) { return $null }
    $id = [int]$conexion.OwningProcess
    $proceso = Get-Process -Id $id -ErrorAction SilentlyContinue
    $servicio = Get-CimInstance Win32_Service -Filter "ProcessId = $id" -ErrorAction SilentlyContinue | Select-Object -First 1
    $ruta = $null
    if ($proceso) { try { $ruta = $proceso.Path } catch { } }
    return @{
        Pid = $id
        Nombre = $(if ($proceso) { $proceso.ProcessName } else { 'desconocido' })
        Ruta = $ruta
        Servicio = $(if ($servicio) { $servicio.DisplayName } else { $null })
        NombreServicio = $(if ($servicio) { $servicio.Name } else { $null })
    }
}

# true si el proceso es el programa de XAMPP indicado (por ejemplo C:\xampp\apache\bin\httpd.exe).
function EsDeXampp($proceso, [string]$programa) {
    if (-not $proceso) { return $false }
    if ($proceso.Ruta) { return $proceso.Ruta -ieq $programa }
    return $proceso.Nombre -ieq [System.IO.Path]::GetFileNameWithoutExtension($programa)
}

# "el servicio «...» (W3SVC)", "Windows (System, PID 4): ...", "el programa X (ruta, PID n)".
function DescribirProceso($proceso) {
    if ($proceso.Pid -eq 4) { return 'Windows (System, PID 4): un servicio web de Windows que usa http.sys, como IIS' }
    if ($proceso.Servicio) { return "el servicio «$($proceso.Servicio)» ($($proceso.NombreServicio), programa $($proceso.Nombre), PID $($proceso.Pid))" }
    $ruta = $(if ($proceso.Ruta) { $proceso.Ruta } else { 'ruta desconocida' })
    return "el programa $($proceso.Nombre) ($ruta, PID $($proceso.Pid))"
}

# Líneas de «Qué hacer» cuando otro programa tiene el puerto.
function QueHacerConElPuerto($proceso) {
    $volver = 'y vuelve a ejecutar este script.'
    if ($proceso.Pid -eq 4) {
        return @(
            "Qué hacer: en PowerShell como administrador, Stop-Service -Name W3SVC (Servicio de publicación World Wide Web, de IIS) $volver",
            'Si no es IIS, «netsh http show servicestate» muestra qué servicio lo usa.'
        )
    }
    if ($proceso.NombreServicio) {
        return @(
            "Qué hacer: en PowerShell como administrador, Stop-Service -Name $($proceso.NombreServicio) $volver",
            "Para que no vuelva a encenderse al reiniciar: Set-Service -Name $($proceso.NombreServicio) -StartupType Manual"
        )
    }
    if ($proceso.Nombre -match '^(com\.docker\.backend|com\.docker\.proxy|vpnkit|wslrelay|docker)') {
        return @("Qué hacer: es Docker. Detén el contenedor que publica ese puerto (docker ps; docker stop NOMBRE) $volver")
    }
    return @("Qué hacer: cierra $($proceso.Nombre) (o termínalo en el Administrador de tareas, PID $($proceso.Pid)) $volver")
}

# El primer puerto de la lista ocupado por un programa que no es $programa: @{ Puerto; Proceso }, o $null.
function PuertoOcupadoPorOtro([int[]]$puertos, [string]$programa) {
    foreach ($puerto in $puertos) {
        $proceso = ProcesoEnPuerto $puerto
        if ($proceso -and -not (EsDeXampp $proceso $programa)) { return @{ Puerto = $puerto; Proceso = $proceso } }
    }
    return $null
}

function UltimasLineas([string]$archivo, [int]$cuantas) {
    if (Test-Path $archivo) {
        Get-Content $archivo -Tail $cuantas -ErrorAction SilentlyContinue | ForEach-Object { Write-Host "        $_" }
    }
}

# ---------------------------------------------------------------- Apache y MySQL

function ApacheContesta { return (CodigoHttp "http://localhost${RUTA_WEB}login.html") -eq 200 }

function ApacheEncendido { return (EsDeXampp (ProcesoEnPuerto 80) $HTTPD) -and (ApacheContesta) }

function MysqlContesta {
    if (-not (Test-Path $MYSQLADMIN)) { return $false }
    $r = EjecutarConTope $MYSQLADMIN '--connect-timeout=3 -h 127.0.0.1 -P 3306 -u root ping' 8
    return $r.Termino -and $r.Codigo -eq 0 -and $r.Salida -match 'alive'
}

function MysqlEncendido { return (EsDeXampp (ProcesoEnPuerto 3306) $MYSQLD) -and (MysqlContesta) }

function Esperar([scriptblock]$condicion, [int]$segundos) {
    $limite = (Get-Date).AddSeconds($segundos)
    while ((Get-Date) -lt $limite) {
        if (& $condicion) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return [bool](& $condicion)
}

function EncenderApache {
    if (-not (Test-Path $HTTPD)) {
        Mal "No se encontró $HTTPD" @('Qué hacer: instala XAMPP en C:\xampp (docs/GUIA-EQUIPO.md, «Instalación en Windows»).')
        return
    }
    if (ApacheEncendido) { Bien 'Apache ya estaba encendido'; return }
    $ocupado = PuertoOcupadoPorOtro @(80, 443) $HTTPD
    if ($ocupado) {
        Mal "Apache no se puede encender: el puerto $($ocupado.Puerto) lo ocupa $(DescribirProceso $ocupado.Proceso)" (QueHacerConElPuerto $ocupado.Proceso)
        return
    }
    if (-not (EsDeXampp (ProcesoEnPuerto 80) $HTTPD)) {
        # Como apache_start.bat de XAMPP, pero en segundo plano y sin ventana.
        Start-Process -FilePath $HTTPD -WorkingDirectory $XAMPP -WindowStyle Hidden
    }
    if (Esperar { ApacheEncendido } $ESPERA_APACHE) { Bien 'Apache encendido'; return }
    Write-Host "      Últimas líneas de $XAMPP\apache\logs\error.log:"
    UltimasLineas (Join-Path $XAMPP 'apache\logs\error.log') 5
    Mal "Apache no contestó en $ESPERA_APACHE s (http://localhost${RUTA_WEB}login.html)" @(
        'Qué hacer: lee el registro de arriba y abre el panel de XAMPP (C:\xampp\xampp-control.exe): su ventana dice por qué no enciende.',
        'Si Apache contesta pero no esta página, la carpeta debe ser C:\xampp\htdocs\monitoreo-red-soa (docs/GUIA-EQUIPO.md, «Problemas comunes»).'
    )
}

function EncenderMysql {
    if (-not (Test-Path $MYSQLD)) {
        Mal "No se encontró $MYSQLD" @('Qué hacer: instala XAMPP en C:\xampp (docs/GUIA-EQUIPO.md, «Instalación en Windows»).')
        return
    }
    if (MysqlEncendido) { Bien 'MySQL ya estaba encendido'; return }
    $ocupado = PuertoOcupadoPorOtro @(3306) $MYSQLD
    if ($ocupado) {
        $pasos = @(QueHacerConElPuerto $ocupado.Proceso) + 'Mientras ese programa ocupe el puerto 3306, la aplicación no puede usar tu base.'
        Mal "MySQL de XAMPP no se puede encender: el puerto 3306 lo ocupa $(DescribirProceso $ocupado.Proceso)" $pasos
        return
    }
    if (-not (EsDeXampp (ProcesoEnPuerto 3306) $MYSQLD)) {
        # Como mysql_start.bat de XAMPP, pero en segundo plano y sin ventana.
        Start-Process -FilePath $MYSQLD -ArgumentList "--defaults-file=`"$MY_INI`"", '--standalone' -WorkingDirectory $XAMPP -WindowStyle Hidden
    }
    if (Esperar { MysqlEncendido } $ESPERA_MYSQL) { Bien 'MySQL encendido'; return }
    Write-Host "      Últimas líneas de $XAMPP\mysql\data\mysql_error.log:"
    UltimasLineas (Join-Path $XAMPP 'mysql\data\mysql_error.log') 8
    Mal "MySQL no contestó en $ESPERA_MYSQL s" @('Qué hacer: lee el registro de arriba y abre el panel de XAMPP; si dice «MySQL shutdown unexpectedly», sigue docs/GUIA-EQUIPO.md, «Problemas comunes».')
}

# ---------------------------------------------------------------- Tailscale

function ProgramaTailscale {
    $comando = Get-Command tailscale.exe -ErrorAction SilentlyContinue
    if ($comando) { return $comando.Source }
    $ruta = Join-Path $env:ProgramFiles 'Tailscale\tailscale.exe'
    if (Test-Path $ruta) { return $ruta }
    return $null
}

# Lee la salida de «tailscale status --json»: @{ Estado; Ip; Nombre; Enlace } (Estado vacío si no se pudo leer).
function LeerEstadoTailscale([string]$json) {
    $estado = @{ Estado = ''; Ip = $null; Nombre = $null; Enlace = $null }
    try { $datos = $json | ConvertFrom-Json } catch { return $estado }
    if (-not $datos) { return $estado }
    $propiedades = $datos.PSObject.Properties.Name
    if ($propiedades -contains 'BackendState') { $estado.Estado = [string]$datos.BackendState }
    if ($propiedades -contains 'AuthURL' -and $datos.AuthURL) { $estado.Enlace = [string]$datos.AuthURL }
    if ($propiedades -contains 'Self' -and $datos.Self) {
        $yo = $datos.Self
        if ($yo.PSObject.Properties.Name -contains 'TailscaleIPs') {
            $estado.Ip = @($yo.TailscaleIPs | Where-Object { $_ -match '^\d+\.\d+\.\d+\.\d+$' }) | Select-Object -First 1
        }
        if ($yo.PSObject.Properties.Name -contains 'HostName') { $estado.Nombre = [string]$yo.HostName }
    }
    return $estado
}

function EstadoTailscale {
    $programa = ProgramaTailscale
    if (-not $programa) { return $null }
    $r = EjecutarConTope $programa 'status --json' $ESPERA_TAILSCALE
    if (-not $r.Termino) { return @{ Estado = 'SinRespuesta'; Ip = $null; Nombre = $null; Enlace = $null } }
    return LeerEstadoTailscale $r.Salida
}

# Mensaje y «Qué hacer» para un estado de Tailscale que no es Running.
function QueHacerConTailscale($estado) {
    switch ($estado.Estado) {
        'NeedsLogin' {
            $donde = $(if ($estado.Enlace) { "abre $($estado.Enlace)" } else { 'abre Tailscale (icono junto al reloj) → Log in' })
            return @('Falta iniciar sesión en Tailscale', "Qué hacer: $donde, entra con tu cuenta y vuelve a ejecutar este script.")
        }
        'NeedsMachineAuth' { return @('Tailscale espera que se apruebe esta computadora', 'Qué hacer: apruébala en https://login.tailscale.com/admin/machines y vuelve a ejecutar este script.') }
        'Stopped' { return @('Tailscale está desconectado', 'Qué hacer: abre Tailscale (icono junto al reloj) → Connect, o en PowerShell: tailscale up. Después vuelve a ejecutar este script.') }
        'SinRespuesta' { return @("Tailscale no contestó en $ESPERA_TAILSCALE s", 'Qué hacer: abre Tailscale desde el menú Inicio; si sigue igual, reinicia la computadora.') }
        default { return @("Tailscale no está conectado (estado: $(if ($estado.Estado) { $estado.Estado } else { 'el servicio no contesta' }))", 'Qué hacer: abre Tailscale desde el menú Inicio, inicia sesión y vuelve a ejecutar este script.') }
    }
}

function ComprobarTailscale {
    $estado = EstadoTailscale
    if ($null -eq $estado) {
        Mal 'Tailscale no está instalado' @('Qué hacer: descárgalo de https://tailscale.com/download/windows, instálalo, inicia sesión y vuelve a ejecutar este script.')
        return $null
    }
    if ($estado.Estado -ne 'Running') {
        $mensaje = QueHacerConTailscale $estado
        Mal $mensaje[0] @($mensaje[1])
        return $null
    }
    Bien "Tailscale está conectado ($($estado.Ip), $($estado.Nombre))"
    if (ApacheEncendido) {
        $codigo = CodigoHttp "http://$($estado.Ip)${RUTA_WEB}login.html"
        if ($codigo -eq 200) {
            Bien "Apache responde en la IP de Tailscale (http://$($estado.Ip)$RUTA_WEB)"
        } else {
            Aviso "Apache no responde en la IP de Tailscale (HTTP $codigo)" @("Revisa que $XAMPP\apache\conf\httpd.conf diga «Listen 80» y no «Listen 127.0.0.1:80».")
        }
    }
    return $estado.Ip
}

# ---------------------------------------------------------------- Firewall de Windows

# Perfiles (Private, Public, Domain) que incluye el valor Profile de una regla ("Any", "Private, Public"…).
function PerfilesDeRegla([string]$perfil) {
    if ($perfil -match 'Any' -or $perfil -eq '0') { return @('Domain', 'Private', 'Public') }
    return @($perfil -split ',\s*' | Where-Object { $_ })
}

# Perfiles de $necesarios en los que Apache NO puede recibir conexiones. $reglas: las reglas
# de entrada habilitadas de Apache (@{ Accion = 'Allow'|'Block'; Perfil }); $desactivados: perfiles
# con el firewall apagado (ahí todo entra). Una regla Block gana a una Allow.
function PerfilesSinPermiso($reglas, [string[]]$necesarios, [string[]]$desactivados = @()) {
    $permitidos = @{}
    $bloqueados = @{}
    foreach ($regla in @($reglas)) {
        foreach ($perfil in (PerfilesDeRegla ([string]$regla.Perfil))) {
            if ($regla.Accion -eq 'Block') { $bloqueados[$perfil] = $true } else { $permitidos[$perfil] = $true }
        }
    }
    return @($necesarios | Where-Object { ($desactivados -notcontains $_) -and (-not $permitidos[$_] -or $bloqueados[$_]) })
}

# El comando exacto (PowerShell como administrador) para permitir Apache en esos perfiles.
function ComandoParaPermitirApache([string[]]$perfiles) {
    $nombres = ($perfiles | ForEach-Object { $NOMBRE_PERFIL[$_] }) -join ' y '
    return "New-NetFirewallRule -DisplayName `"Apache HTTP Server (XAMPP, redes $nombres)`" -Direction Inbound -Action Allow -Program `"$HTTPD`" -Protocol TCP -LocalPort 80 -Profile $($perfiles -join ',')"
}

# Reglas de entrada habilitadas para httpd.exe de XAMPP: @{ Accion; Perfil; Nombre; NombreInterno }.
function ReglasDeApache {
    $filtros = Get-NetFirewallApplicationFilter -ErrorAction SilentlyContinue | Where-Object { $_.Program -ieq $HTTPD }
    $reglas = foreach ($filtro in @($filtros | Where-Object { $_ })) {
        $regla = $filtro | Get-NetFirewallRule -ErrorAction SilentlyContinue
        if ($regla -and [string]$regla.Enabled -eq 'True' -and [string]$regla.Direction -eq 'Inbound') {
            $puertos = $regla | Get-NetFirewallPortFilter -ErrorAction SilentlyContinue
            if (-not $puertos -or [string]$puertos.Protocol -in @('Any', 'TCP', '6')) {
                @{ Accion = [string]$regla.Action; Perfil = [string]$regla.Profile; Nombre = $regla.DisplayName; NombreInterno = $regla.Name }
            }
        }
    }
    return @($reglas)
}

function ComprobarFirewall {
    try {
        $reglas = @(ReglasDeApache)
        $desactivados = @(Get-NetFirewallProfile -ErrorAction Stop | Where-Object { -not $_.Enabled } | ForEach-Object { [string]$_.Name })
        $activos = @(Get-NetConnectionProfile -ErrorAction SilentlyContinue | ForEach-Object { [string]$_.NetworkCategory } | Where-Object { $_ -in @('Private', 'Public', 'DomainAuthenticated') } | ForEach-Object { if ($_ -eq 'DomainAuthenticated') { 'Domain' } else { $_ } } | Sort-Object -Unique)
    } catch {
        Aviso 'No se pudo leer la configuración del Firewall de Windows' @($_.Exception.Message)
        $script:ResumenFirewall = 'no se pudo revisar'
        return
    }

    $faltan = @(PerfilesSinPermiso $reglas $PERFILES_NECESARIOS $desactivados)
    foreach ($regla in @($reglas | Where-Object { $_.Accion -eq 'Block' })) {
        Aviso "Hay una regla que BLOQUEA a Apache: «$($regla.Nombre)» (redes $(((PerfilesDeRegla $regla.Perfil) | ForEach-Object { $NOMBRE_PERFIL[$_] }) -join ', '))" @(
            "Para quitarla (este script no lo ejecuta), en PowerShell como administrador: Disable-NetFirewallRule -Name `"$($regla.NombreInterno)`"")
    }
    if ($faltan.Count -eq 0) {
        Bien 'El Firewall de Windows permite a Apache en las redes privadas y públicas'
        $script:ResumenFirewall = 'permite Apache en redes privadas y públicas'
        return
    }

    $comando = ComandoParaPermitirApache $faltan
    $nombres = ($faltan | ForEach-Object { $NOMBRE_PERFIL[$_] }) -join ' y '
    $activosSinPermiso = @($activos | Where-Object { $faltan -contains $_ })
    $pasos = @(
        'Sin eso, el celular no puede cargar la página en esas redes (la computadora sí).',
        'Para permitirlo (este script no lo ejecuta), en PowerShell como administrador:',
        "  $comando"
    )
    if ($activosSinPermiso.Count -gt 0) {
        $ahora = ($activosSinPermiso | ForEach-Object { $NOMBRE_PERFIL[$_] }) -join ' y '
        Mal "El Firewall de Windows no permite a Apache en las redes $nombres, y ahora estás conectado a una red $ahora" $pasos
    } else {
        Aviso "El Firewall de Windows no permite a Apache en las redes $nombres" $pasos
    }
    $script:ResumenFirewall = "falta permitir Apache en redes ${nombres}: $comando"
}

# ---------------------------------------------------------------- Comprobación previa

function ComprobacionPrevia {
    if (-not (MysqlEncendido)) {
        Aviso 'Se omitió: el MySQL de XAMPP no está encendido' @('Sin él, preparar-demo.php fallaría o leería otro MySQL del puerto 3306. Resuelve lo de MySQL y vuelve a ejecutar este script.')
        $script:ResumenComprobacion = 'omitida (el MySQL de XAMPP no está encendido)'
        return
    }
    # Su salida va directo a esta ventana (sin redirigir), con su tope de tiempo.
    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = $PHP
    $info.Arguments = '"' + (Join-Path $DIR_PROYECTO 'herramientas\preparar-demo.php') + '"'
    $info.WorkingDirectory = $DIR_PROYECTO
    $info.UseShellExecute = $false
    $info.RedirectStandardInput = $true
    $proceso = [System.Diagnostics.Process]::Start($info)
    $proceso.StandardInput.Close()
    if (-not $proceso.WaitForExit($ESPERA_COMPROBACION * 1000)) {
        try { $proceso.Kill() } catch { }
        $script:ResumenComprobacion = "no terminó en $ESPERA_COMPROBACION s"
        Mal "preparar-demo.php no terminó en $ESPERA_COMPROBACION s" @("Qué hacer: córrelo a mano para ver dónde se queda: $PHP herramientas\preparar-demo.php")
        return
    }
    switch ($proceso.ExitCode) {
        0 { $script:ResumenComprobacion = 'sin ✗' }
        1 { $script:ResumenComprobacion = 'con ✗: lee «Qué hacer» en la comprobación previa de arriba' }
        default {
            $script:ResumenComprobacion = "no pudo empezar (código $($proceso.ExitCode))"
            Mal "preparar-demo.php no pudo empezar (código $($proceso.ExitCode))" @('Qué hacer: lee su mensaje de arriba.')
        }
    }
}

# ---------------------------------------------------------------- Resumen

function IpDeLaRedLocal {
    $config = Get-NetIPConfiguration -ErrorAction SilentlyContinue | Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' } | Select-Object -First 1
    if ($config) { return @($config.IPv4Address)[0].IPAddress }
    return $null
}

function MostrarResumen([string]$ipTailscale) {
    $live = $(if (ProcesoEnPuerto $PUERTO_LIVE_SERVER) { 'Live Server está encendido' } else { 'ahora no está encendido' })
    $ipLocal = IpDeLaRedLocal
    $estado = { param($encendido) if ($encendido) { 'encendido' } else { 'APAGADO' } }

    Write-Host ''
    Write-Host '======================================================================'
    Write-Host 'Resumen'
    Write-Host "  Apache ............... $(& $estado (ApacheEncendido))"
    Write-Host "  MySQL (XAMPP) ........ $(& $estado (MysqlEncendido))"
    Write-Host "  Tailscale ............ $(if ($ipTailscale) { "conectado ($ipTailscale)" } else { 'NO CONECTADO' })"
    Write-Host "  Firewall ............. $script:ResumenFirewall"
    Write-Host "  Comprobación previa .. $script:ResumenComprobacion"

    $ip = $(if ($ipTailscale) { $ipTailscale } else { '<IP de Tailscale>' })
    Write-Host ''
    Write-Host 'Para entrar'
    Write-Host "  En la computadora ................ http://localhost$RUTA_WEB"
    if ($ipLocal) { Write-Host "  En el celular, misma WiFi ........ http://$ipLocal$RUTA_WEB" }
    Write-Host "  En el celular, con Tailscale ..... http://$ip$RUTA_WEB"
    Write-Host "  En el celular, con Live Server ... http://${ip}:$PUERTO_LIVE_SERVER/componentes.html"
    Write-Host "                                     abre la carpeta web\ en VS Code y pulsa «Go Live» ($live);"
    Write-Host '                                     para el celular, liveServer.settings.host en 0.0.0.0 (docs/GUIA-EQUIPO.md, 6.3)'
    Write-Host '  Escribe http:// completo: si el navegador lo cambia a https://, no carga.'

    Write-Host ''
    if ($script:Problemas.Count -gt 0) {
        Write-Host 'Falló algo (detalle arriba):' -ForegroundColor Red
        foreach ($linea in $script:Problemas) { Write-Host "  $linea" }
    } else {
        Write-Host 'Todo encendido.' -ForegroundColor Green
    }
}

# ---------------------------------------------------------------- Principal

function Principal {
    Write-Host 'Iniciando los servicios del Sistema de Monitoreo de Red SOA'

    Titulo 'Apache y MySQL (XAMPP)'
    EncenderApache
    EncenderMysql

    Titulo 'Tailscale'
    $ipTailscale = ComprobarTailscale

    Titulo 'Firewall de Windows'
    ComprobarFirewall

    Titulo 'Comprobación previa (herramientas/preparar-demo.php; no cambia tu base)'
    ComprobacionPrevia

    MostrarResumen $ipTailscale
    if ($script:Problemas.Count -eq 0) { return 0 }
    return 1
}

if (-not $SoloFunciones) {
    # Aquí y no arriba: cargado con «. script -SoloFunciones», no cambia la consola de quien lo carga.
    Set-StrictMode -Version 2.0
    # UTF-8 en la consola (✓, ✗, acentos), también si la salida se redirige; al final se restaura.
    $codificacionAnterior = [Console]::OutputEncoding
    [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false
    try {
        $codigo = Principal
    } finally {
        [Console]::OutputEncoding = $codificacionAnterior
    }
    exit $codigo
}
