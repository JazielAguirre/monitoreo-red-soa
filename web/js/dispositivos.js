/*
 * Pantalla Dispositivos (docs/diseno: 2a–2f; API.md: "Qué usa cada pantalla").
 * - Filtros por estado (con contadores), tipo y búsqueda, todo en el navegador.
 * - Tabla en escritorio y tarjetas en celular (se generan las dos; CSS muestra una).
 * - Monitorear una fila o todos, agregar, editar y eliminar (con confirmación).
 * Los datos de la API se insertan siempre con textContent (modulos/piezas.js).
 */

import { api } from './api.js';
import { montarEstructura, actualizarContadorAlertas } from './modulos/estructura.js';
import { formatearFechaCorta, formatearMs, normalizarBusqueda, pluralizar } from './modulos/formatos.js';
import { mostrarNotificacion, notificarError } from './modulos/notificaciones.js';
import { crearError, crearVacio, mostrarBloque } from './modulos/vistas.js';
import { elemento, icono, crearInsigniaEstado, crearTipo, iconoTipo, urlHistorial } from './modulos/piezas.js';
import { crearProgreso, monitorearTodos, notificarResumen, ponerBotonMonitoreando } from './modulos/monitorear-todos.js';
import { abrirFormulario } from './modulos/formulario-dispositivo.js';
import { abrirModal, cerrarModal } from './modulos/modales.js';

/**
 * Botón visible (tabla o tarjeta) de una acción de un dispositivo, para devolverle el foco.
 */
function botonVisible(id, accion) {
    return [...document.querySelectorAll(`[data-id="${id}"] [data-accion="${accion}"]`)].find((b) => b.offsetParent !== null) ?? document.getElementById('agregar-dispositivo');
}

const estado = {
    dispositivos: [],
    cargado: false,
    filtros: { texto: '', estado: 'todos', tipo: 'todos' },
    monitoreando: new Set(), // ids con un ping en curso
    corriendo: false, // "Monitorear todos" en curso
    eliminar: null, // dispositivo a eliminar
};

const $ = (id) => document.getElementById(id);
const contenido = $('contenido-dispositivos');
const botonesTodos = [$('monitorear-todos-escritorio'), $('monitorear-todos-movil')];
const progreso = crearProgreso($('progreso-monitoreo'));

montarEstructura();
conectarControles();
cargar();

// ------------------------------------------------------------ Carga

async function cargar() {
    try {
        estado.dispositivos = await api.dispositivos.listar();
        estado.cargado = true;
        render();
    } catch (error) {
        estado.cargado = false;
        $('pie-dispositivos').hidden = true;
        $('subtitulo-dispositivos').textContent = 'No se pudieron cargar los dispositivos';
        botonesTodos.forEach((b) => { b.disabled = true; });
        mostrarBloque(contenido, crearError({
            titulo: 'No se pudieron cargar los dispositivos',
            mensaje: error.message,
            alReintentar: reintentar,
            compacto: true,
        }));
    }
}

function reintentar() {
    contenido.setAttribute('aria-busy', 'true');
    contenido.replaceChildren(elemento('p', 'texto-secundario p-4 m-0', 'Cargando dispositivos…'));
    cargar();
}

// ------------------------------------------------------------ Filtros

function filtrar() {
    const texto = normalizarBusqueda(estado.filtros.texto);

    return estado.dispositivos.filter((d) => {
        const coincideTexto = texto === '' || normalizarBusqueda(d.nombre).includes(texto) || d.ip.includes(texto);
        const coincideEstado = estado.filtros.estado === 'todos' || d.estado === estado.filtros.estado;
        const coincideTipo = estado.filtros.tipo === 'todos' || d.tipo === estado.filtros.tipo;

        return coincideTexto && coincideEstado && coincideTipo;
    });
}

function hayFiltros() {
    return estado.filtros.texto.trim() !== '' || estado.filtros.estado !== 'todos' || estado.filtros.tipo !== 'todos';
}

