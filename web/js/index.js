/*
 * Dashboard (docs/diseno: 1b escritorio, 1c/1d celular; API.md: "Qué usa cada pantalla").
 * Tres paneles que cargan y fallan por separado:
 * - Dispositivos (GET /dispositivos): indicadores, "Última comprobación" y tarjetas de estado.
 * - Últimas alertas (GET /alertas?limite=4, GET /alertas/conteo).
 * - Gráfica (GET /monitoreo/recientes): solo los dispositivos que hoy responden.
 * "Monitorear todos" usa modulos/monitorear-todos.js y al final actualiza todo.
 */

import { api } from './api.js';
import { iniciarPagina, actualizarContadorAlertas } from './modulos/estructura.js';
import { compararFechas, formatearFechaCompleta, formatearFechaCorta, formatearFechaHora, formatearHora, formatearMs, pluralizar } from './modulos/formatos.js';
import { crearError, crearVacio, mostrarBloque } from './modulos/vistas.js';
import { elemento, icono, crearInsigniaEstado, iconoTipo, urlHistorial } from './modulos/piezas.js';
import { crearProgreso, monitorearTodos, notificarResumen, ponerBotonMonitoreando } from './modulos/monitorear-todos.js';
import { crearGraficaLineas, destruirGrafica, estiloSerie } from './modulos/graficas.js';

const $ = (id) => document.getElementById(id);
const estado = {
    dispositivos: null, // null mientras no se cargan (o si falló la carga)
    monitoreando: null, // id del dispositivo que se está comprobando
    corriendo: false,
};
const progreso = crearProgreso($('progreso-monitoreo'));

await iniciarPagina(); // confirma la sesión antes de pedir datos
$('monitorear-todos').addEventListener('click', monitorearTodosDesdeDashboard);
cargarTodo();

/**
 * Carga los paneles en paralelo. Las alertas y la gráfica esperan a los dispositivos
 * (con éxito o con error) para mostrar los nombres.
 */
function cargarTodo() {
    const dispositivos = cargarDispositivos();

    return Promise.all([dispositivos, cargarAlertas(dispositivos), dispositivos.then(cargarGrafica)]);
}

// ------------------------------------------------------------ Dispositivos e indicadores

async function cargarDispositivos() {
    try {
        estado.dispositivos = await api.dispositivos.listar();
        renderDispositivos();
    } catch (error) {
        estado.dispositivos = null;
        $('monitorear-todos').disabled = true;
        for (const id of ['total', 'activos', 'inactivos']) {
            $(`indicador-${id}`).textContent = '—';
            $(`indicador-${id}-detalle`).textContent = 'Sin datos';
        }
        $('resumen-estado').textContent = 'No se pudieron cargar';
        mostrarBloque($('panel-estado'), crearError({
            titulo: 'No se pudieron cargar los dispositivos',
            mensaje: error.message,
            alReintentar: () => cargarTodo(),
            compacto: true,
        }));
    }
}

