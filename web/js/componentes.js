/*
 * Demostraciones de web/componentes.html (página de muestra del sistema de diseño):
 * lanza notificaciones reales y muestra el bloque de error con "Reintentar".
 * El modal se abre solo con los atributos data-bs-* de Bootstrap.
 */

import { mostrarNotificacion } from './modulos/notificaciones.js';
import { crearError, mostrarBloque } from './modulos/vistas.js';
import { llenarBarraLateral, llenarEncabezadoMovil } from './modulos/estructura.js';
import { crearProgreso } from './modulos/monitorear-todos.js';
import { crearGraficaArea, crearGraficaLineas, estiloSerie } from './modulos/graficas.js';
import { crearInsigniaHallazgo, crearNombreEquipo, crearRegistro, crearTablaEquipos, crearTarjetaEquipo } from './modulos/busqueda-red.js';

const NOTIFICACIONES_DE_EJEMPLO = {
    exito: { tipo: 'exito', titulo: 'Monitoreo completado', texto: '4 de 6 dispositivos responden.' },
    error: {
        tipo: 'error',
        icono: 'bi-wifi-off',
        titulo: 'Servidor principal no respondió',
        texto: 'Se generó una alerta pendiente.',
        enlace: { texto: 'Ver alerta', href: 'alertas.html' },
    },
    info: { tipo: 'info', icono: 'bi-plus-lg', titulo: 'Dispositivo agregado', texto: 'Impresora Laboratorio se registró correctamente.' },
};

function mostrarErrorDeEjemplo(contenedor) {
    mostrarBloque(contenedor, crearError({
        titulo: 'No se pudieron cargar los dispositivos',
        mensaje: 'No se pudo conectar con el servidor. Revisa que Apache esté encendido.',
        alReintentar: () => mostrarNotificacion({ tipo: 'info', titulo: 'Reintentando…', texto: 'En las pantallas, aquí se vuelven a pedir los datos.' }),
    }));
}

document.querySelectorAll('[data-notificacion]').forEach((boton) => {
    boton.addEventListener('click', () => mostrarNotificacion(NOTIFICACIONES_DE_EJEMPLO[boton.dataset.notificacion]));
});

const contenedorError = document.getElementById('demo-error');
if (contenedorError) {
    mostrarErrorDeEjemplo(contenedorError);
}

const barraDemo = document.getElementById('demo-barra-lateral');
const encabezadoDemo = document.getElementById('demo-encabezado-movil');
if (barraDemo && encabezadoDemo) {
    const usuarioDemo = { nombre: 'Administrador', usuario: 'admin' };
    llenarBarraLateral(barraDemo, 'index', usuarioDemo);
    llenarEncabezadoMovil(encabezadoDemo, 'index', usuarioDemo);
    // El menú se muestra abierto, como en 1d, sin mover el foco (no debe desplazar la página).
    const marco = encabezadoDemo.parentElement;
    marco.querySelector('.menu-movil').hidden = false;
    marco.querySelector('.menu-movil__fondo').hidden = false;
    const boton = encabezadoDemo.querySelector('.encabezado-movil__boton');
    boton.setAttribute('aria-expanded', 'true');
    boton.setAttribute('aria-label', 'Cerrar el menú');
    boton.firstElementChild.className = 'bi bi-x-lg';
    // Contador fijo: esta página es pública (sin sesión) y no pide datos a la API.
    for (const contador of document.querySelectorAll('.js-contador-alertas')) {
        contador.textContent = '1';
        contador.hidden = false;
        contador.setAttribute('aria-label', '1 alerta pendiente');
    }
}

// Progreso como en 2d: dos comprobados, el tercero en curso y el resto en espera.
const contenedorProgreso = document.getElementById('demo-progreso');
if (contenedorProgreso) {
    const lista = [
        { nombre: 'Mi PC', ip: '127.0.0.1' },
        { nombre: 'Router principal', ip: '192.168.1.1' },
        { nombre: 'Servidor principal', ip: '192.168.1.10' },
        { nombre: 'Servidor de respaldo', ip: '192.168.1.11' },
        { nombre: 'Impresora Laboratorio', ip: '192.168.1.20' },
        { nombre: 'PC Recepción', ip: '192.168.1.35' },
    ];
    const progreso = crearProgreso(contenedorProgreso);
    progreso.mostrar(lista);
    progreso.actualizar(2, {
        0: { monitoreo: { estado: 'activo', tiempo_respuesta_ms: 0.31 } },
        1: { monitoreo: { estado: 'activo', tiempo_respuesta_ms: 1.9 } },
    });
}