function limpiarFiltros() {
    estado.filtros = { texto: '', estado: 'todos', tipo: 'todos' };
    $('buscar-dispositivo').value = '';
    $('filtro-tipo').value = 'todos';
    $('estado-todos').checked = true;
    render();
}

function conectarControles() {
    $('buscar-dispositivo').addEventListener('input', (e) => { estado.filtros.texto = e.target.value; render(); });
    $('filtro-tipo').addEventListener('change', (e) => { estado.filtros.tipo = e.target.value; render(); });
    $('filtro-estado').addEventListener('change', (e) => { estado.filtros.estado = e.target.value; render(); });
    $('limpiar-filtros').addEventListener('click', limpiarFiltros);
    $('agregar-dispositivo').addEventListener('click', (e) => abrirFormulario({ modo: 'agregar', alGuardar, origen: e.currentTarget }));
    botonesTodos.forEach((b) => b.addEventListener('click', monitorearTodosLosDispositivos));
    $('confirmar-eliminar').addEventListener('click', confirmarEliminar);
    $('modal-eliminar').addEventListener('hidden.bs.modal', () => { estado.eliminar = null; });
}

// ------------------------------------------------------------ Render

function render() {
    if (!estado.cargado) {
        return;
    }

    const total = estado.dispositivos.length;
    const visibles = filtrar();

    $('subtitulo-dispositivos').textContent = `${pluralizar(total, 'dispositivo registrado', 'dispositivos registrados')}`;
    actualizarContadores();
    $('limpiar-filtros').hidden = !hayFiltros();
    botonesTodos.forEach((b) => { b.disabled = estado.corriendo || total === 0; });

    const pie = $('pie-dispositivos');
    pie.hidden = total === 0;
    $('texto-pie-dispositivos').textContent = `Mostrando ${visibles.length} de ${pluralizar(total, 'dispositivo', 'dispositivos')}`;

    if (total === 0) {
        mostrarBloque(contenido, crearVacio({
            icono: 'bi-hdd-network',
            titulo: 'Aún no hay dispositivos',
            texto: 'Registra el primer equipo de la red para empezar a monitorearlo.',
            accion: { texto: 'Agregar dispositivo', icono: 'bi-plus-lg', clase: 'btn btn-primary btn-sm', alHacerClic: () => $('agregar-dispositivo').click() },
            compacto: true,
        }));
        return;
    }

    if (visibles.length === 0) {
        mostrarBloque(contenido, crearVacio({
            icono: 'bi-search',
            titulo: 'Ningún dispositivo coincide',
            texto: 'Prueba con otro nombre, IP o filtro.',
            accion: { texto: 'Limpiar filtros', alHacerClic: limpiarFiltros },
            compacto: true,
        }));
        return;
    }

    const tabla = crearTabla(visibles);
    const tarjetas = elemento('div', 'lista-tarjetas-dispositivo');
    tarjetas.append(...visibles.map(crearTarjetaMovil));

    contenido.replaceChildren(tabla, tarjetas);
    contenido.removeAttribute('aria-busy');
}

function actualizarContadores() {
    const cuentas = { todos: estado.dispositivos.length, activo: 0, inactivo: 0, desconocido: 0 };
    estado.dispositivos.forEach((d) => { cuentas[d.estado] = (cuentas[d.estado] ?? 0) + 1; });

    document.querySelectorAll('[data-cuenta]').forEach((nodo) => {
        nodo.textContent = String(cuentas[nodo.dataset.cuenta] ?? 0);
    });
}

/**
 * Estado que se muestra: "monitoreando" mientras hay un ping en curso.
 */
function estadoVisible(d) {
    return estado.monitoreando.has(d.id) ? 'monitoreando' : d.estado;
}

