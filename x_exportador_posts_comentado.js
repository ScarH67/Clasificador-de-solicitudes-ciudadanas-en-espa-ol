

(() => {
    'use strict';

    /*
     * Identificador del panel que aparece sobre X y clave utilizada para
     * guardar temporalmente la lista en localStorage.
     */
    const PANEL_ID = 'x-exportador-posts-panel';
    const STORAGE_KEY = 'x_exportador_posts_v150';

    // Lista acumulada de posts detectados durante el desplazamiento.
    let posts = [];

    // Referencia al observador que detecta cambios en la interfaz dinámica de X.
    let observer = null;

    // Guarda la última URL para detectar navegación interna de X.
    let ultimaURL = location.href;

    /**
    * Normaliza espacios y saltos de línea sin alterar el contenido visible.
     */
    function limpiarTexto(texto) {
        return (texto || '')
            .replace(/\u00A0/g, ' ')
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n[ \t]+/g, '\n')
            .replace(/[ \t]{2,}/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    /**
     * Elimina menciones y hashtags completos.
     *
    * La versión anterior usaba una expresión que podía comenzar a
     * coincidir en la vocal acentuada de una palabra. Por ejemplo:
    *     #TránsitoCDMX
    * podía dejar "nsitoCDMX".
     *
    * Esta versión exige que @ o # estén al inicio del texto o después de
    * un carácter que NO sea una letra, número, guion bajo ni vocal acentuada.
    * Además, acepta letras Unicode mediante la bandera /u y las propiedades
     * \p{L} y \p{N}.
     */
    function quitarMencionesYHashtags(texto) {
        return limpiarTexto(
            texto
                .replace(/(^|[^\p{L}\p{N}_])[@#][\p{L}\p{N}_]+/gu, '$1')
                .replace(/[ \t]{2,}/g, ' ')
        );
    }

    /**
     * Determina si un article corresponde a una respuesta.
     *
    * En la pestaña "Con respuestas", X suele mostrar un texto como
     * "Replying to" o "Respondiendo a" dentro del article de la respuesta.
     * Esos articles se excluyen para conservar solamente el post original.
     */
    function esRespuesta(article) {
        const textoCompleto = limpiarTexto(article.innerText || article.textContent);

        const tieneTextoRespuesta = /replying\s+to|respondiendo\s+a/i.test(textoCompleto);

        const tieneEnlaceRespuesta = [...article.querySelectorAll('a')]
            .some(enlace => {
                const texto = limpiarTexto(enlace.innerText || enlace.textContent);
                return /replying\s+to|respondiendo\s+a/i.test(texto);
            });

        return tieneTextoRespuesta || tieneEnlaceRespuesta;
    }

    /**
     * Obtiene la fecha y hora original del post.
     *
     * X suele guardar el valor exacto en <time datetime="...">. El valor
    * ISO se convierte al formato local de México usando un reloj de 24 horas.
     */
    function obtenerFecha(article) {
        const elementoTiempo = article.querySelector('time[datetime]');
        if (!elementoTiempo) return '';

        const valorISO = elementoTiempo.getAttribute('datetime');
        const fecha = new Date(valorISO);

        if (Number.isNaN(fecha.getTime())) return valorISO;

        return new Intl.DateTimeFormat('es-MX', {
            dateStyle: 'short',
            timeStyle: 'medium',
            hour12: false
        }).format(fecha);
    }

    /**
     * Obtiene solo el texto del post principal.
     *
     * Se toma el primer bloque tweetText del article. Esto evita incluir
    * normalmente el texto de una publicación citada o un contenido anidado.
     */
    function obtenerTextoPost(article) {
        const elementosTexto = [
            ...article.querySelectorAll('[data-testid="tweetText"]')
        ];

        if (elementosTexto.length === 0) return '';

        const textoOriginal = limpiarTexto(
            elementosTexto[0].innerText || elementosTexto[0].textContent
        );

        return quitarMencionesYHashtags(textoOriginal);
    }

    /**
     * Obtiene un identificador estable para no guardar dos veces el mismo post.
     */
    function obtenerIdUnico(article, texto) {
        const enlaceEstado = [...article.querySelectorAll('a[href*="/status/"]')]
            .map(enlace => enlace.href)
            .find(Boolean);

        return enlaceEstado || `${texto}|${obtenerFecha(article)}`;
    }

    /**
     * Recorre los articles actualmente cargados, descarta respuestas y
    * agrega únicamente posts originales con texto y fecha.
     */
    function recolectarPosts() {
        const articulos = document.querySelectorAll('article[data-testid="tweet"]');
        const existentes = new Set(posts.map(post => post.id));
        let cantidadNueva = 0;

        articulos.forEach(article => {
            // No guardar el article si representa una respuesta.
            if (esRespuesta(article)) return;

            const texto = obtenerTextoPost(article);
            if (!texto) return;

            const id = obtenerIdUnico(article, texto);
            if (existentes.has(id)) return;

            posts.push({
                id,
                fecha: obtenerFecha(article),
                texto
            });

            existentes.add(id);
            cantidadNueva++;
        });

        guardarPosts();
        actualizarContador();

        if (cantidadNueva > 0) {
            mostrarEstado(`Se añadieron ${cantidadNueva} post(s). Total: ${posts.length}.`);
            console.log(`[X-EXPORTADOR] Nuevos: ${cantidadNueva}. Total: ${posts.length}.`);
        }

        return cantidadNueva;
    }

    /** Guarda los posts en el almacenamiento local del sitio. */
    function guardarPosts() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(posts));
        } catch (error) {
            console.warn('[X-EXPORTADOR] No se pudo guardar la lista:', error);
        }
    }

    /** Recupera los posts guardados al recargar la página. */
    function cargarPostsGuardados() {
        try {
            const datos = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');

            if (!Array.isArray(datos)) return;

            posts = datos.filter(post =>
                post &&
                typeof post.id === 'string' &&
                typeof post.texto === 'string'
            );
        } catch (error) {
            posts = [];
            console.warn('[X-EXPORTADOR] No se pudo recuperar la lista:', error);
        }
    }

    /** Escapa comillas para que el texto sea válido dentro de un CSV. */
    function escaparCSV(valor) {
        return `"${String(valor ?? '').replace(/"/g, '""')}"`;
    }

    /** Crea un nombre de archivo basado en el perfil y la hora actual. */
    function nombreArchivo(extension) {
        const partesURL = location.pathname.split('/').filter(Boolean);
        const usuario = (partesURL[0] || 'posts').replace(/[^\w.-]/g, '_');
        const fecha = new Date().toISOString()
            .replace(/[:.]/g, '-')
            .replace('T', '_')
            .slice(0, 19);

        return `x_${usuario}_posts_${fecha}.${extension}`;
    }

    /** Descarga el contenido generado como archivo local. */
    function descargarArchivo(nombre, contenido, tipoMime) {
        const archivo = new Blob([contenido], {
            type: `${tipoMime};charset=utf-8`
        });

        const url = URL.createObjectURL(archivo);
        const enlace = document.createElement('a');

        enlace.href = url;
        enlace.download = nombre;
        enlace.style.display = 'none';

        document.body.appendChild(enlace);
        enlace.click();
        enlace.remove();

        setTimeout(() => URL.revokeObjectURL(url), 3000);
        console.log(`[X-EXPORTADOR] Archivo generado: ${nombre}`);
    }

    /** Exporta fecha y texto en formato TXT. */
    function exportarTXT() {
        recolectarPosts();

        if (posts.length === 0) {
            mostrarEstado('No hay posts originales detectados.', true);
            return;
        }

        const contenido = posts
            .map(post => `${post.fecha}\n${post.texto}`)
            .join('\n\n');

        descargarArchivo(nombreArchivo('txt'), contenido, 'text/plain');
        mostrarEstado(`TXT descargado con ${posts.length} post(s).`);
    }

    /** Exporta dos columnas: fecha_hora y texto. */
    function exportarCSV() {
        recolectarPosts();

        if (posts.length === 0) {
            mostrarEstado('No hay posts originales detectados.', true);
            return;
        }

        const filas = posts.map(post => [post.fecha, post.texto]
            .map(escaparCSV)
            .join(','));

        const contenido = '\uFEFFfecha_hora,texto\n' + filas.join('\n');

        descargarArchivo(nombreArchivo('csv'), contenido, 'text/csv');
        mostrarEstado(`CSV descargado con ${posts.length} post(s).`);
    }

    /** Borra la lista actual y los datos guardados localmente. */
    function borrarLista() {
        posts = [];
        localStorage.removeItem(STORAGE_KEY);
        actualizarContador();
        mostrarEstado('Lista borrada.');
    }

    /** Actualiza el contador visible del panel. */
    function actualizarContador() {
        const contador = document.querySelector(`#${PANEL_ID} .xep-contador`);
        if (contador) contador.textContent = `Posts guardados: ${posts.length}`;
    }

    /** Muestra información de estado o error dentro del panel. */
    function mostrarEstado(mensaje, esError = false) {
        const estado = document.querySelector(`#${PANEL_ID} .xep-estado`);
        if (!estado) return;

        estado.textContent = mensaje;
        estado.style.setProperty(
            'color',
            esError ? '#ff7b72' : '#8bffb0',
            'important'
        );
    }

    /** Aplica estilos con !important para evitar conflictos con X. */
    function forzar(elemento, propiedades) {
        Object.entries(propiedades).forEach(([propiedad, valor]) => {
            elemento.style.setProperty(propiedad, valor, 'important');
        });
    }

    /** Elimina paneles antiguos que pudieran haber quedado en el DOM. */
    function eliminarPaneles() {
        document.querySelectorAll(`#${PANEL_ID}`).forEach(elemento => elemento.remove());
    }

    /** Crea el panel flotante y conecta los eventos de sus botones. */
    function crearPanel() {
        eliminarPaneles();

        const panel = document.createElement('div');
        panel.id = PANEL_ID;

        panel.innerHTML = `
            <div class="xep-titulo">Exportador de textos de X</div>
            <div class="xep-contador">Posts guardados: 0</div>
            <button class="xep-btn xep-actualizar" type="button">Actualizar lista</button>
            <button class="xep-btn xep-txt" type="button">Descargar TXT</button>
            <button class="xep-btn xep-csv" type="button">Descargar CSV</button>
            <button class="xep-btn xep-limpiar" type="button">Borrar lista</button>
            <div class="xep-estado">Carga posts y pulsa Actualizar lista.</div>
        `;

        forzar(panel, {
            position: 'fixed',
            top: '90px',
            right: '16px',
            width: '235px',
            'max-width': '90vw',
            padding: '12px',
            'box-sizing': 'border-box',
            background: 'rgba(15,15,15,0.98)',
            border: '1px solid #4d4d4d',
            'border-radius': '14px',
            color: '#f5f5f5',
            'font-family': 'Arial, Helvetica, sans-serif',
            'box-shadow': '0 8px 25px rgba(0,0,0,0.6)',
            'z-index': '2147483647',
            isolation: 'isolate',
            transform: 'translateZ(0)',
            'pointer-events': 'auto'
        });

        document.documentElement.appendChild(panel);

        forzar(panel.querySelector('.xep-titulo'), {
            'margin-bottom': '8px',
            'font-size': '15px',
            'font-weight': '700',
            color: '#ffffff'
        });

        forzar(panel.querySelector('.xep-contador'), {
            'margin-bottom': '10px',
            color: '#a9a9a9',
            'font-size': '13px'
        });

        const colores = {
            'xep-actualizar': '#1d9bf0',
            'xep-txt': '#00a878',
            'xep-csv': '#6c63ff',
            'xep-limpiar': '#555555'
        };

        panel.querySelectorAll('.xep-btn').forEach(boton => {
            const clase = [...boton.classList].find(c => colores[c]);

            forzar(boton, {
                display: 'block',
                width: '100%',
                margin: '7px 0',
                padding: '9px 10px',
                border: '0',
                'border-radius': '999px',
                cursor: 'pointer',
                color: '#ffffff',
                'font-size': '13px',
                'font-weight': '700',
                background: colores[clase]
            });
        });

        forzar(panel.querySelector('.xep-estado'), {
            'margin-top': '10px',
            color: '#8bffb0',
            'font-size': '11px',
            'line-height': '1.35'
        });

        panel.querySelector('.xep-actualizar').addEventListener('click', () => {
            const nuevos = recolectarPosts();
            if (nuevos === 0) {
                mostrarEstado(`No había posts nuevos. Total: ${posts.length}.`);
            }
        });

        panel.querySelector('.xep-txt').addEventListener('click', exportarTXT);
        panel.querySelector('.xep-csv').addEventListener('click', exportarCSV);
        panel.querySelector('.xep-limpiar').addEventListener('click', borrarLista);

        actualizarContador();
        console.log('[X-EXPORTADOR] Panel creado y eventos conectados.');
    }

    /**
     * Observa la interfaz de X porque los posts aparecen y desaparecen
    * dinámicamente durante el desplazamiento.
     */
    function observarCambios() {
        if (observer) observer.disconnect();

        observer = new MutationObserver(() => {
            recolectarPosts();

            if (!document.getElementById(PANEL_ID)) {
                crearPanel();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    /** Inicializa almacenamiento, panel, recopilación y observador. */
    function iniciar() {
        cargarPostsGuardados();
        crearPanel();
        recolectarPosts();
        observarCambios();

        console.log('[X-EXPORTADOR] v1.5.0 iniciado.');
    }

    /**
    * Revisa periódicamente si cambió la ruta interna de X y recoge nuevos
    * posts cargados después del desplazamiento.
     */
    setInterval(() => {
        if (location.href !== ultimaURL) {
            ultimaURL = location.href;
            posts = [];
            localStorage.removeItem(STORAGE_KEY);
            console.log('[X-EXPORTADOR] Nueva página detectada; lista reiniciada.');
        }

        recolectarPosts();
    }, 2000);

    iniciar();
})();
