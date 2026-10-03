/*
 * Pantalla Monitoreos (docs/diseno: 3a; API.md: "Qué usa cada pantalla").
 * - Filtros en la URL (?dispositivo=3&estado=inactivo&desde=…&hasta=…&pagina=2), así
 *   Dispositivos y el Dashboard enlazan al historial de un dispositivo.
 * - Rango inicial: los últimos 7 días (hoy y los 6 anteriores).
 * - Gráfica solo con un dispositivo elegido (GET /monitoreo?por_pagina=24, invertida).
 * - Resumen y tabla paginada de 10 (GET /monitoreo).
 */

import { api } from './api.js';
import { iniciarPagina } from './modulos/estructura.js';
import { fechaParaCampo, formatearFechaCorta, formatearFechaHora, formatearHora, formatearMs, formatearPorcentaje, haceDias, partesFecha, pluralizar } from './modulos/formatos.js';
import { crearError, crearVacio, mostrarBloque } from './modulos/vistas.js';
import { elemento, icono, crearInsigniaEstado, iconoTipo } from './modulos/piezas.js';
import { crearGraficaArea, destruirGrafica } from './modulos/graficas.js';

const POR_PAGINA = 10;
const PUNTOS_GRAFICA = 24;
const PATRON_DIA = /^\d{4}-\d{2}-\d{2}$/;

const $ = (id) => document.getElementById(id);
const estado = {
    dispositivos: [],
    sinDispositivos: false, // la lista cargó bien y está vacía (la base recién creada)
    filtros: filtrosPorDefecto(),
    peticion: 0, // para descartar respuestas que llegan tarde
};

await iniciarPagina(); // confirma la sesión antes de pedir datos
iniciar();

function filtrosPorDefecto() {
    return { dispositivo: '', estado: '', desde: fechaParaCampo(haceDias(6)), hasta: fechaParaCampo(new Date()), pagina: 1 };
}

// ------------------------------------------------------------ Filtros y URL

function leerFiltrosDeLaUrl() {
    const parametros = new URLSearchParams(location.search);
    const filtros = filtrosPorDefecto();
    const dispositivo = parametros.get('dispositivo') ?? '';
    const estadoUrl = parametros.get('estado') ?? '';
    const pagina = Number(parametros.get('pagina'));

    if (/^[1-9]\d*$/.test(dispositivo)) {
        filtros.dispositivo = dispositivo;
    }
    if (['activo', 'inactivo'].includes(estadoUrl)) {
        filtros.estado = estadoUrl;
    }
    for (const campo of ['desde', 'hasta']) {
        const valor = parametros.get(campo) ?? '';
        if (PATRON_DIA.test(valor)) {
            filtros[campo] = valor;
        }
    }
    if (Number.isInteger(pagina) && pagina >= 1) {
        filtros.pagina = pagina;
    }

    return filtros;
}

/**
 * Escribe en la URL solo los filtros distintos de los valores por defecto.
 */
function guardarFiltrosEnLaUrl() {
    const porDefecto = filtrosPorDefecto();
    const parametros = new URLSearchParams();

    for (const [clave, valor] of Object.entries(estado.filtros)) {
        if (String(valor) !== String(porDefecto[clave])) {
            parametros.set(clave, String(valor));
        }
    }

    const consulta = parametros.toString();
    history.replaceState(null, '', consulta ? `?${consulta}` : location.pathname);
}

function mostrarFiltros() {
    const f = estado.filtros;
    $('filtro-dispositivo').value = f.dispositivo;
    $('filtro-estado').value = f.estado;
    $('filtro-desde').value = f.desde;
    $('filtro-hasta').value = f.hasta;
}

function marcarErrorFiltro(campo, mensaje) {
    const control = $(`filtro-${campo}`);
    const error = $(`error-${campo}`);

    if (!control || !error) {
        return;
    }
    control.classList.toggle('is-invalid', mensaje !== null);
    control.setAttribute('aria-invalid', mensaje !== null ? 'true' : 'false');
    error.textContent = mensaje ?? '';
    error.hidden = mensaje === null;
}

function conectarFiltros() {
    const alCambiar = (campo) => (evento) => {
        estado.filtros[campo] = evento.target.value;
        estado.filtros.pagina = 1;
        cargar();
    };

    $('filtro-dispositivo').addEventListener('change', alCambiar('dispositivo'));
    $('filtro-estado').addEventListener('change', alCambiar('estado'));
    $('filtro-desde').addEventListener('change', alCambiar('desde'));
    $('filtro-hasta').addEventListener('change', alCambiar('hasta'));
    $('filtros').addEventListener('submit', (e) => e.preventDefault());

    $('restablecer-filtros').addEventListener('click', () => {
        estado.filtros = filtrosPorDefecto();
        mostrarFiltros();
        cargar();
    });
    $('pagina-anterior').addEventListener('click', () => cambiarPagina(-1));
    $('pagina-siguiente').addEventListener('click', () => cambiarPagina(1));
}