function crearRespuesta(d, clase, corta = false) {
    const visible = estadoVisible(d);

    if (visible === 'monitoreando') {
        return elemento('span', `${clase} texto-tenue`, corta ? 'Enviando…' : 'Enviando ping…');
    }
    if (d.estado === 'inactivo') {
        const sin = elemento('span', `${clase} texto-error`, corta ? 'Sin resp.' : 'Sin respuesta');
        if (corta) {
            sin.setAttribute('aria-label', 'Sin respuesta');
        }
        return sin;
    }
    if (d.estado === 'desconocido') {
        const guion = elemento('span', `${clase} texto-tenue`, '—');
        guion.setAttribute('aria-label', 'Sin datos');
        return guion;
    }
    return elemento('span', clase, formatearMs(d.ultimo_tiempo_ms));
}

function crearNombre(d, clase) {
    const enlace = elemento('a', `${clase} enlace-discreto`, d.nombre);
    enlace.href = urlHistorial(d.id);
    enlace.title = `Ver el historial de ${d.nombre}`;

    return enlace;
}

function crearTabla(visibles) {
    const envoltura = elemento('div', 'table-responsive tabla-dispositivos');
    const tabla = elemento('table', 'table table-hover');
    const encabezado = elemento('thead');
    const fila = elemento('tr');

    for (const [texto, clase] of [['Estado'], ['Nombre'], ['Dirección IP'], ['Tipo'], ['Ubicación'], ['Respuesta'], ['Comprobado'], ['Acciones', 'text-end']]) {
        const th = elemento('th', clase ?? '', texto);
        th.scope = 'col';
        fila.append(th);
    }
    encabezado.append(fila);

    const cuerpo = elemento('tbody');
    cuerpo.append(...visibles.map(crearFila));
    tabla.append(encabezado, cuerpo);
    envoltura.append(tabla);

    return envoltura;
}

function crearFila(d) {
    const monitoreando = estado.monitoreando.has(d.id);
    const fila = elemento('tr');
    fila.dataset.id = String(d.id);
    if (monitoreando) {
        fila.className = 'fila-monitoreando';
        fila.setAttribute('aria-busy', 'true');
    }

    const celdas = [
        crearInsigniaEstado(estadoVisible(d)),
        crearNombre(d, 'tabla-nombre'),
        elemento('span', 'texto-mono fw-normal', d.ip),
        crearTipo(d.tipo),
        elemento('span', 'tabla-ubicacion', d.ubicacion),
        crearRespuesta(d, 'texto-mono fw-normal'),
        elemento('span', '', formatearFechaCorta(d.ultima_comprobacion, { siVacio: 'Nunca' })),
    ];
    const clasesCelda = ['', '', '', '', '', '', 'tabla-tenue'];

    celdas.forEach((contenidoCelda, i) => {
        const td = elemento('td', clasesCelda[i]);
        td.append(contenidoCelda);
        fila.append(td);
    });

    const acciones = elemento('div', 'tabla-acciones');
    acciones.append(...crearAcciones(d, false));
    const td = elemento('td');
    td.append(acciones);
    fila.append(td);

    return fila;
}

/**
 * Botones Monitorear / Editar / Eliminar (de tabla o, con completos, de la tarjeta móvil).
 */
