#!/usr/bin/env bash
#
# Enciende lo que necesita la aplicación después de reiniciar la computadora, también
# para entrar desde el celular por Tailscale (desde otra red). Solo Linux con XAMPP.
#
#   sudo bash herramientas/iniciar-servicios.sh
#
# 1. Apache y MySQL de XAMPP: los enciende solo si no están encendidos.
# 2. Tailscale: comprueba que tailscaled esté activo y Tailscale conectado; si no lo
#    está, ejecuta tailscale up (si pide iniciar sesión, muestra el enlace y espera
#    hasta 60 s). Comprueba también si ufw permite el tráfico de tailscale0: si no,
#    muestra el comando para permitirlo, pero no lo ejecuta.
# 3. Con tu usuario (SUDO_USER), no como root, la comprobación previa de
#    herramientas/preparar-demo.php sin opciones: no cambia tu base.
# 4. Resumen: IP de Tailscale, tailscale status y las direcciones para entrar.
#
# Nunca se queda esperando: cada paso tiene un tope de tiempo y, si algo falla, dice
# qué falló y qué hacer. Termina con 0 si todo quedó encendido y 1 si algo falló.

set -u

readonly XAMPP=/opt/lampp
readonly LAMPP=$XAMPP/lampp
readonly PHP=$XAMPP/bin/php
DIR_PROYECTO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
readonly DIR_PROYECTO
readonly RUTA_WEB=/monitoreo-red-soa/web/
readonly PUERTO_LIVE_SERVER=5500
readonly ESPERA_APACHE=10        # segundos para que Apache termine de encender
readonly ESPERA_MYSQL=30         # lampp startmysql lo enciende en segundo plano
readonly ESPERA_TAILSCALE=60     # para iniciar sesión con el enlace de tailscale up
readonly ESPERA_COMPROBACION=90  # preparar-demo.php hace pings y peticiones con tope propio
readonly COMANDO_UFW='sudo ufw allow in on tailscale0'

PROBLEMAS=()            # cada ✗ con su «Qué hacer», para repetirlos en el resumen
UFW_RESUMEN='sin revisar'
COMPROBACION='sin ejecutar'
ARCHIVO_TEMPORAL=''

# ---------------------------------------------------------------- Mensajes

titulo() { printf '\n%s\n' "$1"; }

bien() { printf '  ✓ %s\n' "$1"; }

# ✗ con sus líneas de «Qué hacer»: cuenta como fallo y se repite en el resumen.
mal() {
    local linea
    printf '  ✗ %s\n' "$1"
    PROBLEMAS+=("✗ $1")
    shift
    for linea in "$@"; do
        printf '      %s\n' "$linea"
        PROBLEMAS+=("    $linea")
    done
}

# ⚠ solo avisa: no cuenta como fallo.
aviso() {
    local linea
    printf '  ⚠ %s\n' "$1"
    shift
    for linea in "$@"; do
        printf '      %s\n' "$linea"
    done
}

con_sangria() { sed 's/^/        /'; }

# ---------------------------------------------------------------- Sudo

comprobar_que_es_sudo() {
    if [[ $EUID -ne 0 ]]; then
        echo 'Este script necesita sudo (enciende Apache, MySQL y Tailscale). Ejecútalo así:' >&2
        echo '  sudo bash herramientas/iniciar-servicios.sh' >&2
        exit 1
    fi
    if [[ -z ${SUDO_USER:-} || $SUDO_USER == root ]]; then
        echo 'Ejecútalo con sudo desde tu usuario normal, no desde una sesión de root:' >&2
        echo '  sudo bash herramientas/iniciar-servicios.sh' >&2
        echo '(la comprobación previa corre con tu usuario, el de SUDO_USER).' >&2
        exit 1
    fi
    if [[ ! -x $LAMPP || ! -x $PHP ]]; then
        echo "No se encontró XAMPP en $XAMPP: este script es para Linux con XAMPP (README, «Instalación en Linux»)." >&2
        exit 1
    fi
}

# ---------------------------------------------------------------- Apache y MySQL

# true si el Apache o el MySQL de XAMPP (APACHE o MYSQL) está encendido. Lo decide
# «lampp statusraw» con su archivo .pid y su proceso: otro MySQL en el puerto 3306 no cuenta.
xampp_encendido() {
    local estado
    estado=$(timeout 10 "$LAMPP" statusraw 2>/dev/null)
    grep -qx "$1 RUNNING" <<<"$estado"
}

esperar_xampp() {
    local servicio=$1 segundos=$2 i
    for ((i = 0; i < segundos; i++)); do
        xampp_encendido "$servicio" && return 0
        sleep 1
    done
    xampp_encendido "$servicio"
}

puerto_ocupado() {
    [[ -n $(ss -ltnH "sport = :$1" 2>/dev/null) ]]
}

