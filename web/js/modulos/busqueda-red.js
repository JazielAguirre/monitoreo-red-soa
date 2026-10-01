/*
 * "Buscar dispositivos en la red" (pantalla Dispositivos, Fase 9).
 * Pide la red al Servicio de Descubrimiento y escanea sus lotes uno tras otro
 * (POST /descubrimiento/red/lotes/{n}), para mostrar el progreso como
 * "Monitorear todos". La lista se va llenando con cada lote.
 *
 * Cada equipo muestra si respondió al ping o solo apareció en la tabla ARP, su
 * IP, MAC y nombre, y si ya está registrado. "Agregar" lo decide la página
 * (abre el formulario con la IP y el nombre escritos).
 * Los datos de la API se insertan siempre con textContent (piezas.js).
 */

import { api } from '../api.js';
import { formatearMs, pluralizar } from './formatos.js';
import { notificarError } from './notificaciones.js';
import { crearError, crearVacio, mostrarBloque } from './vistas.js';
import { elemento, icono, crearPunto, urlHistorial } from './piezas.js';
import { ponerBotonMonitoreando } from './monitorear-todos.js';

const LONGITUD_MAXIMA_NOMBRE = 100; // la del campo nombre de Dispositivos

/**
 * Nombre que se propone al agregar: la primera parte del nombre DNS
 * ("S25-de-Allan.mshome.net" → "S25-de-Allan"), o "" si no hay.
 */
export function nombreSugerido(equipo) {
    return (equipo.nombre ?? '').split('.')[0].slice(0, LONGITUD_MAXIMA_NOMBRE);
}

/**
 * Datos para prellenar el formulario de agregar.
 */
export function datosParaAgregar(equipo) {
    return {
        nombre: nombreSugerido(equipo),
        ip: equipo.ip,
        tipo: equipo.es_puerta_enlace ? 'router' : 'computadora',
        ubicacion: '',
    };
}

/**
 * Insignia de cómo se encontró: "Responde al ping" (verde) o "Solo en ARP" (gris).
 */
export function crearInsigniaHallazgo(equipo) {
    const responde = equipo.responde_ping;
    const insignia = elemento('span', `insignia-estado${responde ? ' insignia-estado--activo' : ''}`);
    insignia.append(crearPunto(responde ? 'activo' : 'desconocido'), responde ? 'Responde al ping' : 'Solo en ARP');
    insignia.title = responde
        ? 'Respondió al ping'
        : 'No respondió al ping, pero contestó en la red hace poco (tabla ARP): puede bloquear el ping';

    return insignia;
}

/**
 * Marcas "Esta computadora" y "Puerta de enlace" (o null si no aplica).
 */
function crearMarcas(equipo) {
    const marcas = [];
    if (equipo.es_esta_computadora) {
        marcas.push(elemento('span', 'marca-equipo', 'Esta computadora'));
    }
    if (equipo.es_puerta_enlace) {
        marcas.push(elemento('span', 'marca-equipo', 'Puerta de enlace'));
    }

    return marcas;
}

/**
 * Nombre DNS; si se recorta en el proyector, el completo queda en el title.
 */
function crearNombreEquipo(nombre) {
    const nodo = elemento('span', 'equipo-nombre', nombre);
    nodo.title = nombre;

    return nodo;
}

function crearSinDato() {
    const guion = elemento('span', 'texto-tenue', '—');
    guion.setAttribute('aria-label', 'Sin dato');

    return guion;
}

/**
 * "Registrado · Router principal" (enlace al historial) o el botón "Agregar".
 */
export function crearRegistro(equipo, { completo = false, alAgregar = () => {} } = {}) {
    if (equipo.dispositivo) {
        const registrado = elemento('span', 'equipo-registrado');
        const enlace = elemento('a', 'enlace-discreto', equipo.dispositivo.nombre);
        enlace.href = urlHistorial(equipo.dispositivo.id);
        enlace.title = `Ver el historial de ${equipo.dispositivo.nombre}`;
        registrado.append(icono('bi-check2-circle'), elemento('span', 'equipo-registrado__etiqueta', 'Registrado · '), enlace);
        return registrado;
    }

    const boton = elemento('button', completo ? 'btn btn-primary btn-tarjeta' : 'btn btn-primary btn-sm boton-agregar-equipo');
    boton.type = 'button';
    boton.dataset.accion = 'agregar-equipo';
    boton.append(icono('bi-plus-lg'), 'Agregar');
    boton.setAttribute('aria-label', `Agregar ${equipo.nombre ?? equipo.ip}`);
    boton.addEventListener('click', (evento) => alAgregar(equipo, evento.currentTarget));

    return boton;
}