function renderDispositivos() {
    const lista = estado.dispositivos;
    const total = lista.length;
    const activos = lista.filter((d) => d.estado === 'activo').length;
    const inactivos = lista.filter((d) => d.estado === 'inactivo');
    const sinMonitorear = lista.filter((d) => d.estado === 'desconocido').length;
    const tipos = new Set(lista.map((d) => d.tipo)).size;
    const ubicaciones = new Set(lista.map((d) => d.ubicacion.trim().toLowerCase())).size;

    $('indicador-total').textContent = String(total);
    $('indicador-total-detalle').textContent = `${pluralizar(tipos, 'tipo', 'tipos')} · ${pluralizar(ubicaciones, 'ubicación', 'ubicaciones')}`;
    $('indicador-activos').textContent = String(activos);
    $('indicador-activos-detalle').textContent = total > 0 ? `${Math.round((activos / total) * 100)} % de la red responde` : 'Sin dispositivos';
    $('indicador-inactivos').textContent = String(inactivos.length);
    $('indicador-inactivos-detalle').textContent = inactivos.length > 0 ? inactivos.map((d) => d.nombre).join(', ') : 'Ninguno';

    const ultima = lista.map((d) => d.ultima_comprobacion).filter(Boolean).sort(compararFechas).pop();
    $('ultima-comprobacion').textContent = ultima ? formatearFechaCompleta(ultima) : 'Nunca';

    $('resumen-estado').textContent = [
        pluralizar(activos, 'activo', 'activos'),
        pluralizar(inactivos.length, 'inactivo', 'inactivos'),
        sinMonitorear > 0 ? `${sinMonitorear} sin monitorear` : null,
    ].filter(Boolean).join(' · ');

    $('monitorear-todos').disabled = estado.corriendo || total === 0;
    const panel = $('panel-estado');

    if (total === 0) {
        mostrarBloque(panel, crearVacio({
            icono: 'bi-hdd-network',
            titulo: 'Aún no hay dispositivos',
            texto: 'Registra el primer equipo de la red para empezar a monitorearlo.',
            accion: { texto: 'Agregar dispositivo', icono: 'bi-plus-lg', clase: 'btn btn-primary btn-sm', href: 'dispositivos.html' },
            compacto: true,
        }));
        return;
    }

    const rejilla = elemento('div', 'rejilla-estado');
    rejilla.append(...lista.map(crearTarjetaEstado));
    const filas = elemento('div', 'lista-estado');
    filas.append(...lista.map(crearFilaEstado));

    panel.replaceChildren(rejilla, filas);
    panel.removeAttribute('aria-busy');
}

function estadoVisible(d) {
    return estado.monitoreando === d.id ? 'monitoreando' : d.estado;
}

/**
 * Tiempo de la última comprobación: "0.32 ms", "Sin respuesta", "—" o "Enviando ping…".
 */
function crearTiempo(d, clase) {
    if (estadoVisible(d) === 'monitoreando') {
        return elemento('span', `${clase} texto-tenue`, 'Enviando ping…');
    }
    if (d.estado === 'inactivo') {
        return elemento('span', `${clase} texto-error`, 'Sin respuesta');
    }
    if (d.estado === 'desconocido') {
        return elemento('span', `${clase} texto-tenue`, '—');
    }
    return elemento('span', clase, formatearMs(d.ultimo_tiempo_ms));
}

function crearTarjetaEstado(d) {
    const visible = estadoVisible(d);
    const tarjeta = elemento('article', `tarjeta-estado tarjeta-estado--${visible}`);
    tarjeta.dataset.id = String(d.id);

    const cabecera = elemento('div', 'tarjeta-estado__cabecera');
    const iconoTarjeta = elemento('span', 'tarjeta-estado__icono');
    iconoTarjeta.append(icono(iconoTipo(d.tipo)));
    cabecera.append(iconoTarjeta, crearInsigniaEstado(visible));

    const textos = elemento('div', 'tarjeta-estado__textos');
    const nombre = elemento('a', 'tarjeta-estado__nombre enlace-discreto stretched-link', d.nombre);
    nombre.href = urlHistorial(d.id);
    textos.append(nombre, elemento('span', 'tarjeta-estado__ip', d.ip));

    const pie = elemento('div', 'tarjeta-estado__pie');
    pie.append(crearTiempo(d, 'tarjeta-estado__tiempo'), elemento('span', 'tarjeta-estado__fecha', formatearFechaCorta(d.ultima_comprobacion, { siVacio: 'Nunca comprobado', compacta: true })));

    tarjeta.append(cabecera, textos, pie);

    return tarjeta;
}

