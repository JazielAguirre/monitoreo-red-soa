/*
 * Estructura común de las pantallas, generada desde un solo módulo (no copiada en
 * cada HTML): barra lateral, encabezado móvil con su menú desplegable y el contador
 * de alertas pendientes del menú.
 *
 * Cada página tiene <aside id="barra-lateral"> y <header id="encabezado-movil">
 * vacíos (así el espacio queda reservado antes de que corra JavaScript) y
 * <body data-pagina="…"> para marcar la página activa.
 */

import { api } from '../api.js';
import { mostrarNotificacion } from './notificaciones.js';

const PAGINAS = [
    { id: 'index', href: 'index.html', icono: 'bi-grid-1x2', texto: 'Dashboard' },
    { id: 'dispositivos', href: 'dispositivos.html', icono: 'bi-hdd-network', texto: 'Dispositivos' },
    { id: 'monitoreos', href: 'monitoreos.html', icono: 'bi-activity', texto: 'Monitoreos' },
    { id: 'alertas', href: 'alertas.html', icono: 'bi-bell', texto: 'Alertas', contador: true },
];

// Hasta la Fase 7 (inicio de sesión) el usuario es fijo, como en el diseño.
const USUARIO = { nombre: 'Administrador', usuario: 'admin', iniciales: 'AD' };

/**
 * Crea un elemento con clase y atributos. Los textos se asignan con textContent.
 */
function elemento(etiqueta, clase = '', atributos = {}, texto = null) {
    const nodo = document.createElement(etiqueta);

    if (clase) {
        nodo.className = clase;
    }
    for (const [nombre, valor] of Object.entries(atributos)) {
        nodo.setAttribute(nombre, valor);
    }
    if (texto !== null) {
        nodo.textContent = texto;
    }

    return nodo;
}

function icono(clase) {
    return elemento('i', `bi ${clase}`, { 'aria-hidden': 'true' });
}

function crearMarca(titulo, subtitulo = null) {
    const marca = elemento('div', 'marca');
    const logo = elemento('span', 'marca__logo', { 'aria-hidden': 'true' });
    logo.append(icono('bi-diagram-3-fill'));
    marca.append(logo);

    if (subtitulo === null) {
        return { marca, logo };
    }

    const textos = elemento('div', 'marca__texto');
    textos.append(elemento('span', 'marca__titulo', {}, titulo), elemento('span', 'marca__subtitulo', {}, subtitulo));
    marca.append(textos);

    return { marca, logo };
}

function crearContador(clase = 'contador') {
    return elemento('span', `${clase} js-contador-alertas`, { hidden: '' });
}

function crearNavegacion(paginaActiva) {
    const nav = elemento('nav', 'nav-lateral', { 'aria-label': 'Navegación principal' });

    for (const pagina of PAGINAS) {
        const enlace = elemento('a', 'nav-lateral__enlace', { href: pagina.href });
        if (pagina.id === paginaActiva) {
            enlace.classList.add('active');
            enlace.setAttribute('aria-current', 'page');
        }
        enlace.append(icono(pagina.icono), pagina.texto);
        if (pagina.contador) {
            enlace.append(crearContador());
        }
        nav.append(enlace);
    }

    return nav;
}

function avisarSalir() {
    mostrarNotificacion({
        tipo: 'info',
        icono: 'bi-box-arrow-right',
        titulo: 'Cerrar sesión',
        texto: 'El inicio y cierre de sesión se habilitan en la Fase 7.',
    });
}

/**
 * Llena la barra lateral: logo con el punto verde, "MENÚ", navegación y usuario al pie.
 */
export function llenarBarraLateral(aside, paginaActiva) {
    const { marca } = crearMarca('Monitoreo de Red', 'SOA · Laboratorio');
    const nav = crearNavegacion(paginaActiva);
    nav.prepend(elemento('span', 'nav-lateral__titulo', { 'aria-hidden': 'true' }, 'MENÚ'));

    const usuario = elemento('div', 'usuario-barra');
    const datos = elemento('div', 'usuario-barra__datos');
    datos.append(elemento('span', 'usuario-barra__nombre', {}, USUARIO.nombre), elemento('span', 'usuario-barra__rol', {}, USUARIO.usuario));
    const salir = elemento('button', 'usuario-barra__salir', { type: 'button', 'aria-label': 'Salir', title: 'Salir' });
    salir.append(icono('bi-box-arrow-right'));
    salir.addEventListener('click', avisarSalir);
    usuario.append(elemento('span', 'avatar', { 'aria-hidden': 'true' }, USUARIO.iniciales), datos, salir);

    aside.replaceChildren(marca, nav, usuario);
    aside.setAttribute('aria-label', 'Barra lateral');
}