/**
 * Tabla de equipos (escritorio y proyector).
 */
export function crearTablaEquipos(equipos, opciones = {}) {
    const envoltura = elemento('div', 'table-responsive tabla-equipos');
    const tabla = elemento('table', 'table');
    const fila = elemento('tr');

    for (const [texto, clase] of [['Detección'], ['Dirección IP'], ['Nombre', 'col-nombre'], ['MAC', 'col-mac'], ['Respuesta', 'col-respuesta'], ['Registro', 'text-end']]) {
        const th = elemento('th', clase ?? '', texto);
        th.scope = 'col';
        fila.append(th);
    }
    const encabezado = elemento('thead');
    encabezado.append(fila);

    const cuerpo = elemento('tbody');
    cuerpo.append(...equipos.map((equipo) => crearFilaEquipo(equipo, opciones)));
    tabla.append(encabezado, cuerpo);
    envoltura.append(tabla);

    return envoltura;
}

function crearFilaEquipo(equipo, opciones) {
    const fila = elemento('tr');
    fila.dataset.ip = equipo.ip;

    const ip = elemento('div', 'equipo-ip');
    ip.append(elemento('span', 'texto-mono fw-normal', equipo.ip), ...crearMarcas(equipo));

    const celdas = [
        [crearInsigniaHallazgo(equipo), ''],
        [ip, ''],
        [equipo.nombre ? crearNombreEquipo(equipo.nombre) : crearSinDato(), 'col-nombre'],
        [equipo.mac ? elemento('span', 'texto-mono fw-normal', equipo.mac) : crearSinDato(), 'tabla-tenue col-mac'],
        [equipo.responde_ping ? elemento('span', 'texto-mono fw-normal', formatearMs(equipo.tiempo_respuesta_ms)) : crearSinDato(), 'col-respuesta'],
        [crearRegistro(equipo, opciones), 'text-end'],
    ];

    for (const [contenido, clase] of celdas) {
        const td = elemento('td', clase);
        td.append(contenido);
        fila.append(td);
    }

    return fila;
}

/**
 * Tarjeta de un equipo (celular): las mismas piezas que la tarjeta de dispositivo.
 */
export function crearTarjetaEquipo(equipo, opciones = {}) {
    const tarjeta = elemento('article', 'tarjeta-dispositivo-movil tarjeta-equipo');
    tarjeta.dataset.ip = equipo.ip;

    const cabecera = elemento('div', 'tarjeta-dispositivo-movil__cabecera');
    const iconoTarjeta = elemento('span', 'tarjeta-dispositivo-movil__icono');
    iconoTarjeta.append(icono(equipo.es_puerta_enlace ? 'bi-router' : 'bi-pc-display'));
    const textos = elemento('div', 'tarjeta-dispositivo-movil__textos');
    textos.append(
        elemento('span', 'tarjeta-dispositivo-movil__nombre', equipo.nombre ?? 'Sin nombre'),
        elemento('span', 'tarjeta-dispositivo-movil__ip', equipo.ip),
    );
    cabecera.append(iconoTarjeta, textos, crearInsigniaHallazgo(equipo));

    const datos = elemento('dl', 'tarjeta-dispositivo-movil__datos tarjeta-equipo__datos');
    const agregarDato = (etiqueta, valor) => {
        const grupo = elemento('div');
        const dd = elemento('dd');
        dd.append(valor);
        grupo.append(elemento('dt', '', etiqueta), dd);
        datos.append(grupo);
    };
    agregarDato('MAC', equipo.mac ? elemento('span', 'mono', equipo.mac) : crearSinDato());
    agregarDato('Respuesta', equipo.responde_ping ? elemento('span', 'mono', formatearMs(equipo.tiempo_respuesta_ms)) : crearSinDato());

    const pie = elemento('div', 'tarjeta-equipo__pie');
    const marcas = crearMarcas(equipo);
    if (marcas.length > 0) {
        const grupo = elemento('div', 'tarjeta-equipo__marcas');
        grupo.append(...marcas);
        pie.append(grupo);
    }
    pie.append(crearRegistro(equipo, { ...opciones, completo: true }));

    tarjeta.append(cabecera, datos, pie);

    return tarjeta;
}

/**
 * Panel de búsqueda dentro de `contenedor` (oculto hasta la primera búsqueda).
 * opciones: boton (el que inicia la búsqueda), alAgregar(equipo, boton).
 * Devuelve { buscar(), sincronizarRegistrados(dispositivos), cerrar() }.
 */