// Gráfica de ejemplo con cinco series: la quinta repite el primer color con línea punteada.
const lienzoDemo = document.getElementById('demo-grafica');
if (lienzoDemo && window.Chart) {
    const series = [
        { nombre: 'Router principal', datos: [1.6, 1.9, 1.7, 2.1, 1.8, 1.7, 2.4, 1.9, 1.7, 1.8] },
        { nombre: 'Impresora Laboratorio', datos: [3.8, 4.4, 4.0, 5.2, 4.6, 3.9, 4.3, 4.8, 4.2, 4.1] },
        { nombre: 'PC Recepción', datos: [2.2, 2.6, 2.4, 2.3, 3.1, 2.7, 2.5, 2.4, 2.8, 2.5] },
        { nombre: 'Mi PC', datos: [0.3, 0.28, 0.35, 0.31, 0.3, 0.34, 0.29, 0.33, 0.3, 0.32] },
        { nombre: 'Servidor de archivos', datos: [5.1, 5.6, null, null, 5.9, 5.4, 5.2, 5.8, 5.5, 5.3] },
    ];
    document.getElementById('demo-leyenda').replaceChildren(...series.map((serie, i) => {
        const estilo = estiloSerie(i);
        const item = document.createElement('li');
        item.className = `leyenda-grafica__item${estilo.punteada ? ' leyenda-grafica__item--punteada' : ''}`;
        const linea = document.createElement('span');
        linea.className = 'leyenda-grafica__linea';
        linea.style.setProperty('--color-serie', `var(${estilo.variable})`);
        item.append(linea, serie.nombre);
        return item;
    }));
    const etiquetas = ['09:20', '09:25', '09:30', '09:35', '09:40', '09:45', '09:50', '09:55', '10:00', '10:05'];
    crearGraficaLineas(lienzoDemo, { etiquetas, series });
}

// Gráfica de un dispositivo como en 3a: cuatro comprobaciones sin respuesta al final.
const lienzoArea = document.getElementById('demo-grafica-area');
if (lienzoArea && window.Chart) {
    const tiempos = [0.8, 1.2, 0.9, 2.4, 1.0, 0.95, null, null, null, null];
    crearGraficaArea(lienzoArea, {
        puntos: tiempos.map((ms, i) => ({ etiqueta: `09:${String(i * 5).padStart(2, '0')}`, ms })),
    });
}

// Búsqueda en la red (Fase 9): las mismas piezas de modulos/busqueda-red.js con equipos de ejemplo.
const EQUIPOS_DE_EJEMPLO = [
    { ip: '192.168.1.1', mac: 'be:1e:a4:da:96:d3', nombre: 'Technicolor CGA2121', nombre_origen: 'upnp', responde_ping: false, tiempo_respuesta_ms: null, en_arp: true, es_esta_computadora: false, es_puerta_enlace: true, dispositivo: { id: 2, nombre: 'Router principal' } },
    { ip: '192.168.1.20', mac: '00:11:22:33:44:55', nombre: 'HP LaserJet M110w', nombre_origen: 'upnp', responde_ping: true, tiempo_respuesta_ms: 2.75, en_arp: true, es_esta_computadora: false, es_puerta_enlace: false, dispositivo: { id: 5, nombre: 'Impresora Laboratorio' } },
    { ip: '192.168.1.35', mac: null, nombre: 'pc-recepcion.lan', nombre_origen: 'dns', responde_ping: true, tiempo_respuesta_ms: 0, en_arp: false, es_esta_computadora: true, es_puerta_enlace: false, dispositivo: { id: 3, nombre: 'PC Recepción' } },
    { ip: '192.168.1.143', mac: '94:bb:43:b5:29:72', nombre: 'LAPTOP-ANA', nombre_origen: 'netbios', responde_ping: false, tiempo_respuesta_ms: null, en_arp: true, es_esta_computadora: false, es_puerta_enlace: false, dispositivo: null },
    { ip: '192.168.1.150', mac: '3a:1f:7c:20:9e:41', nombre: 'iPhone-de-Luis.local', nombre_origen: 'mdns', responde_ping: true, tiempo_respuesta_ms: 48.2, en_arp: true, es_esta_computadora: false, es_puerta_enlace: false, dispositivo: null },
    { ip: '192.168.1.220', mac: '86:f1:ba:40:ec:9f', nombre: null, nombre_origen: null, responde_ping: true, tiempo_respuesta_ms: 34.9, en_arp: true, es_esta_computadora: false, es_puerta_enlace: false, dispositivo: null },
];
const agregarDeEjemplo = (equipo) => mostrarNotificacion({ tipo: 'info', icono: 'bi-plus-lg', titulo: 'Agregar', texto: `En Dispositivos se abre el formulario con ${equipo.ip} ya escrita.` });

const piezasBusqueda = document.getElementById('demo-busqueda-piezas');
if (piezasBusqueda) {
    const marcas = ['Esta computadora', 'Puerta de enlace'].map((texto) => {
        const marca = document.createElement('span');
        marca.className = 'marca-equipo';
        marca.textContent = texto;
        return marca;
    });
    piezasBusqueda.append(
        crearInsigniaHallazgo(EQUIPOS_DE_EJEMPLO[1]),
        crearInsigniaHallazgo(EQUIPOS_DE_EJEMPLO[3]),
        ...marcas,
        crearRegistro(EQUIPOS_DE_EJEMPLO[0]),
        crearRegistro(EQUIPOS_DE_EJEMPLO[3], { alAgregar: agregarDeEjemplo }),
        ...[1, 4, 3, 2, 5].map((i) => crearNombreEquipo(EQUIPOS_DE_EJEMPLO[i])),
    );
    document.getElementById('demo-busqueda-buscando').replaceChildren(crearTablaEquipos(EQUIPOS_DE_EJEMPLO.slice(0, 2), { alAgregar: agregarDeEjemplo }));
    document.getElementById('demo-busqueda-resultado').replaceChildren(crearTablaEquipos(EQUIPOS_DE_EJEMPLO, { alAgregar: agregarDeEjemplo }));
    document.getElementById('demo-busqueda-tarjetas').replaceChildren(...[0, 3, 5].map((i) => crearTarjetaEquipo(EQUIPOS_DE_EJEMPLO[i], { alAgregar: agregarDeEjemplo })));
}
