/**
 * Argen Filter Categories
 * Archivo: assets/afc-script.js
 * Versión: 1.0.0
 *
 * Funcionalidades:
 * - Escucha cambios en los checkboxes del widget
 * - Envía petición AJAX con las categorías seleccionadas
 * - Renderiza los productos sin recargar la página
 * - Paginación dinámica
 * - Debounce para no saturar el servidor
 * - Estado "is-checked" en los ítems del sidebar
 */

(function () {
    'use strict';

    // ── Configuración ──────────────────────────
    var DEBUG    = false;     // ← true para ver logs en consola
    var DEBOUNCE = 280;       // ms de espera tras el último check

    // ── Referencias al DOM ─────────────────────
    var widget      = null;   // .afc-filter-widget o .afc-widget-inner
    var resultsArea = null;   // #afc-results-area
    var clearBtn    = null;   // .afc-clear-btn
    var debounceTimer;
    var currentPage = 1;

    // ── Helpers ────────────────────────────────
    function log() {
        if (DEBUG && window.console) {
            console.log.apply(console, ['[AFC]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    /**
     * Obtiene los IDs de categorías actualmente marcadas.
     * @returns {number[]}
     */
    function getCheckedIds() {
        var boxes = document.querySelectorAll('.afc-cat-checkbox:checked');
        return Array.prototype.map.call(boxes, function (cb) {
            return parseInt(cb.value, 10);
        });
    }

    /**
     * Lee la configuración del área de resultados (cols, per_page).
     * @returns {{cols: number, perPage: number}}
     */
    function getConfig() {
        var cols    = parseInt(resultsArea.dataset.cols, 10)    || 3;
        var perPage = parseInt(resultsArea.dataset.perPage, 10) || 9;
        return { cols: cols, perPage: perPage };
    }

    // ── UI helpers ─────────────────────────────

    /**
     * Sincroniza la clase .is-checked de cada .afc-cat-item
     * con el estado de su checkbox.
     */
    function syncCheckedStyles() {
        var items = document.querySelectorAll('.afc-cat-item');
        items.forEach(function (item) {
            var cb = item.querySelector('.afc-cat-checkbox');
            if (!cb) return;
            item.classList.toggle('is-checked', cb.checked);
        });

        var ids = getCheckedIds();
        if (clearBtn) {
            clearBtn.style.display = ids.length > 0 ? 'flex' : 'none';
        }
    }

    /**
     * Muestra u oculta el estado de carga.
     * @param {boolean} loading
     */
    function setLoading(loading) {
        if (!resultsArea) return;
        resultsArea.classList.toggle('afc-loading', loading);
        log('loading:', loading);
    }

    /**
     * Inserta el HTML de resultados y el área de meta-info.
     * @param {string}  html
     * @param {number}  total
     * @param {number}  pages
     * @param {number}  page
     */
    function renderResults(html, total, pages, page) {
        var inner = resultsArea.querySelector('.afc-results-inner');
        if (!inner) return;

        // Meta info
        var metaHtml = '';
        if (total > 0) {
            var ids = getCheckedIds();
            var catText = ids.length > 0
                ? afcData.i18n.results
                : afcData.i18n.results;

            metaHtml = '<div class="afc-results-meta">'
                + '<span class="afc-results-count"><strong>' + total + '</strong> ' + catText + '</span>'
                + '</div>';
        }

        // Paginación
        var paginationHtml = pages > 1 ? buildPagination(pages, page) : '';

        inner.innerHTML = metaHtml + html + paginationHtml;

        // Binding de la paginación
        bindPagination();

        // Scroll suave al área de resultados (solo si el usuario scrolleó)
        if (page > 1 || getCheckedIds().length > 0) {
            scrollToResults();
        }
    }

    /**
     * Construye el HTML de la paginación.
     */
    function buildPagination(total, current) {
        var html = '<nav class="afc-pagination" aria-label="Paginación de productos">';

        // Botón anterior
        html += '<button class="afc-page-btn afc-prev" data-page="' + (current - 1) + '" '
            + (current <= 1 ? 'disabled' : '')
            + ' aria-label="Página anterior">‹</button>';

        // Páginas numeradas
        for (var i = 1; i <= total; i++) {
            html += '<button class="afc-page-btn ' + (i === current ? 'is-active' : '') + '" '
                + 'data-page="' + i + '" '
                + (i === current ? 'aria-current="page"' : '')
                + '>' + i + '</button>';
        }

        // Botón siguiente
        html += '<button class="afc-page-btn afc-next" data-page="' + (current + 1) + '" '
            + (current >= total ? 'disabled' : '')
            + ' aria-label="Página siguiente">›</button>';

        html += '</nav>';
        return html;
    }

    /**
     * Agrega eventos a los botones de paginación.
     */
    function bindPagination() {
        var btns = resultsArea.querySelectorAll('.afc-page-btn:not(:disabled):not(.is-active)');
        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var page = parseInt(btn.dataset.page, 10);
                if (!isNaN(page)) {
                    currentPage = page;
                    fetchProducts(currentPage);
                }
            });
        });
    }

    /**
     * Scroll suave hacia el área de resultados.
     */
    function scrollToResults() {
        if (!resultsArea) return;
        var offset = resultsArea.getBoundingClientRect().top + window.pageYOffset - 80;
        window.scrollTo({ top: offset, behavior: 'smooth' });
    }

    /**
     * Estado vacío (ningún checkbox activo).
     */
    function renderEmptyState() {
        var inner = resultsArea.querySelector('.afc-results-inner');
        if (!inner) return;
        inner.innerHTML = '<div class="afc-empty-state">'
            + '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">'
            + '<rect x="2" y="3" width="20" height="14" rx="2"/>'
            + '<path d="M8 21h8M12 17v4"/>'
            + '</svg>'
            + '<p>Seleccioná una o más categorías<br>para ver los productos.</p>'
            + '</div>';
    }

    // ── AJAX ───────────────────────────────────

    /**
     * Realiza la petición AJAX y actualiza el área de resultados.
     * @param {number} page  — Número de página a cargar
     */
    function fetchProducts(page) {

        var ids    = getCheckedIds();
        var config = getConfig();

        log('fetchProducts — ids:', ids, '| page:', page, '| config:', config);

        // Si no hay nada seleccionado → mostrar estado vacío
        if (ids.length === 0) {
            setLoading(false);
            renderEmptyState();
            return;
        }

        setLoading(true);

        // Construir FormData
        var formData = new FormData();
        formData.append('action',   afcData.action);
        formData.append('nonce',    afcData.nonce);
        formData.append('cols',     config.cols);
        formData.append('per_page', config.perPage);
        formData.append('paged',    page || 1);

        ids.forEach(function (id) {
            formData.append('cat_ids[]', id);
        });

        // Fetch API (moderno, con fallback XMLHttpRequest)
        if (window.fetch) {
            fetch(afcData.ajaxUrl, {
                method:      'POST',
                credentials: 'same-origin',
                body:        formData,
            })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                setLoading(false);
                if (data.success) {
                    renderResults(data.data.html, data.data.total, data.data.pages, data.data.current);
                } else {
                    renderError();
                }
            })
            .catch(function (err) {
                log('Error fetch:', err);
                setLoading(false);
                renderError();
            });
        } else {
            // Fallback XHR para IE/navegadores muy viejos
            var xhr = new XMLHttpRequest();
            xhr.open('POST', afcData.ajaxUrl, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                setLoading(false);
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            renderResults(data.data.html, data.data.total, data.data.pages, data.data.current);
                        } else {
                            renderError();
                        }
                    } catch (e) {
                        renderError();
                    }
                } else {
                    renderError();
                }
            };
            xhr.send(formData);
        }
    }

    /**
     * Muestra un mensaje de error genérico.
     */
    function renderError() {
        var inner = resultsArea && resultsArea.querySelector('.afc-results-inner');
        if (inner) {
            inner.innerHTML = '<p class="afc-no-results">' + afcData.i18n.error + '</p>';
        }
    }

    // ── Evento principal: cambio en checkbox ──

    /**
     * Dispara fetchProducts con debounce para no saturar el servidor
     * cuando el usuario marca varios checkboxes rápidamente.
     */
    function onCheckboxChange() {
        currentPage = 1; // Volver a la primera página al cambiar filtros
        syncCheckedStyles();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            fetchProducts(currentPage);
        }, DEBOUNCE);
    }

    // ── Botón limpiar filtros ──────────────────

    function clearAllFilters() {
        var boxes = document.querySelectorAll('.afc-cat-checkbox');
        boxes.forEach(function (cb) { cb.checked = false; });
        currentPage = 1;
        syncCheckedStyles();
        renderEmptyState();
    }

    // ── Inicialización ─────────────────────────

    function init() {

        // Buscar el área de resultados
        resultsArea = document.getElementById('afc-results-area');

        if (!resultsArea) {
            log('ADVERTENCIA: #afc-results-area no encontrado en el DOM.');
            return;
        }

        // Buscar el widget
        widget = document.querySelector('.afc-filter-widget');
        if (!widget) {
            log('ADVERTENCIA: .afc-filter-widget no encontrado en el DOM.');
            return;
        }

        clearBtn = widget.querySelector('.afc-clear-btn');

        // Sincronizar estilos iniciales
        syncCheckedStyles();

        // Estado inicial sin selección
        renderEmptyState();

        // ── Eventos en checkboxes ──────────────
        var checkboxes = document.querySelectorAll('.afc-cat-checkbox');
        log('Checkboxes encontrados:', checkboxes.length);

        checkboxes.forEach(function (cb) {
            cb.addEventListener('change', onCheckboxChange);
        });

        // ── Evento en botón limpiar ────────────
        if (clearBtn) {
            clearBtn.addEventListener('click', clearAllFilters);
        }

        log('Plugin inicializado correctamente.');
    }

    // Esperar al DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