function crearAcciones(d, completos) {
    const monitoreando = estado.monitoreando.has(d.id);
    const ocupado = estado.corriendo || estado.monitoreando.size > 0;

    const monitorear = elemento('button', completos ? 'btn btn-secondary btn-tarjeta' : 'btn-accion btn-accion--principal');
    monitorear.type = 'button';
    monitorear.disabled = ocupado;
    monitorear.dataset.accion = 'monitorear';
    if (monitoreando) {
        monitorear.classList.add(completos ? 'btn-tarjeta--cargando' : 'btn-accion--cargando');
        const girador = elemento('span', 'girador girador--acento');
        girador.setAttribute('aria-hidden', 'true');
        monitorear.append(girador);
    } else {
        monitorear.append(icono('bi-broadcast'));
    }
    if (completos) {
        monitorear.append('Monitorear');
    } else {
        monitorear.setAttribute('aria-label', `Monitorear ${d.nombre}`);
        monitorear.title = 'Monitorear';
    }
    monitorear.addEventListener('click', () => monitorearUno(d));

    const editar = elemento('button', completos ? 'btn btn-secondary btn-tarjeta' : 'btn-accion');
    editar.type = 'button';
    editar.disabled = monitoreando;
    editar.dataset.accion = 'editar';
    editar.append(icono('bi-pencil'));
    completos ? editar.append('Editar') : (editar.setAttribute('aria-label', `Editar ${d.nombre}`), (editar.title = 'Editar'));
    editar.addEventListener('click', (e) => abrirFormulario({ modo: 'editar', dispositivo: d, alGuardar, origen: e.currentTarget, alternativa: () => botonVisible(d.id, 'editar') }));

    const eliminar = elemento('button', completos ? 'btn btn-secondary btn-tarjeta btn-tarjeta--peligro' : 'btn-accion btn-accion--peligro');
    eliminar.type = 'button';
    eliminar.disabled = monitoreando;
    eliminar.dataset.accion = 'eliminar';
    eliminar.append(icono('bi-trash3'));
    completos ? eliminar.append('Eliminar') : (eliminar.setAttribute('aria-label', `Eliminar ${d.nombre}`), (eliminar.title = 'Eliminar'));
    eliminar.addEventListener('click', (e) => pedirEliminar(d, e.currentTarget));

    return [monitorear, editar, eliminar];
}

function crearTarjetaMovil(d) {
    const visible = estadoVisible(d);
    const tarjeta = elemento('article', `tarjeta-dispositivo-movil${d.estado === 'inactivo' ? ' tarjeta-dispositivo-movil--inactivo' : ''}${visible === 'monitoreando' ? ' tarjeta-dispositivo-movil--monitoreando' : ''}`);
    tarjeta.dataset.id = String(d.id);

    const cabecera = elemento('div', 'tarjeta-dispositivo-movil__cabecera');
    const iconoTarjeta = elemento('span', 'tarjeta-dispositivo-movil__icono');
    iconoTarjeta.append(icono(iconoTipo(d.tipo)));
    const textos = elemento('div', 'tarjeta-dispositivo-movil__textos');
    textos.append(crearNombre(d, 'tarjeta-dispositivo-movil__nombre'), elemento('span', 'tarjeta-dispositivo-movil__ip', d.ip));
    cabecera.append(iconoTarjeta, textos, crearInsigniaEstado(visible));

    const datos = elemento('dl', 'tarjeta-dispositivo-movil__datos');
    const agregarDato = (etiqueta, valor) => {
        const grupo = elemento('div');
        const dd = elemento('dd');
        dd.append(valor);
        grupo.append(elemento('dt', '', etiqueta), dd);
        datos.append(grupo);
    };
    agregarDato('Ubicación', d.ubicacion);
    agregarDato('Respuesta', crearRespuesta(d, 'mono', true));
    agregarDato('Comprobación', formatearFechaCorta(d.ultima_comprobacion, { siVacio: 'Nunca', compacta: true }));

    const acciones = elemento('div', 'tarjeta-dispositivo-movil__acciones');
    acciones.append(...crearAcciones(d, true));

    tarjeta.append(cabecera, datos, acciones);

    return tarjeta;
}

// ------------------------------------------------------------ Acciones