function cambiarPagina(delta) {
    estado.filtros.pagina = Math.max(1, estado.filtros.pagina + delta);
    cargar({ soloTabla: true }).then(() => $('titulo-historial').scrollIntoView({ block: 'nearest' }));
}

// ------------------------------------------------------------ Carga

async function iniciar() {
    estado.filtros = leerFiltrosDeLaUrl();
    conectarFiltros();

    try {
        estado.dispositivos = await api.dispositivos.listar();
        estado.sinDispositivos = estado.dispositivos.length === 0;
    } catch {
        estado.dispositivos = []; // el historial se puede ver igual, sin nombres
        estado.sinDispositivos = false;
    }

    const selector = $('filtro-dispositivo');
    selector.append(...estado.dispositivos.map((d) => {
        const opcion = elemento('option', '', d.nombre);
        opcion.value = String(d.id);
        return opcion;
    }));

    // Un dispositivo que ya no existe en la URL se ignora.
    if (estado.filtros.dispositivo && estado.dispositivos.length > 0 && !estado.dispositivos.some((d) => String(d.id) === estado.filtros.dispositivo)) {
        estado.filtros.dispositivo = '';
    }

    mostrarFiltros();
    await cargar();
}

async function cargar({ soloTabla = false } = {}) {
    const numero = ++estado.peticion;
    const f = estado.filtros;
    const consulta = { dispositivo: f.dispositivo, estado: f.estado, desde: f.desde, hasta: f.hasta };

    guardarFiltrosEnLaUrl();
    ['desde', 'hasta', 'dispositivo', 'estado'].forEach((c) => marcarErrorFiltro(c, null));
    $('contenido-historial').setAttribute('aria-busy', 'true');
    $('contenido-historial').classList.add('actualizando');
    if (!soloTabla) {
        $('panel-grafica').setAttribute('aria-busy', 'true');
    }

    const tabla = api.monitoreo.historial({ ...consulta, pagina: f.pagina, por_pagina: POR_PAGINA });
    const grafica = !soloTabla && f.dispositivo
        ? api.monitoreo.historial({ dispositivo: f.dispositivo, desde: f.desde, hasta: f.hasta, por_pagina: PUNTOS_GRAFICA })
        : null;

    try {
        const datos = await tabla;
        if (numero !== estado.peticion) {
            return; // llegó otra búsqueda más reciente
        }
        mostrarResumen(datos.resumen);
        mostrarTabla(datos);
    } catch (error) {
        if (numero !== estado.peticion) {
            return;
        }
        if (error.campos) {
            Object.entries(error.campos).forEach(([campo, mensaje]) => marcarErrorFiltro(campo, mensaje));
        }
        mostrarResumen(null);
        $('paginacion').hidden = true;
        $('conteo-historial').textContent = '';
        mostrarBloque($('contenido-historial'), error.campos
            ? crearVacio({ icono: 'bi-calendar-x', titulo: 'Revisa los filtros', texto: 'Corrige el campo marcado para ver el historial.', accion: { texto: 'Restablecer filtros', alHacerClic: () => $('restablecer-filtros').click() }, compacto: true })
            : crearError({ titulo: 'No se pudo cargar el historial', mensaje: error.message, alReintentar: () => cargar(), compacto: true }));
    } finally {
        $('contenido-historial').classList.remove('actualizando');
    }

    if (!soloTabla) {
        await mostrarGrafica(grafica, numero);
    }
}

// ------------------------------------------------------------ Resumen

function mostrarResumen(resumen) {
    $('resumen-promedio').textContent = formatearMs(resumen?.promedio_ms ?? null, '—');
    $('resumen-maximo').textContent = formatearMs(resumen?.maximo_ms ?? null, '—');

    const disponibilidad = resumen?.disponibilidad ?? null;
    const celda = $('resumen-disponibilidad');
    celda.textContent = formatearPorcentaje(disponibilidad, 1);
    celda.className = disponibilidad === null ? '' : disponibilidad >= 99 ? 'texto-activo' : disponibilidad >= 95 ? 'texto-alerta' : 'texto-error';
}

// ------------------------------------------------------------ Tabla

function nombreDe(id) {
    return estado.dispositivos.find((d) => d.id === id) ?? null;
}

