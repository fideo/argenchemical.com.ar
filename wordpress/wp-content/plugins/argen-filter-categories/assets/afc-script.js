/**
 * Argen Filter Categories — v1.2.0
 * Archivo: assets/afc-script.js
 *
 * Responsabilidades:
 * 1. Toggle abierto/cerrado del widget (opción configurable en el backend)
 * 2. Checkboxes → AJAX → resultados en el contenido principal
 * 3. Acordeón de subcategorías
 * 4. Ocultar/restaurar el loop nativo de WooCommerce
 * 5. Paginación dinámica
 * 6. Re-inicializar el stepper de argen-quote-loop en los resultados AJAX
 */

(function () {
    'use strict';

    var DEBUG    = false;   // ← true para activar logs en consola
    var DEBOUNCE = 260;     // ms de espera tras el último cambio de checkbox

    // ── Referencias globales ───────────────────
    var resultsArea  = null;
    var resultsInner = null;
    var countText    = null;
    var closeBtn     = null;
    var clearBtn     = null;
    var nativeLoop   = null;
    var debounceTimer;
    var currentPage  = 1;

    // ── Helpers ────────────────────────────────
    function log() {
        if (DEBUG && window.console) {
            console.log.apply(console, ['[AFC]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    function getCheckedIds() {
        var boxes = document.querySelectorAll('.afc-cat-checkbox:checked');
        return Array.prototype.map.call(boxes, function (cb) {
            return parseInt(cb.value, 10);
        });
    }

    function getConfig() {
        return {
            cols:    parseInt(resultsArea.dataset.cols, 10)    || 3,
            perPage: parseInt(resultsArea.dataset.perPage, 10) || 9,
        };
    }

    // ──────────────────────────────────────────
    // A. TOGGLE ABIERTO / CERRADO DEL WIDGET
    //
    //    Lee data-default-open del .afc-widget-body
    //    (puesto por PHP según la opción del backend).
    //    El visitante puede cambiar el estado; lo
    //    guardamos en sessionStorage para que persista
    //    durante la sesión pero no entre visitas.
    // ──────────────────────────────────────────
    function initWidgetCollapse() {
        var toggleBtns = document.querySelectorAll('.afc-widget-toggle');

        toggleBtns.forEach(function (btn) {
            var bodyId  = btn.getAttribute('aria-controls');
            var body    = bodyId ? document.getElementById(bodyId) : null;
            if (!body) return;

            var defaultOpen = body.dataset.defaultOpen !== '0'; // '1' = abierto
            var storageKey  = 'afc_widget_open_' + bodyId;
            var savedState  = sessionStorage.getItem(storageKey);

            // Determinar estado inicial:
            // - Si el visitante ya interactuó → usar su preferencia guardada en sesión
            // - Si es su primera visita → usar la opción configurada en el backend
            var isOpen;
            if (savedState !== null) {
                isOpen = savedState === '1';
            } else {
                isOpen = defaultOpen;
            }

            applyCollapseState(btn, body, isOpen, false);

            btn.addEventListener('click', function () {
                var currentlyOpen = btn.getAttribute('aria-expanded') === 'true';
                applyCollapseState(btn, body, !currentlyOpen, true);
            });
        });
    }

    /**
     * Aplica el estado abierto/cerrado al widget.
     * @param {HTMLElement} btn    - Botón .afc-widget-toggle
     * @param {HTMLElement} body   - Div .afc-widget-body
     * @param {boolean}     open   - true = abierto
     * @param {boolean}     save   - guardar en sessionStorage
     */
    function applyCollapseState(btn, body, open, save) {
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        body.classList.toggle('afc-body-closed', !open);

        if (save) {
            try {
                sessionStorage.setItem('afc_widget_open_' + body.id, open ? '1' : '0');
            } catch (e) {}
        }

        log('Widget collapse →', open ? 'abierto' : 'cerrado');
    }

    // ──────────────────────────────────────────
    // B. SINCRONIZAR ESTILOS DE CHECKBOXES
    // ──────────────────────────────────────────
    function syncCheckedStyles() {
        document.querySelectorAll('.afc-cat-item').forEach(function (item) {
            var cb = item.querySelector('.afc-cat-checkbox');
            if (cb) item.classList.toggle('is-checked', cb.checked);
        });

        if (clearBtn) {
            clearBtn.style.display = getCheckedIds().length > 0 ? 'flex' : 'none';
        }
    }

    // ──────────────────────────────────────────
    // C. MOSTRAR / OCULTAR ÁREA DE RESULTADOS
    // ──────────────────────────────────────────
    function showResultsArea() {
        if (resultsArea) resultsArea.style.display = '';
        if (nativeLoop)  nativeLoop.style.display  = 'none';
    }

    function hideResultsArea() {
        if (resultsArea) resultsArea.style.display = 'none';
        if (nativeLoop)  nativeLoop.style.display  = '';
    }

    function setLoading(on) {
        if (resultsArea) resultsArea.classList.toggle('afc-is-loading', on);
    }

    // ──────────────────────────────────────────
    // D. RENDERIZAR RESULTADOS
    // ──────────────────────────────────────────
    function renderResults(html, total, pages, page) {
        if (!resultsInner) return;

        if (countText) {
            countText.innerHTML = total > 0
                ? '<strong>' + total + '</strong> ' + afcData.i18n.found
                : '';
        }

        var paginationHtml = pages > 1 ? buildPagination(pages, page) : '';
        resultsInner.innerHTML = html + paginationHtml;

        bindPagination();

        // ── Importante: re-inicializar el stepper de argen-quote-loop ──
        // Los botones −/+ que inyecta el AJAX necesitan que el JS del
        // plugin hermano los "vea". Como quote-loop.js usa delegación
        // de eventos en $(document), los eventos ya funcionan.
        // Sin embargo, si hay algún init adicional (ej: jQuery plugin),
        // disparamos un evento custom para que lo capture.
        triggerQuoteLoopInit();
    }

    /**
     * Dispara evento custom 'afc:results_rendered' en document.
     * argen-quote-loop puede escucharlo si necesita re-inicializar algo.
     * También dispara wc_fragment_refresh por compatibilidad con WooCommerce.
     */
    function triggerQuoteLoopInit() {
        var event;
        try {
            event = new CustomEvent('afc:results_rendered', { bubbles: true });
        } catch (e) {
            // IE fallback
            event = document.createEvent('Event');
            event.initEvent('afc:results_rendered', true, true);
        }
        document.dispatchEvent(event);

        // Si jQuery está disponible, disparar también eventos de WC
        if (window.jQuery) {
            jQuery(document.body).trigger('wc_fragment_refresh');
        }

        log('triggerQuoteLoopInit disparado');
    }

    function renderError() {
        if (resultsInner) {
            resultsInner.innerHTML = '<p class="afc-no-results">' + afcData.i18n.error + '</p>';
        }
        if (countText) countText.innerHTML = '';
    }

    // ──────────────────────────────────────────
    // E. PAGINACIÓN
    // ──────────────────────────────────────────
    function buildPagination(total, current) {
        var html = '<nav class="afc-pagination" aria-label="Paginación">';

        html += '<button class="afc-page-btn" data-page="' + (current - 1) + '" '
            + (current <= 1 ? 'disabled' : '')
            + ' aria-label="Página anterior">‹</button>';

        for (var i = 1; i <= total; i++) {
            html += '<button class="afc-page-btn' + (i === current ? ' is-active' : '') + '" '
                + 'data-page="' + i + '"'
                + (i === current ? ' aria-current="page"' : '')
                + '>' + i + '</button>';
        }

        html += '<button class="afc-page-btn" data-page="' + (current + 1) + '" '
            + (current >= total ? 'disabled' : '')
            + ' aria-label="Página siguiente">›</button>';

        html += '</nav>';
        return html;
    }

    function bindPagination() {
        if (!resultsInner) return;
        resultsInner.querySelectorAll('.afc-page-btn:not(:disabled):not(.is-active)').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var page = parseInt(btn.dataset.page, 10);
                if (!isNaN(page) && page > 0) {
                    currentPage = page;
                    fetchProducts(currentPage);
                    var top = resultsArea.getBoundingClientRect().top + window.pageYOffset - 100;
                    window.scrollTo({ top: top, behavior: 'smooth' });
                }
            });
        });
    }

    // ──────────────────────────────────────────
    // F. AJAX
    // ──────────────────────────────────────────
    function fetchProducts(page) {
        var ids    = getCheckedIds();
        var config = getConfig();

        if (ids.length === 0) { hideResultsArea(); return; }

        showResultsArea();
        setLoading(true);

        var formData = new FormData();
        formData.append('action',   afcData.action);
        formData.append('nonce',    afcData.nonce);
        formData.append('cols',     config.cols);
        formData.append('per_page', config.perPage);
        formData.append('paged',    page || 1);
        ids.forEach(function (id) { formData.append('cat_ids[]', id); });

        if (window.fetch) {
            fetch(afcData.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(handleResponse)
                .catch(function (err) {
                    log('Error fetch:', err);
                    setLoading(false);
                    renderError();
                });
        } else {
            // Fallback XHR para navegadores antiguos
            var xhr = new XMLHttpRequest();
            xhr.open('POST', afcData.ajaxUrl, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                setLoading(false);
                if (xhr.status === 200) {
                    try { handleResponse(JSON.parse(xhr.responseText)); }
                    catch (e) { renderError(); }
                } else { renderError(); }
            };
            xhr.send(formData);
        }
    }

    function handleResponse(data) {
        setLoading(false);
        if (data && data.success) {
            renderResults(data.data.html, data.data.total, data.data.pages, data.data.current);
        } else {
            renderError();
        }
    }

    // ──────────────────────────────────────────
    // G. ACORDEÓN DE SUBCATEGORÍAS
    // ──────────────────────────────────────────
    function initSubcatToggles() {
        document.querySelectorAll('.afc-subcat-toggle').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var item = btn.closest
                    ? btn.closest('.afc-cat-item')
                    : getParentByClass(btn, 'afc-cat-item');

                if (!item) return;

                var isOpen = item.classList.contains('is-open');
                item.classList.toggle('is-open', !isOpen);
                btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            });
        });
    }

    function getParentByClass(el, cls) {
        var current = el.parentElement;
        while (current) {
            if (current.classList && current.classList.contains(cls)) return current;
            current = current.parentElement;
        }
        return null;
    }

    // ──────────────────────────────────────────
    // H. DETECTAR LOOP NATIVO DE WOOCOMMERCE
    //    Astra + WooCommerce puede generar distintos
    //    selectores según la configuración del tema.
    // ──────────────────────────────────────────
    function findNativeLoop() {
        var selectors = [
            'ul.products',
            '.woocommerce-loop-product',
            '.products.columns-3',
            '.products.columns-4',
            '.wc-block-grid__products',
        ];
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (el) { log('Loop nativo:', selectors[i]); return el; }
        }
        log('ADVERTENCIA: loop nativo no encontrado.');
        return null;
    }

    // ──────────────────────────────────────────
    // H. LIMPIAR FILTROS
    // ──────────────────────────────────────────
    function clearAllFilters() {
        document.querySelectorAll('.afc-cat-checkbox').forEach(function (cb) { cb.checked = false; });
        currentPage = 1;
        syncCheckedStyles();
        hideResultsArea();
    }

    // ──────────────────────────────────────────
    // I. INICIALIZACIÓN
    // ──────────────────────────────────────────
    function init() {

        // El área de resultados existe solo en la página de tienda
        resultsArea  = document.getElementById('afc-results-area');
        if (!resultsArea) return;

        resultsInner = resultsArea.querySelector('.afc-results-inner');
        countText    = resultsArea.querySelector('.afc-results-count-text');
        closeBtn     = resultsArea.querySelector('.afc-results-close');
        clearBtn     = document.querySelector('.afc-filter-widget .afc-clear-btn');
        nativeLoop   = findNativeLoop();

        // A. Toggle abierto/cerrado del widget
        initWidgetCollapse();

        // B. Acordeón de subcategorías
        initSubcatToggles();

        // C. Checkboxes
        var checkboxes = document.querySelectorAll('.afc-cat-checkbox');
        log('Checkboxes encontrados:', checkboxes.length);

        checkboxes.forEach(function (cb) {
            cb.addEventListener('change', function () {
                currentPage = 1;
                syncCheckedStyles();
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    var ids = getCheckedIds();
                    if (ids.length === 0) { hideResultsArea(); } else { fetchProducts(currentPage); }
                }, DEBOUNCE);
            });
        });

        // D. Botón "Ver todos los productos"
        if (closeBtn) closeBtn.addEventListener('click', clearAllFilters);

        // E. Botón "Limpiar filtros" del sidebar
        if (clearBtn) clearBtn.addEventListener('click', clearAllFilters);

        // F. Estado visual inicial
        syncCheckedStyles();

        log('Plugin inicializado correctamente.');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