function crearFilaEstado(d) {
    const visible = estadoVisible(d);
    const fila = elemento('div', `fila-estado fila-estado--${visible}`);
    fila.dataset.id = String(d.id);

    const iconoFila = elemento('span', 'fila-estado__icono');
    iconoFila.append(icono(iconoTipo(d.tipo)));

    const textos = elemento('div', 'fila-estado__textos');
    const nombre = elemento('a', 'fila-estado__nombre enlace-discreto stretched-link', d.nombre);
    nombre.href = urlHistorial(d.id);
    textos.append(nombre, elemento('span', 'fila-estado__ip', d.ip));

    const lado = elemento('div', 'fila-estado__lado');
    const insignia = crearInsigniaEstado(visible);
    insignia.classList.add('insignia-estado--sm');
    lado.append(insignia, crearTiempo(d, 'fila-estado__tiempo'));

    fila.append(iconoFila, textos, lado);

    return fila;
}

// ------------------------------------------------------------ Últimas alertas

async function cargarAlertas(dispositivosCargados = Promise.resolve()) {
    const panel = $('panel-alertas');

    try {
        const [alertas, conteo, pendientes] = await Promise.all([
            api.alertas.listar({ limite: 4 }),
            api.alertas.conteo(),
            api.alertas.listar({ atendida: 0, limite: 1 }),
            dispositivosCargados, // para mostrar el nombre de cada dispositivo
        ]);

        $('indicador-alertas').textContent = String(conteo.pendientes);
        $('indicador-alertas-detalle').textContent = pendientes.length > 0 ? `Última: ${formatearFechaHora(pendientes[0].fecha)}` : 'Sin alertas pendientes';
        const contador = $('contador-panel-alertas');
        contador.textContent = String(conteo.pendientes);
        contador.hidden = conteo.pendientes === 0;
        contador.setAttribute('aria-label', pluralizar(conteo.pendientes, 'alerta pendiente', 'alertas pendientes'));

        if (alertas.length === 0) {
            mostrarBloque(panel, crearVacio({
                icono: 'bi-check2-circle',
                variante: 'exito',
                titulo: 'Sin alertas',
                texto: 'Cuando un dispositivo deje de responder, aparecerá aquí.',
                compacto: true,
            }));
            return;
        }

        const lista = elemento('ul', 'lista-alertas');
        lista.append(...alertas.map(crearAlertaReciente));
        panel.replaceChildren(lista);
        panel.removeAttribute('aria-busy');
    } catch (error) {
        $('indicador-alertas').textContent = '—';
        $('indicador-alertas-detalle').textContent = 'Sin datos';
        mostrarBloque(panel, crearError({
            titulo: 'No se pudieron cargar las alertas',
            mensaje: error.message,
            alReintentar: () => cargarAlertas(),
            compacto: true,
        }));
    }
}

function nombreDispositivo(dispositivoId) {
    return estado.dispositivos?.find((d) => d.id === dispositivoId)?.nombre ?? `Dispositivo ${dispositivoId}`;
}

function crearAlertaReciente(alerta) {
    const item = elemento('li', `alerta-reciente${alerta.atendida ? ' alerta-reciente--atendida' : ''}`);

    const iconoAlerta = elemento('span', 'alerta-reciente__icono');
    iconoAlerta.append(icono('bi-wifi-off'));

    const cuerpo = elemento('div', 'alerta-reciente__cuerpo');
    const cabecera = elemento('div', 'alerta-reciente__cabecera');
    const insignia = elemento('span', `insignia-estado insignia-estado--sm ${alerta.atendida ? 'insignia-estado--atendida' : 'insignia-estado--pendiente'}`);
    if (alerta.atendida) {
        insignia.append(icono('bi-check2'), 'Atendida');
    } else {
        insignia.append(elemento('span', 'punto-estado punto-estado--alerta'), 'Pendiente');
    }
    cabecera.append(elemento('span', 'alerta-reciente__nombre', nombreDispositivo(alerta.dispositivo_id)), insignia);

    cuerpo.append(
        cabecera,
        elemento('p', 'alerta-reciente__mensaje', alerta.mensaje),
        elemento('span', 'alerta-reciente__fecha', formatearFechaHora(alerta.fecha)),
    );
    item.append(iconoAlerta, cuerpo);

    return item;
}

