/*
 * Gráficas con Chart.js (web/vendor/chart.js, cargado como script normal: window.Chart).
 * - Los colores se leen de los tokens con getComputedStyle: aquí no hay colores escritos.
 * - Con prefers-reduced-motion, sin animaciones.
 */

const SERIES = ['--color-grafica-1', '--color-grafica-2', '--color-grafica-3', '--color-grafica-4'];

export function token(nombre) {
    return getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
}

function movimientoReducido() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Color y estilo de la serie i: 4 colores; de la 5.ª a la 8.ª se repiten con línea punteada.
 */
export function estiloSerie(indice) {
    return {
        variable: SERIES[indice % SERIES.length],
        color: token(SERIES[indice % SERIES.length]),
        punteada: indice >= SERIES.length,
    };
}

function opcionesBase({ compacta = false } = {}) {
    const fuenteMono = { family: token('--fuente-mono'), size: 11 };
    const colorTexto = token('--color-texto-2');

    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: movimientoReducido() ? false : { duration: 400 },
        interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: 6, right: 6 } },
        scales: {
            x: {
                grid: { display: false },
                border: { display: false },
                ticks: { color: colorTexto, font: fuenteMono, maxRotation: 0, autoSkip: true, maxTicksLimit: compacta ? 4 : 10 },
            },
            y: {
                beginAtZero: true,
                grid: { color: token('--color-borde-suave') },
                border: { display: false, dash: [3, 4] },
                ticks: { color: colorTexto, font: fuenteMono, maxTicksLimit: 5, padding: 8 },
            },
        },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: token('--color-base'),
                titleColor: token('--color-base-texto-2'),
                bodyColor: token('--color-superficie'),
                titleFont: fuenteMono,
                bodyFont: { family: token('--fuente-mono'), size: 12 },
                padding: 10,
                cornerRadius: 8,
                boxWidth: 10,
                boxHeight: 3,
                boxPadding: 4,
                usePointStyle: false,
            },
        },
    };
}

/**
 * Líneas de tiempos de respuesta (dashboard). series: [{ nombre, datos (ms o null) }].
 * El último punto de cada serie se marca con un círculo.
 */
export function crearGraficaLineas(canvas, { etiquetas, series, compacta = false }) {
    const fondoPunto = token('--color-superficie');
    const ultimo = etiquetas.length - 1;

    const conjuntos = series.map((serie, i) => {
        const estilo = estiloSerie(i);
        return {
            label: serie.nombre,
            data: serie.datos,
            borderColor: estilo.color,
            backgroundColor: estilo.color,
            borderWidth: 2,
            borderDash: estilo.punteada ? [6, 4] : [],
            tension: 0.4,
            cubicInterpolationMode: 'monotone', // sin curvas por debajo de 0 ms
            spanGaps: false,
            pointRadius: (contexto) => (contexto.dataIndex === ultimo ? 4 : 0),
            pointHoverRadius: 4,
            pointBackgroundColor: fondoPunto,
            pointBorderColor: estilo.color,
            pointBorderWidth: 2,
        };
    });

    const opciones = opcionesBase({ compacta });
    opciones.plugins.tooltip.callbacks = {
        title: (elementos) => `${elementos[0]?.label ?? ''} · ms`,
        label: (elemento) => ` ${elemento.dataset.label}: ${elemento.parsed.y ?? '—'}`,
    };

    return new window.Chart(canvas, { type: 'line', data: { labels: etiquetas, datasets: conjuntos }, options: opciones });
}

/**
 * Área de tiempos de un dispositivo (Monitoreos). puntos: [{ etiqueta, ms (o null si no respondió) }].
 * Las comprobaciones sin respuesta se marcan con un punto rojo sobre el eje.
 */
export function crearGraficaArea(canvas, { puntos, compacta = false }) {
    const acento = token('--color-acento');
    const inactivo = token('--color-inactivo');

    const opciones = opcionesBase({ compacta });
    opciones.plugins.tooltip.callbacks = {
        title: (elementos) => elementos[0]?.label ?? '',
        label: (elemento) => (elemento.datasetIndex === 1
            ? ' Sin respuesta'
            : ` Tiempo de respuesta: ${elemento.parsed.y} ms`),
    };
    opciones.plugins.tooltip.filter = (elemento) => elemento.parsed.y !== null;

    return new window.Chart(canvas, {
        type: 'line',
        data: {
            labels: puntos.map((p) => p.etiqueta),
            datasets: [
                {
                    label: 'Tiempo de respuesta (ms)',
                    data: puntos.map((p) => p.ms),
                    borderColor: acento,
                    backgroundColor: token('--color-acento-fondo'),
                    fill: 'origin',
                    borderWidth: 2,
                    tension: 0.4,
            cubicInterpolationMode: 'monotone', // sin curvas por debajo de 0 ms
                    spanGaps: false,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointBackgroundColor: token('--color-superficie'),
                    pointBorderColor: acento,
                },
                {
                    label: 'Sin respuesta',
                    data: puntos.map((p) => (p.ms === null ? 0 : null)),
                    showLine: false,
                    pointRadius: 4,
                    pointHoverRadius: 5,
                    pointBackgroundColor: inactivo,
                    pointBorderColor: inactivo,
                },
            ],
        },
        options: opciones,
    });
}

/**
 * Destruye la gráfica anterior del lienzo, si la hay (al volver a dibujar).
 */
export function destruirGrafica(canvas) {
    window.Chart.getChart(canvas)?.destroy();
}