# Nombre del contenedor de Docker que publica ese puerto en la computadora, o nada.
contenedor_en_el_puerto() {
    command -v docker >/dev/null 2>&1 || return 0
    timeout 5 docker ps --format '{{.Names}}|{{.Ports}}' 2>/dev/null | grep -E ":$1->" | head -n 1 | cut -d '|' -f 1
}

quien_usa_el_puerto() {
    local contenedor programa
    contenedor=$(contenedor_en_el_puerto "$1")
    if [[ -n $contenedor ]]; then
        echo "el contenedor de Docker $contenedor"
        return
    fi
    programa=$(ss -ltnpH "sport = :$1" 2>/dev/null | sed -n 's/.*users:(("\([^"]*\)".*/\1/p' | head -n 1)
    echo "${programa:+el programa $programa}${programa:-otro programa}"
}

# «Qué hacer» cuando otro programa tiene el puerto (servicio_del_sistema es un ejemplo).
que_hacer_con_el_puerto() {
    local puerto=$1 servicio_del_sistema=$2 contenedor
    contenedor=$(contenedor_en_el_puerto "$puerto")
    if [[ -n $contenedor ]]; then
        echo "Qué hacer: detén ese contenedor (docker stop $contenedor) y vuelve a ejecutar este script."
        echo "Si no lo necesitas al reiniciar, para que no vuelva a encenderse solo: docker update --restart=no $contenedor"
    else
        echo "Qué hacer: detén ese programa (por ejemplo: sudo systemctl stop $servicio_del_sistema) y vuelve a ejecutar este script."
    fi
}

encender_apache() {
    local pasos
    if xampp_encendido APACHE; then
        bien 'Apache ya estaba encendido'
        return
    fi
    if puerto_ocupado 80; then
        mapfile -t pasos < <(que_hacer_con_el_puerto 80 apache2)
        mal "Apache no se puede encender: el puerto 80 lo ocupa $(quien_usa_el_puerto 80)" "${pasos[@]}"
        return
    fi

    "$LAMPP" startapache >"$ARCHIVO_TEMPORAL" 2>&1
    if esperar_xampp APACHE "$ESPERA_APACHE"; then
        bien 'Apache encendido'
        return
    fi
    con_sangria <"$ARCHIVO_TEMPORAL"
    echo "      Últimas líneas de $XAMPP/logs/error_log:"
    tail -n 5 "$XAMPP/logs/error_log" 2>/dev/null | con_sangria
    mal 'Apache no encendió' 'Qué hacer: lee el mensaje de XAMPP y el registro de arriba (README, «Problemas comunes»).'
}

encender_mysql() {
    local pasos registro
    if xampp_encendido MYSQL; then
        bien 'MySQL ya estaba encendido'
        return
    fi
    if puerto_ocupado 3306; then
        mapfile -t pasos < <(que_hacer_con_el_puerto 3306 mysql)
        mal "MySQL de XAMPP no se puede encender: el puerto 3306 lo ocupa $(quien_usa_el_puerto 3306)" "${pasos[@]}" \
            'Mientras ese MySQL esté encendido, la aplicación no puede usar tu base.'
        return
    fi

    # Con pam_tmpdir, sudo deja TMPDIR=/tmp/user/0 y MariaDB no puede escribir ahí (README, paso 4).
    TMPDIR=/tmp "$LAMPP" startmysql >"$ARCHIVO_TEMPORAL" 2>&1
    if esperar_xampp MYSQL "$ESPERA_MYSQL"; then
        bien 'MySQL encendido'
        return
    fi
    registro="$XAMPP/var/mysql/$(hostname).err"
    con_sangria <"$ARCHIVO_TEMPORAL"
    echo "      Últimas líneas de $registro:"
    tail -n 8 "$registro" 2>/dev/null | con_sangria
    mal "MySQL no encendió en $ESPERA_MYSQL s" \
        "Qué hacer: lee el registro de arriba. Si habla de la carpeta temporal, agrega tmpdir = /tmp debajo de [mysqld] en $XAMPP/etc/my.cnf (README, «Instalación en Linux», paso 4)."
}

# ---------------------------------------------------------------- Tailscale y ufw

# Running, Stopped, NeedsLogin, NeedsMachineAuth, Starting o NoState; vacío si
# tailscaled no contesta.
estado_tailscale() {
    timeout 10 tailscale status --json 2>/dev/null | sed -n 's/^ *"BackendState": *"\([^"]*\)".*/\1/p' | head -n 1
}

# El enlace de inicio de sesión que espera tailscaled, si hay uno.
enlace_de_inicio_de_sesion() {
    timeout 10 tailscale status --json 2>/dev/null | sed -n 's/^ *"AuthURL": *"\([^"]*\)".*/\1/p' | head -n 1
}

ip_tailscale() {
    timeout 10 tailscale ip -4 2>/dev/null | head -n 1
}