/**
 * Llena el encabezado móvil y crea su menú desplegable (con fondo que lo cierra).
 */
export function llenarEncabezadoMovil(encabezado, paginaActiva) {
    const boton = elemento('button', 'encabezado-movil__boton', {
        type: 'button',
        'aria-label': 'Abrir el menú',
        'aria-expanded': 'false',
        'aria-controls': 'menu-movil',
    });
    boton.append(icono('bi-list'));

    const { logo } = crearMarca('Monitoreo de Red SOA');
    const campana = elemento('a', 'encabezado-movil__boton', { href: 'alertas.html', 'aria-label': 'Alertas' });
    campana.append(icono('bi-bell'), crearContador('contador contador--mini'));

    encabezado.replaceChildren(boton, logo, elemento('span', 'encabezado-movil__titulo', {}, 'Monitoreo de Red SOA'), campana);

    const fondo = elemento('div', 'menu-movil__fondo', { hidden: '' });
    const menu = elemento('div', 'menu-movil', { id: 'menu-movil', hidden: '' });
    const nav = crearNavegacion(paginaActiva);
    nav.setAttribute('aria-label', 'Navegación principal (celular)');
    nav.className = 'menu-movil__nav';

    const usuario = elemento('div', 'menu-movil__usuario');
    const datos = elemento('div', 'usuario-barra__datos');
    datos.append(elemento('span', 'usuario-barra__nombre', {}, USUARIO.nombre), elemento('span', 'usuario-barra__rol', {}, USUARIO.usuario));
    const salir = elemento('button', 'menu-movil__salir', { type: 'button' });
    salir.append(icono('bi-box-arrow-right'), 'Salir');
    salir.addEventListener('click', avisarSalir);
    usuario.append(elemento('span', 'avatar', { 'aria-hidden': 'true' }, USUARIO.iniciales), datos, salir);

    menu.append(nav, usuario);
    encabezado.after(fondo, menu);
    conectarMenu(boton, menu, fondo);
}

function conectarMenu(boton, menu, fondo) {
    const abrir = () => {
        menu.hidden = false;
        fondo.hidden = false;
        boton.setAttribute('aria-expanded', 'true');
        boton.setAttribute('aria-label', 'Cerrar el menú');
        boton.firstElementChild.className = 'bi bi-x-lg';
        menu.querySelector('a')?.focus();
    };
    const cerrar = (devolverFoco = true) => {
        if (menu.hidden) {
            return;
        }
        menu.hidden = true;
        fondo.hidden = true;
        boton.setAttribute('aria-expanded', 'false');
        boton.setAttribute('aria-label', 'Abrir el menú');
        boton.firstElementChild.className = 'bi bi-list';
        if (devolverFoco) {
            boton.focus();
        }
    };

    boton.addEventListener('click', () => (menu.hidden ? abrir() : cerrar()));
    fondo.addEventListener('click', () => cerrar());
    document.addEventListener('keydown', (evento) => {
        if (evento.key === 'Escape') {
            cerrar();
        }
    });
    window.matchMedia('(min-width: 992px)').addEventListener('change', (consulta) => {
        if (consulta.matches) {
            cerrar(false);
        }
    });
}

/**
 * Pide el conteo de alertas y actualiza todos los contadores del menú.
 * Si falla, los contadores se ocultan (la página sigue funcionando).
 */
export async function actualizarContadorAlertas() {
    let pendientes = 0;

    try {
        pendientes = (await api.alertas.conteo()).pendientes;
    } catch {
        pendientes = 0;
    }

    for (const contador of document.querySelectorAll('.js-contador-alertas')) {
        contador.textContent = String(pendientes);
        contador.hidden = pendientes === 0;
        contador.setAttribute('aria-label', pendientes === 1 ? '1 alerta pendiente' : `${pendientes} alertas pendientes`);
    }

    return pendientes;
}

/**
 * Monta la estructura de la página actual y carga el contador de alertas.
 */
export function montarEstructura() {
    const paginaActiva = document.body.dataset.pagina;
    const aside = document.getElementById('barra-lateral');
    const encabezado = document.getElementById('encabezado-movil');

    if (aside) {
        llenarBarraLateral(aside, paginaActiva);
    }
    if (encabezado) {
        llenarEncabezadoMovil(encabezado, paginaActiva);
    }

    actualizarContadorAlertas();
}