export function crearBusquedaRed(contenedor, { boton, alAgregar }) {
    const estado = {
        red: null,
        equipos: [],
        buscando: false,
        loteActual: 0,
        terminado: false,
        error: null,
    };

    // ---------------------------------------------------------------- Estructura

    const panel = elemento('section', 'tabla-tarjeta panel-busqueda');
    panel.setAttribute('aria-labelledby', 'titulo-busqueda');

    const cabecera = elemento('div', 'panel-busqueda__cabecera');
    const textos = elemento('div', 'panel-busqueda__textos');
    const titulo = elemento('h2', 'panel-busqueda__titulo');
    titulo.id = 'titulo-busqueda';
    titulo.append(icono('bi-radar'), 'Equipos en la red');
    const subtitulo = elemento('p', 'panel-busqueda__subtitulo');
    textos.append(titulo, subtitulo);

    const acciones = elemento('div', 'panel-busqueda__acciones');
    const repetir = elemento('button', 'btn btn-secondary btn-sm');
    repetir.type = 'button';
    // En celular, solo el ícono (el texto sigue para lectores de pantalla).
    repetir.append(icono('bi-arrow-clockwise'), elemento('span', 'd-none d-sm-inline', 'Buscar de nuevo'), elemento('span', 'visually-hidden d-sm-none', 'Buscar de nuevo'));
    repetir.addEventListener('click', () => buscar());
    const cerrar = elemento('button', 'modal-formulario__cerrar panel-busqueda__cerrar');
    cerrar.type = 'button';
    cerrar.setAttribute('aria-label', 'Cerrar la búsqueda');
    cerrar.append(icono('bi-x-lg'));
    cerrar.addEventListener('click', () => cerrarPanel());
    acciones.append(repetir, cerrar);
    cabecera.append(textos, acciones);

    const progreso = elemento('div', 'panel-busqueda__progreso');
    const progresoTexto = elemento('div', 'progreso-monitoreo__cabecera');
    const actual = elemento('span', 'progreso-monitoreo__actual');
    const posicion = elemento('span', 'progreso-monitoreo__detalle');
    progresoTexto.append(actual, posicion);
    const barra = elemento('div', 'progreso-monitoreo__barra');
    barra.setAttribute('role', 'progressbar');
    barra.setAttribute('aria-label', 'Progreso de la búsqueda en la red');
    barra.setAttribute('aria-valuemin', '0');
    const relleno = elemento('div', 'progreso-monitoreo__relleno');
    barra.append(relleno);
    progreso.append(progresoTexto, barra);

    const contenido = elemento('div', 'panel-busqueda__contenido');
    contenido.id = 'contenido-busqueda';
    const anuncio = elemento('p', 'visually-hidden');
    anuncio.setAttribute('aria-live', 'polite');

    const pie = elemento('div', 'pie-tabla');
    const nota = elemento('span', 'pie-tabla__nota');
    nota.append(icono('bi-shield-check'), 'Solo busca en la red de esta computadora. Úsalo en redes propias o con permiso.');
    pie.append(nota);

    panel.append(cabecera, progreso, contenido, anuncio, pie);
    contenedor.replaceChildren(panel);
    contenedor.hidden = true;

    // ---------------------------------------------------------------- Búsqueda

    async function buscar() {
        if (estado.buscando) {
            return;
        }

        Object.assign(estado, { red: null, equipos: [], buscando: true, loteActual: 0, terminado: false, error: null });
        contenedor.hidden = false;
        render();
        panel.scrollIntoView({ block: 'nearest' });

        try {
            estado.red = await api.descubrimiento.red();
            for (let lote = 1; lote <= estado.red.lotes; lote++) {
                estado.loteActual = lote;
                render();
                const resultado = await api.descubrimiento.escanearLote(lote);
                estado.equipos.push(...resultado.encontrados);
            }
            estado.terminado = true;
        } catch (error) {
            estado.error = error;
            notificarError(error, 'No se pudo buscar en la red');
        } finally {
            estado.buscando = false;
            render();
        }
    }

    function cerrarPanel() {
        if (estado.buscando) {
            return;
        }
        contenedor.hidden = true;
        boton.focus();
    }

    /**
     * Actualiza "Registrado" con la lista de dispositivos de la página (después de
     * agregar, editar o eliminar), sin volver a escanear.
     */
    function sincronizarRegistrados(dispositivos) {
        if (estado.equipos.length === 0) {
            return;
        }
        const porIp = new Map(dispositivos.map((d) => [d.ip, { id: d.id, nombre: d.nombre }]));
        estado.equipos.forEach((equipo) => { equipo.dispositivo = porIp.get(equipo.ip) ?? null; });
        render();
    }

    // ---------------------------------------------------------------- Render

    function render() {
        const sinRegistrar = estado.equipos.filter((e) => !e.dispositivo).length;
        const red = estado.red?.red ?? 'la red local';

        if (estado.buscando) {
            subtitulo.textContent = `Buscando en ${red}…`;
        } else if (estado.error) {
            subtitulo.textContent = `La búsqueda en ${red} no terminó`;
        } else {
            subtitulo.textContent = `${red} · ${pluralizar(estado.equipos.length, 'equipo encontrado', 'equipos encontrados')} · ${sinRegistrar} sin registrar`;
        }

        repetir.disabled = estado.buscando;
        cerrar.disabled = estado.buscando;
        ponerBotonMonitoreando(boton, estado.buscando ? textoBoton() : null, 'girador girador--oscuro');
        renderProgreso();
        renderContenido();
    }

    function textoBoton() {
        return estado.loteActual > 0 && estado.red ? `Buscando ${estado.loteActual} de ${estado.red.lotes}…` : 'Buscando…';
    }

    function renderProgreso() {
        progreso.hidden = !estado.buscando;
        if (!estado.buscando) {
            return;
        }

        const total = estado.red?.lotes ?? 1;
        const hechos = Math.max(0, estado.loteActual - 1);
        const textoLote = estado.loteActual > 0 ? `Lote ${estado.loteActual} de ${total}` : 'Preparando';

        actual.replaceChildren(crearPunto('monitoreando'), estado.red ? `Enviando ping a ${estado.red.red}` : 'Detectando la red de esta computadora');
        posicion.textContent = `${textoLote} · ${pluralizar(estado.equipos.length, 'encontrado', 'encontrados')}`;
        relleno.style.setProperty('--progreso', `${Math.round((hechos / total) * 100)}%`);
        barra.setAttribute('aria-valuemax', String(total));
        barra.setAttribute('aria-valuenow', String(hechos));
        barra.setAttribute('aria-valuetext', textoLote);
        anuncio.textContent = estado.loteActual > 0 ? `Buscando en la red, ${textoLote.toLowerCase()}` : 'Buscando en la red';
    }

    function renderContenido() {
        const opciones = { alAgregar };

        if (estado.error) {
            anuncio.textContent = 'La búsqueda en la red falló';
            mostrarBloque(contenido, crearError({
                titulo: 'No se pudo buscar en la red',
                mensaje: estado.error.message,
                alReintentar: buscar,
                compacto: true,
            }));
            return;
        }

        if (estado.equipos.length === 0 && estado.buscando) {
            contenido.setAttribute('aria-busy', 'true');
            contenido.replaceChildren(crearEsqueleto());
            return;
        }

        if (estado.equipos.length === 0) {
            anuncio.textContent = 'No se encontró ningún equipo en la red';
            mostrarBloque(contenido, crearVacio({
                icono: 'bi-radar',
                titulo: 'No se encontró ningún equipo',
                texto: 'Revisa que la computadora y los equipos estén conectados a la misma red y vuelve a buscar.',
                accion: { texto: 'Buscar de nuevo', icono: 'bi-arrow-clockwise', alHacerClic: buscar },
                compacto: true,
            }));
            return;
        }

        const tarjetas = elemento('div', 'lista-tarjetas-equipo');
        tarjetas.append(...estado.equipos.map((equipo) => crearTarjetaEquipo(equipo, opciones)));
        contenido.replaceChildren(crearTablaEquipos(estado.equipos, opciones), tarjetas);
        if (estado.buscando) {
            contenido.setAttribute('aria-busy', 'true');
        } else {
            contenido.removeAttribute('aria-busy');
            anuncio.textContent = `Búsqueda terminada: ${subtitulo.textContent}`;
        }
    }

    return { buscar, sincronizarRegistrados, cerrar: cerrarPanel };
}

/**
 * Esqueleto mientras llega el primer lote.
 */
function crearEsqueleto() {
    const lista = elemento('div', 'esqueleto-lista');
    lista.setAttribute('aria-hidden', 'true');
    lista.append(elemento('div', 'esqueleto-tabla__encabezado'));
    for (const ancho of ['55%', '70%', '45%']) {
        const fila = elemento('div', 'esqueleto-tabla__fila');
        const texto = elemento('span', 'esqueleto');
        texto.style.width = ancho;
        fila.append(elemento('span', 'esqueleto esqueleto--suave esqueleto--pastilla'), texto, elemento('span', 'esqueleto esqueleto--suave'), elemento('span', 'esqueleto esqueleto--suave'));
        lista.append(fila);
    }

    return lista;
}