// ------------------------------------------------------------ Gráfica

async function cargarGrafica() {
    const panel = $('panel-grafica');
    const leyenda = $('leyenda-grafica');

    try {
        const recientes = await api.monitoreo.recientes(10);
        const responden = estado.dispositivos === null
            ? null
            : new Set(estado.dispositivos.filter((d) => d.estado === 'activo').map((d) => d.id));

        const grupos = recientes
            .filter((g) => responden === null || responden.has(g.dispositivo_id))
            .map((g) => ({ nombre: nombreDispositivo(g.dispositivo_id), monitoreos: g.monitoreos }))
            .sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'));

        if (grupos.length === 0) {
            leyenda.replaceChildren();
            mostrarBloque(panel, crearVacio({
                icono: 'bi-activity',
                titulo: 'Aún no hay tiempos para graficar',
                texto: 'Aparecen cuando algún dispositivo responde. Prueba con «Monitorear todos».',
                compacto: true,
            }));
            return;
        }

        // Las series se alinean por el final: la última comprobación de cada una queda a la derecha.
        const largo = Math.max(...grupos.map((g) => g.monitoreos.length));
        const masLarga = grupos.find((g) => g.monitoreos.length === largo);
        const etiquetas = masLarga.monitoreos.map((m) => formatearHora(m.fecha));
        const series = grupos.map((g) => ({
            nombre: g.nombre,
            datos: [...Array(largo - g.monitoreos.length).fill(null), ...g.monitoreos.map((m) => m.tiempo_respuesta_ms)],
        }));

        leyenda.replaceChildren(...series.map((serie, i) => {
            const estilo = estiloSerie(i);
            const item = elemento('li', `leyenda-grafica__item${estilo.punteada ? ' leyenda-grafica__item--punteada' : ''}`);
            const muestra = elemento('span', 'leyenda-grafica__linea');
            muestra.style.setProperty('--color-serie', `var(${estilo.variable})`);
            item.append(muestra, serie.nombre);
            return item;
        }));

        const contenedor = elemento('div', 'grafica');
        const canvas = elemento('canvas');
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', `Tiempos de respuesta de las últimas ${largo} comprobaciones de ${series.map((s) => s.nombre).join(', ')}`);
        contenedor.append(canvas);

        const anterior = panel.querySelector('canvas');
        if (anterior) {
            destruirGrafica(anterior);
        }
        panel.replaceChildren(contenedor);
        panel.removeAttribute('aria-busy');
        crearGraficaLineas(canvas, { etiquetas, series, compacta: window.matchMedia('(max-width: 575.98px)').matches });
    } catch (error) {
        leyenda.replaceChildren();
        mostrarBloque(panel, crearError({
            titulo: 'No se pudo cargar la gráfica',
            mensaje: error.message,
            alReintentar: () => cargarGrafica(),
            compacto: true,
        }));
    }
}

// ------------------------------------------------------------ Monitorear todos

async function monitorearTodosDesdeDashboard() {
    const boton = $('monitorear-todos');
    const lista = [...(estado.dispositivos ?? [])];
    if (estado.corriendo || lista.length === 0) {
        return;
    }

    estado.corriendo = true;
    progreso.mostrar(lista);

    const { resumen } = await monitorearTodos(lista, {
        alComprobar(indice, resultados) {
            estado.monitoreando = lista[indice].id;
            ponerBotonMonitoreando(boton, `Monitoreando ${indice + 1} de ${lista.length}…`, 'girador');
            progreso.actualizar(indice, resultados);
            renderDispositivos();
        },
        alResultado(indice, resultados) {
            const resultado = resultados[indice];
            const d = estado.dispositivos.find((x) => x.id === lista[indice].id);
            if (d && !resultado.error) {
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
    estado.monitoreando = null;
    ponerBotonMonitoreando(boton, null);
    notificarResumen(resumen);
    await Promise.all([cargarTodo(), actualizarContadorAlertas()]);
    progreso.ocultar();
}