async function monitorearUno(d) {
    estado.monitoreando.add(d.id);
    render();

    try {
        const { monitoreo, alerta_creada: alertaCreada } = await api.monitoreo.monitorear(d.id);

        if (monitoreo.estado === 'activo') {
            mostrarNotificacion({ tipo: 'exito', titulo: `${d.nombre} responde`, texto: `Tiempo de respuesta: ${formatearMs(monitoreo.tiempo_respuesta_ms)}.` });
        } else {
            mostrarNotificacion({
                tipo: 'error',
                icono: 'bi-wifi-off',
                titulo: `${d.nombre} no respondió`,
                texto: alertaCreada ? 'Se generó una alerta pendiente.' : 'Ya tenía una alerta pendiente.',
                enlace: { texto: alertaCreada ? 'Ver alerta' : 'Ver alertas', href: 'alertas.html' },
            });
        }
    } catch (error) {
        notificarError(error, `No se pudo monitorear ${d.nombre}`);
    } finally {
        estado.monitoreando.delete(d.id);
        await recargarDespuesDeCambios();
    }
}

async function monitorearTodosLosDispositivos() {
    const lista = [...estado.dispositivos];
    if (estado.corriendo || lista.length === 0) {
        return;
    }

    estado.corriendo = true;
    progreso.mostrar(lista);

    const { resumen } = await monitorearTodos(lista, {
        alComprobar(indice, resultados) {
            estado.monitoreando = new Set([lista[indice].id]);
            botonesTodos.forEach((b) => ponerBotonMonitoreando(b, `Monitoreando ${indice + 1} de ${lista.length}…`));
            progreso.actualizar(indice, resultados);
            render();
        },
        alResultado(indice, resultados) {
            const resultado = resultados[indice];
            const d = estado.dispositivos.find((x) => x.id === lista[indice].id);
            if (d && !resultado.error) {
                // Refleja el resultado en la fila sin esperar a recargar la lista.
                Object.assign(d, {
                    estado: resultado.monitoreo.estado,
                    ultimo_tiempo_ms: resultado.monitoreo.tiempo_respuesta_ms,
                    ultima_comprobacion: resultado.monitoreo.fecha,
                });
            }
            progreso.actualizar(indice + 1 < lista.length ? indice + 1 : lista.length, resultados);
        },
    });

    estado.corriendo = false;
    estado.monitoreando = new Set();
    botonesTodos.forEach((b) => ponerBotonMonitoreando(b, null));
    notificarResumen(resumen);
    await recargarDespuesDeCambios();
    progreso.ocultar();
}

async function recargarDespuesDeCambios() {
    await Promise.all([cargar(), actualizarContadorAlertas()]);
}

function alGuardar(dispositivo, modo) {
    mostrarNotificacion(modo === 'agregar'
        ? { tipo: 'info', icono: 'bi-plus-lg', titulo: 'Dispositivo agregado', texto: `${dispositivo.nombre} se registró correctamente.` }
        : { tipo: 'exito', titulo: 'Cambios guardados', texto: `${dispositivo.nombre} se actualizó.` });
    cargar();
}

function pedirEliminar(d, boton) {
    estado.eliminar = { dispositivo: d };
    $('eliminar-nombre').textContent = d.nombre;
    $('eliminar-ip').textContent = d.ip;
    abrirModal($('modal-eliminar'), { origen: boton, alternativa: () => $('agregar-dispositivo') });
}

async function confirmarEliminar() {
    const boton = $('confirmar-eliminar');
    const d = estado.eliminar?.dispositivo;
    if (!d || boton.disabled) {
        return;
    }

    ponerBotonMonitoreando(boton, 'Eliminando…', 'girador');

    try {
        await api.dispositivos.eliminar(d.id);
        cerrarModal($('modal-eliminar')); // el foco irá a «Agregar»: la fila ya no existe
        mostrarNotificacion({ tipo: 'exito', icono: 'bi-trash3', titulo: 'Dispositivo eliminado', texto: `${d.nombre} y su historial se eliminaron.` });
        await recargarDespuesDeCambios(); // sus alertas se borraron por la cascada
    } catch (error) {
        notificarError(error, `No se pudo eliminar ${d.nombre}`);
    } finally {
        ponerBotonMonitoreando(boton, null);
    }
}