function mostrarTabla({ items, total, pagina, por_pagina: porPagina, total_paginas: totalPaginas }) {
    const contenedor = $('contenido-historial');
    $('conteo-historial').textContent = pluralizar(total, 'comprobación', 'comprobaciones');

    if (total === 0) {
        $('paginacion').hidden = true;
        const sinFiltros = !estado.filtros.dispositivo && !estado.filtros.estado
            && estado.filtros.desde === filtrosPorDefecto().desde && estado.filtros.hasta === filtrosPorDefecto().hasta;
        const sinNada = estado.sinDispositivos
            ? crearVacio({ icono: 'bi-activity', titulo: 'Aún no hay comprobaciones', texto: 'Agrega tus dispositivos y monitoréalos para ver aquí su historial.', accion: { texto: 'Ir a Dispositivos', href: 'dispositivos.html' }, compacto: true })
            : null;
        mostrarBloque(contenedor, sinNada ?? (sinFiltros
            ? crearVacio({ icono: 'bi-activity', titulo: 'Aún no hay comprobaciones en los últimos 7 días', texto: 'Monitorea los dispositivos desde el Dashboard o Dispositivos.', accion: { texto: 'Ir al Dashboard', href: 'index.html' }, compacto: true })
            : crearVacio({ icono: 'bi-search', titulo: 'Ninguna comprobación coincide', texto: 'Prueba con otro dispositivo, estado o rango de fechas.', accion: { texto: 'Restablecer filtros', alHacerClic: () => $('restablecer-filtros').click() }, compacto: true })));
        return;
    }

    if (items.length === 0 && pagina > 1) {
        // La página ya no existe (por ejemplo, tras cambiar de filtros): se vuelve a la última.
        estado.filtros.pagina = totalPaginas;
        cargar({ soloTabla: true });
        return;
    }

    const envoltura = elemento('div', 'table-responsive tabla-historial');
    const tabla = elemento('table', 'table');
    const encabezado = elemento('thead');
    const filaEncabezado = elemento('tr');
    for (const texto of ['Fecha y hora', 'Dispositivo', 'Dirección IP', 'Resultado', 'Tiempo de respuesta']) {
        const th = elemento('th', '', texto);
        th.scope = 'col';
        filaEncabezado.append(th);
    }
    encabezado.append(filaEncabezado);

    const cuerpo = elemento('tbody');
    cuerpo.append(...items.map((m) => {
        const dispositivo = nombreDe(m.dispositivo_id);
        const fila = elemento('tr');
        const celda = (contenido, clase = '') => {
            const td = elemento('td', clase);
            td.append(contenido);
            fila.append(td);
        };
        const nombre = elemento('span', 'tabla-tipo tabla-tipo--nombre');
        nombre.append(icono(iconoTipo(dispositivo?.tipo)), dispositivo?.nombre ?? `Dispositivo ${m.dispositivo_id}`);

        celda(elemento('span', 'texto-mono fw-normal', formatearFechaHora(m.fecha)));
        celda(nombre);
        celda(elemento('span', 'texto-mono fw-normal texto-tenue-ip', m.ip));
        celda(crearInsigniaEstado(m.estado));
        celda(elemento('span', `texto-mono fw-normal${m.estado === 'inactivo' ? ' texto-error' : ''}`, formatearMs(m.tiempo_respuesta_ms)));
        return fila;
    }));
    tabla.append(encabezado, cuerpo);
    envoltura.append(tabla);
    contenedor.replaceChildren(envoltura, crearFilasCompactas(items));
    contenedor.removeAttribute('aria-busy');

    const desde = (pagina - 1) * porPagina + 1;
    const hasta = Math.min(pagina * porPagina, total);
    $('texto-paginacion').textContent = `Mostrando ${desde}–${hasta} de ${total} · página ${pagina} de ${totalPaginas}`;
    $('pagina-anterior').disabled = pagina <= 1;
    $('pagina-siguiente').disabled = pagina >= totalPaginas;
    $('paginacion').hidden = false;
}

/**
 * La misma página del historial en filas compactas, para cuando la tarjeta es más angosta
 * que la tabla (app.css las muestra con una container query): fecha, resultado y tiempo
 * en la primera línea; dispositivo e IP en la segunda, salvo si el filtro ya eligió uno.
 */