# El estado, después de esperar hasta 10 s si tailscaled todavía está arrancando.
esperar_estado_tailscale() {
    local estado i
    for ((i = 0; i < 10; i++)); do
        estado=$(estado_tailscale)
        [[ $estado != '' && $estado != NoState && $estado != Starting ]] && break
        sleep 1
    done
    echo "$estado"
}

conectar_tailscale() {
    local estado enlace
    if ! command -v tailscale >/dev/null 2>&1; then
        mal 'Tailscale no está instalado' 'Qué hacer: instálalo (tailscale.com/download/linux), inicia sesión y vuelve a ejecutar este script.'
        return
    fi
    if systemctl is-active --quiet tailscaled; then
        bien 'tailscaled está activo'
    elif timeout 20 systemctl start tailscaled && systemctl is-active --quiet tailscaled; then
        bien 'tailscaled no estaba activo: se encendió'
    else
        mal 'tailscaled no está activo y no se pudo encender' \
            'Qué hacer: sudo systemctl enable --now tailscaled; si falla, mira el motivo con journalctl -u tailscaled -n 20'
        return
    fi

    estado=$(esperar_estado_tailscale)
    if [[ $estado == Running ]]; then
        bien "Tailscale está conectado ($(ip_tailscale))"
        return
    fi
    if [[ $estado != NeedsMachineAuth ]]; then
        echo "  Tailscale no está conectado (${estado:-sin estado}): se ejecuta tailscale up."
        echo "  Si pide iniciar sesión, abre el enlace que aparece abajo; se espera hasta $ESPERA_TAILSCALE s."
        timeout --foreground "$ESPERA_TAILSCALE" tailscale up
        estado=$(esperar_estado_tailscale)
    fi

    case $estado in
        Running)
            bien "Tailscale conectado ($(ip_tailscale))"
            ;;
        NeedsLogin)
            enlace=$(enlace_de_inicio_de_sesion)
            mal 'Falta iniciar sesión en Tailscale' "Qué hacer: abre ${enlace:-el enlace de arriba}, inicia sesión y vuelve a ejecutar este script."
            ;;
        NeedsMachineAuth)
            mal 'Tailscale espera que se apruebe esta computadora' \
                'Qué hacer: apruébala en login.tailscale.com/admin/machines y vuelve a ejecutar este script.'
            ;;
        *)
            mal "Tailscale no se conectó (estado: ${estado:-sin estado})" \
                'Qué hacer: lee el mensaje de tailscale up de arriba; si sugiere otro comando, ejecútalo con sudo y vuelve a ejecutar este script.'
            ;;
    esac
}

# Desde esta misma computadora la petición no pasa por ufw (eso se revisa aparte):
# confirma que Apache escucha también en la IP de Tailscale, no solo en localhost.
comprobar_apache_en_tailscale() {
    local ip codigo
    ip=$(ip_tailscale)
    [[ -n $ip ]] && xampp_encendido APACHE || return 0
    codigo=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://$ip${RUTA_WEB}login.html")
    if [[ $codigo == 200 ]]; then
        bien "Apache responde en la IP de Tailscale (http://$ip$RUTA_WEB)"
    else
        aviso "Apache no responde en la IP de Tailscale (HTTP $codigo)" \
            "Revisa que $XAMPP/etc/httpd.conf diga «Listen 80» y no «Listen 127.0.0.1:80»."
    fi
}

# Lee la salida de «LC_ALL=C ufw status verbose». Devuelve 0 y dice por qué si ufw deja
# entrar todo lo que llega por tailscale0.
ufw_permite_tailscale0() {
    if grep -q '^Status: inactive' <<<"$1"; then
        echo 'ufw está inactivo: no bloquea tailscale0'
    elif grep -q '^Default: allow (incoming)' <<<"$1"; then
        echo 'ufw deja entrar todo por defecto, también por tailscale0'
    elif grep -qE '^Anywhere on tailscale0[[:space:]]+ALLOW IN' <<<"$1"; then
        echo 'ufw permite el tráfico de tailscale0'
    else
        return 1
    fi
}

comprobar_ufw() {
    local salida motivo
    if ! command -v ufw >/dev/null 2>&1; then
        bien 'ufw no está instalado: no bloquea tailscale0'
        UFW_RESUMEN='no está instalado'
        return
    fi
    if ! salida=$(LC_ALL=C timeout 10 ufw status verbose 2>&1); then
        aviso 'No se pudo leer el estado de ufw' "$salida"
        UFW_RESUMEN='no se pudo revisar'
        return
    fi
    if motivo=$(ufw_permite_tailscale0 "$salida"); then
        bien "$motivo"
        UFW_RESUMEN='permite tailscale0'
    else
        aviso 'ufw está activo y no tiene una regla que permita el tráfico de tailscale0' \
            "Sin ella, el celular puede no cargar la página (puertos 80 y $PUERTO_LIVE_SERVER)." \
            "Para permitirlo (este script no lo ejecuta): $COMANDO_UFW"
        UFW_RESUMEN="falta permitir tailscale0: $COMANDO_UFW"
    fi
}