function crearFilasCompactas(items) {
    const lista = elemento('ul', 'lista-historial');
    const conDispositivo = !estado.filtros.dispositivo;

    lista.append(...items.map((m) => {
        const fila = elemento('li', 'fila-historial');
        const linea = elemento('div', 'fila-historial__linea');
        const insignia = crearInsigniaEstado(m.estado);
        insignia.classList.add('insignia-estado--sm');
        linea.append(
            elemento('span', 'fila-historial__fecha', formatearFechaCorta(m.fecha, { compacta: true })),
            insignia,
            elemento('span', `fila-historial__tiempo${m.estado === 'inactivo' ? ' texto-error' : ''}`, formatearMs(m.tiempo_respuesta_ms)),
        );
        fila.append(linea);

        if (conDispositivo) {
            const dispositivo = nombreDe(m.dispositivo_id);
            const segunda = elemento('div', 'fila-historial__dispositivo');
            segunda.append(
                icono(iconoTipo(dispositivo?.tipo)),
                elemento('span', 'fila-historial__nombre', dispositivo?.nombre ?? `Dispositivo ${m.dispositivo_id}`),
                elemento('span', 'fila-historial__ip', m.ip),
            );
            fila.append(segunda);
        }
        return fila;
    }));

    return lista;
}

// ------------------------------------------------------------ Gráfica

async function mostrarGrafica(promesa, numero) {
    const panel = $('panel-grafica');
    const leyenda = $('leyenda-historial');
    const dispositivo = nombreDe(Number(estado.filtros.dispositivo));

    const anterior = panel.querySelector('canvas');
    if (anterior) {
        destruirGrafica(anterior);
    }

    if (promesa === null) {
        $('titulo-grafica').textContent = 'Tiempos de respuesta';
        $('subtitulo-grafica').textContent = 'Resumen del rango · todos los dispositivos';
        leyenda.hidden = true;
        mostrarBloque(panel, estado.sinDispositivos
            ? crearVacio({
                icono: 'bi-hdd-network',
                titulo: 'Aún no hay dispositivos',
                texto: 'Agrega tus dispositivos y monitoréalos para ver aquí sus tiempos de respuesta.',
                accion: { texto: 'Ir a Dispositivos', href: 'dispositivos.html' },
                compacto: true,
            })
            : crearVacio({
                icono: 'bi-graph-up',
                titulo: 'Elige un dispositivo para ver su gráfica',
                texto: 'La gráfica muestra los tiempos de respuesta de un dispositivo a la vez.',
                compacto: true,
            }));
        return;
    }

    const nombre = dispositivo?.nombre ?? `Dispositivo ${estado.filtros.dispositivo}`;
    $('titulo-grafica').textContent = `Tiempos de respuesta · ${nombre}`;

    try {
        const { items } = await promesa;
        if (numero !== estado.peticion) {
            return;
        }

        if (items.length === 0) {
            $('subtitulo-grafica').textContent = 'Sin comprobaciones en el rango';
            leyenda.hidden = true;
            mostrarBloque(panel, crearVacio({ icono: 'bi-graph-up', titulo: 'Sin comprobaciones en el rango', texto: 'Prueba con otro rango de fechas.', compacto: true }));
            return;
        }

        const puntos = [...items].reverse();
        const variosDias = new Set(puntos.map((m) => m.fecha.slice(0, 10))).size > 1;
        const ip = puntos[puntos.length - 1].ip;
        $('subtitulo-grafica').replaceChildren(elemento('span', 'mono', ip), ` · últimas ${puntos.length} comprobaciones del rango`);

        const contenedor = elemento('div', 'grafica');
        const canvas = elemento('canvas');
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', `Tiempos de respuesta de ${nombre} en sus últimas ${puntos.length} comprobaciones`);
        contenedor.append(canvas);
        panel.replaceChildren(contenedor);
        panel.removeAttribute('aria-busy');
        leyenda.hidden = false;

        crearGraficaArea(canvas, {
            puntos: puntos.map((m) => {
                const p = partesFecha(m.fecha);
                return { etiqueta: variosDias ? `${p.dia}/${p.mes} ${p.hora}:${p.minuto}` : formatearHora(m.fecha), ms: m.tiempo_respuesta_ms };
            }),
            compacta: window.matchMedia('(max-width: 575.98px)').matches,
        });
    } catch (error) {
        if (numero !== estado.peticion) {
            return;
        }
        leyenda.hidden = true;
        $('subtitulo-grafica').textContent = '\u00a0';
        // Con filtros no válidos, el aviso ya está en el campo y en la tabla: aquí no hay nada que reintentar.
        mostrarBloque(panel, error.campos
            ? crearVacio({ icono: 'bi-graph-up', titulo: 'Sin gráfica con estos filtros', texto: 'Corrige el campo marcado para ver la gráfica.', compacto: true })
            : crearError({ titulo: 'No se pudo cargar la gráfica', mensaje: error.message, alReintentar: () => cargar(), compacto: true }));
    }
}