# ---------------------------------------------------------------- Comprobación previa

comprobacion_previa() {
    local codigo
    if ! xampp_encendido MYSQL; then
        aviso 'Se omitió: el MySQL de XAMPP no está encendido' \
            'Sin él, preparar-demo.php fallaría o leería otro MySQL del puerto 3306. Resuelve lo de MySQL y vuelve a ejecutar este script.'
        COMPROBACION='omitida (el MySQL de XAMPP no está encendido)'
        return
    fi

    # --foreground: sudo queda en primer plano de la terminal y no se detiene al usarla.
    (cd "$DIR_PROYECTO" && timeout --foreground -k 5 "$ESPERA_COMPROBACION" \
        sudo -u "$SUDO_USER" -H -- "$PHP" herramientas/preparar-demo.php </dev/null)
    codigo=$?
    case $codigo in
        0) COMPROBACION='sin ✗' ;;
        1) COMPROBACION='con ✗: lee «Qué hacer» en la comprobación previa de arriba' ;;
        124 | 137)
            COMPROBACION="no terminó en $ESPERA_COMPROBACION s"
            mal "preparar-demo.php no terminó en $ESPERA_COMPROBACION s" \
                "Qué hacer: córrelo a mano para ver dónde se queda: $PHP herramientas/preparar-demo.php"
            ;;
        *)
            COMPROBACION="no pudo empezar (código $codigo)"
            mal "preparar-demo.php no pudo empezar (código $codigo)" 'Qué hacer: lee su mensaje de arriba.'
            ;;
    esac
}

# ---------------------------------------------------------------- Resumen

estado_en_palabras() {
    xampp_encendido "$1" && echo 'encendido' || echo 'APAGADO'
}

mostrar_resumen() {
    local ip estado_ts live
    ip=$(ip_tailscale)
    estado_ts=$(estado_tailscale)
    puerto_ocupado "$PUERTO_LIVE_SERVER" && live='Live Server está encendido' || live='ahora no está encendido'

    echo
    echo '======================================================================'
    echo 'Resumen'
    echo "  Apache ............... $(estado_en_palabras APACHE)"
    echo "  MySQL (XAMPP) ........ $(estado_en_palabras MYSQL)"
    echo "  Tailscale ............ $([[ $estado_ts == Running ]] && echo conectado || echo "NO CONECTADO (${estado_ts:-tailscaled no contesta})")"
    echo "  ufw .................. $UFW_RESUMEN"
    echo "  Comprobación previa .. $COMPROBACION"

    echo
    echo "IP de Tailscale de esta computadora: ${ip:-(ninguna: Tailscale no está conectado)}"

    echo
    echo 'tailscale status'
    if command -v tailscale >/dev/null 2>&1; then
        timeout 10 tailscale status 2>&1 | sed 's/^/  /'
    else
        echo '  (Tailscale no está instalado)'
    fi

    ip=${ip:-<IP de Tailscale>}
    echo
    echo 'Para entrar'
    echo "  En la computadora ................ http://localhost$RUTA_WEB"
    echo "  En el celular, con Apache ........ http://$ip$RUTA_WEB"
    echo "  En el celular, con Live Server ... http://$ip:$PUERTO_LIVE_SERVER/componentes.html"
    echo "                                     abre la carpeta web/ en VS Code y pulsa «Go Live» ($live)"

    echo
    if ((${#PROBLEMAS[@]} > 0)); then
        echo 'Falló algo (detalle arriba):'
        printf '  %s\n' "${PROBLEMAS[@]}"
    else
        echo 'Todo encendido.'
    fi
}

# ---------------------------------------------------------------- Principal

principal() {
    comprobar_que_es_sudo
    ARCHIVO_TEMPORAL=$(mktemp)
    trap 'rm -f "$ARCHIVO_TEMPORAL"' EXIT

    echo 'Iniciando los servicios del Sistema de Monitoreo de Red SOA'

    titulo 'Apache y MySQL (XAMPP)'
    encender_apache
    encender_mysql

    titulo 'Tailscale'
    conectar_tailscale
    comprobar_apache_en_tailscale
    comprobar_ufw

    titulo "Comprobación previa (herramientas/preparar-demo.php con el usuario $SUDO_USER; no cambia tu base)"
    comprobacion_previa

    mostrar_resumen
    ((${#PROBLEMAS[@]} == 0)) && exit 0
    exit 1
}

# Con «source» (para probar las funciones sin sudo) solo se cargan las funciones.
if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    principal "$@"
fi
