/**
 * Argen Filter Categories — v1.2.1
 * Archivo: assets/afc-script.js
 *
 * Bloque 1 (IIFE vanilla): filtro de categorías, AJAX, acordeón, colapso widget
 * Bloque 2 (jQuery):        toggle Grilla/Lista + stepper + add-to-quote
 *
 * Fix v1.2.1:
 * - Dependencia jQuery declarada en PHP (ver afc_enqueue_assets)
 * - applyView() re-busca $('ul.products') cada vez → funciona tras render AJAX
 * - Evento 'afc:results_rendered' conecta ambos bloques
 * - quote-loop.js ya NO se carga por separado: stepper y add-to-quote
 *   están aquí delegados en $(document) → funcionan con productos AJAX
 */

/* ══════════════════════════════════════════════════════════════
   BLOQUE 1 — Filtro AJAX + acordeón + colapso widget (vanilla JS)
   ══════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var DEBUG    = false;
    var DEBOUNCE = 260;

    var resultsArea  = null;
    var resultsInner = null;
    var countText    = null;
    var closeBtn     = null;
    var clearBtn     = null;
    var nativeLoop   = null;
    var debounceTimer;
    var currentPage  = 1;

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
            cols:    parseInt(resultsArea.dataset.cols, 10)    || 4,
            perPage: parseInt(resultsArea.dataset.perPage, 10) || 12,
        };
    }

    // ── A. COLAPSO DEL WIDGET ─────────────────────────────────────
    function initWidgetCollapse() {
        var toggleBtns = document.querySelectorAll('.afc-widget-toggle');
        toggleBtns.forEach(function (btn) {
            var bodyId = btn.getAttribute('aria-controls');
            var body   = bodyId ? document.getElementById(bodyId) : null;
            if (!body) return;

            var defaultOpen = body.dataset.defaultOpen !== '0';
            var storageKey  = 'afc_widget_open_' + bodyId;
            var savedState  = sessionStorage.getItem(storageKey);
            var isOpen      = savedState !== null ? savedState === '1' : defaultOpen;

            applyCollapseState(btn, body, isOpen, false);
            btn.addEventListener('click', function () {
                applyCollapseState(btn, body, btn.getAttribute('aria-expanded') !== 'true', true);
            });
        });
    }

    function applyCollapseState(btn, body, open, save) {
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        body.classList.toggle('afc-body-closed', !open);
        if (save) {
            try { sessionStorage.setItem('afc_widget_open_' + body.id, open ? '1' : '0'); } catch (e) {}
        }
    }

    // ── B. SINCRONIZAR ESTILOS DE CHECKBOXES ──────────────────────
    function syncCheckedStyles() {
        document.querySelectorAll('.afc-cat-item').forEach(function (item) {
            var cb = item.querySelector('.afc-cat-checkbox');
            if (cb) item.classList.toggle('is-checked', cb.checked);
        });
        if (clearBtn) {
            clearBtn.style.display = getCheckedIds().length > 0 ? 'flex' : 'none';
        }
    }

    // ── C. MOSTRAR/OCULTAR ÁREA DE RESULTADOS ─────────────────────
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

    // ── D. RENDERIZAR RESULTADOS ──────────────────────────────────
    function renderResults(html, total, pages, page) {
        if (!resultsInner) return;

        if (countText) {
            countText.innerHTML = total > 0
                ? '<strong>' + total + '</strong> ' + afcData.i18n.found
                : '';
        }

        resultsInner.innerHTML = html + (pages > 1 ? buildPagination(pages, page) : '');
        bindPagination();

        // Notificar al Bloque 2 que hay un nuevo ul.products en el DOM
        notifyViewToggle();
    }

    function notifyViewToggle() {
        var ev;
        try { ev = new CustomEvent('afc:results_rendered', { bubbles: true }); }
        catch (e) { ev = document.createEvent('Event'); ev.initEvent('afc:results_rendered', true, true); }
        document.dispatchEvent(ev);
        log('afc:results_rendered disparado');
    }

    function renderError() {
        if (resultsInner) {
            resultsInner.innerHTML = '<p class="afc-no-results">' + afcData.i18n.error + '</p>';
        }
        if (countText) countText.innerHTML = '';
    }

    // ── E. PAGINACIÓN ─────────────────────────────────────────────
    function buildPagination(total, current) {
        var html = '<nav class="afc-pagination" aria-label="Paginación">';
        html += '<button class="afc-page-btn" data-page="' + (current - 1) + '" '
            + (current <= 1 ? 'disabled' : '') + ' aria-label="Página anterior">‹</button>';
        for (var i = 1; i <= total; i++) {
            html += '<button class="afc-page-btn' + (i === current ? ' is-active' : '') + '" '
                + 'data-page="' + i + '"' + (i === current ? ' aria-current="page"' : '') + '>' + i + '</button>';
        }
        html += '<button class="afc-page-btn" data-page="' + (current + 1) + '" '
            + (current >= total ? 'disabled' : '') + ' aria-label="Página siguiente">›</button>';
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
                    window.scrollTo({ top: resultsArea.getBoundingClientRect().top + window.pageYOffset - 100, behavior: 'smooth' });
                }
            });
        });
    }

    // ── F. AJAX ───────────────────────────────────────────────────
    function fetchProducts(page) {
        var ids = getCheckedIds(), config = getConfig();
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
                .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
                .then(handleResponse)
                .catch(function (err) { log('Fetch error:', err); setLoading(false); renderError(); });
        } else {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', afcData.ajaxUrl, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                setLoading(false);
                if (xhr.status === 200) { try { handleResponse(JSON.parse(xhr.responseText)); } catch (e) { renderError(); } }
                else { renderError(); }
            };
            xhr.send(formData);
        }
    }

    function handleResponse(data) {
        setLoading(false);
        if (data && data.success) { renderResults(data.data.html, data.data.total, data.data.pages, data.data.current); }
        else { renderError(); }
    }

    // ── G. ACORDEÓN DE SUBCATEGORÍAS ─────────────────────────────
    function initSubcatToggles() {
        document.querySelectorAll('.afc-subcat-toggle').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                var item = btn.closest ? btn.closest('.afc-cat-item') : getParentByClass(btn, 'afc-cat-item');
                if (!item) return;
                var isOpen = item.classList.contains('is-open');
                item.classList.toggle('is-open', !isOpen);
                btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            });
        });
    }

    function getParentByClass(el, cls) {
        var cur = el.parentElement;
        while (cur) { if (cur.classList && cur.classList.contains(cls)) return cur; cur = cur.parentElement; }
        return null;
    }

    // ── H. DETECTAR LOOP NATIVO ───────────────────────────────────
    function findNativeLoop() {
        var sels = ['ul.products', '.products.columns-3', '.products.columns-4', '.wc-block-grid__products'];
        for (var i = 0; i < sels.length; i++) {
            var el = document.querySelector(sels[i]);
            if (el) { log('Loop nativo:', sels[i]); return el; }
        }
        return null;
    }

    // ── I. LIMPIAR FILTROS ────────────────────────────────────────
    function clearAllFilters() {
        document.querySelectorAll('.afc-cat-checkbox').forEach(function (cb) { cb.checked = false; });
        currentPage = 1;
        syncCheckedStyles();
        hideResultsArea();
    }

    // ── J. INIT ───────────────────────────────────────────────────
    function init() {
        resultsArea = document.getElementById('afc-results-area');
        if (!resultsArea) return;

        resultsInner = resultsArea.querySelector('.afc-results-inner');
        countText    = resultsArea.querySelector('.afc-results-count-text');
        closeBtn     = resultsArea.querySelector('.afc-results-close');
        clearBtn     = document.querySelector('.afc-filter-widget .afc-clear-btn');
        nativeLoop   = findNativeLoop();

        initWidgetCollapse();
        initSubcatToggles();

        document.querySelectorAll('.afc-cat-checkbox').forEach(function (cb) {
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

        if (closeBtn) closeBtn.addEventListener('click', clearAllFilters);
        if (clearBtn) clearBtn.addEventListener('click', clearAllFilters);
        syncCheckedStyles();
        log('AFC inicializado.');
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }

})();


/* ══════════════════════════════════════════════════════════════
   BLOQUE 2 — Toggle Grilla/Lista + Stepper + Add-to-Quote (jQuery)

   Por qué applyView re-busca $('ul.products') cada vez:
   quote-loop.js original capturaba $products UNA sola vez al ready().
   Al filtrar por AJAX, el nuevo ul.products no existía en ese momento,
   así que el toggle nunca lo encontraba. Aquí lo resolvemos consultando
   el DOM en el momento exacto del click o del evento 'afc:results_rendered'.
   ══════════════════════════════════════════════════════════════ */
(function ($) {
    'use strict';

    var STORAGE_KEY = 'argenViewMode';
    var currentView = 'grid';

    var LIST_HEADER_HTML = [
        '<div class="argen-list-header">',
        '  <span></span>',
        '  <span>Producto</span>',
        '  <span>Presentación</span>',
        '  <span style="text-align:center">Cantidad</span>',
        '  <span></span>',
        '</div>'
    ].join('');

    // ── APLICAR VISTA ─────────────────────────────────────────────
    function applyView(view, save) {
        currentView = view;

        // Actualizar botones
        $('.argen-view-toggle .argen-toggle-btn').each(function () {
            var active = $(this).data('view') === view;
            $(this).toggleClass('active', active).attr('aria-pressed', active ? 'true' : 'false');
        });

        // Buscar ul.products en el DOM AHORA (incluye el inyectado por AJAX)
        var $all = $('ul.products');

        if (view === 'list') {
            $all.addClass('argen-list-view');
            $all.each(function () {
                if (!$(this).prev('.argen-list-header').length) {
                    $(LIST_HEADER_HTML).insertBefore($(this));
                }
            });
        } else {
            $all.removeClass('argen-list-view');
            $('.argen-list-header').remove();
        }

        if (save) { try { localStorage.setItem(STORAGE_KEY, view); } catch (e) {} }
    }

    // ── INIT TOGGLE ───────────────────────────────────────────────
    function initViewToggle() {
        var $toggle = $('.argen-view-toggle');
        if (!$toggle.length) return;

        if (!$toggle.parent().hasClass('argen-view-toggle-wrap')) {
            $toggle.wrap('<div class="argen-view-toggle-wrap"></div>');
        }

        // Aplicar vista guardada
        applyView(localStorage.getItem(STORAGE_KEY) || 'grid', false);

        // Click en botones
        $toggle.on('click', '.argen-toggle-btn', function () {
            applyView($(this).data('view'), true);
        });
    }

    // ── ESCUCHAR RENDER AJAX ──────────────────────────────────────
    // Cuando el Bloque 1 inyecta productos nuevos, re-aplicamos la vista
    // actual para que el ul recién creado quede con la clase correcta.
    $(document).on('afc:results_rendered', function () {
        applyView(currentView, false);
    });

    // ── STEPPER ───────────────────────────────────────────────────
    $(document).on('click', '.argen-qty-minus, .argen-qty-plus', function (e) {
        e.preventDefault();
        var $btn   = $(this);
        var $input = $btn.closest('.argen-qty-wrapper').find('.argen-qty-input');
        var val    = parseInt($input.val(), 10) || 1;
        var min    = parseInt($input.attr('min'), 10) || 1;
        var max    = parseInt($input.attr('max'), 10) || 9999;
        $input.val($btn.hasClass('argen-qty-minus') ? Math.max(min, val - 1) : Math.min(max, val + 1));
    });

    $(document).on('change blur', '.argen-qty-input', function () {
        var $i = $(this), v = parseInt($i.val(), 10),
            mn = parseInt($i.attr('min'), 10) || 1, mx = parseInt($i.attr('max'), 10) || 9999;
        if (isNaN(v) || v < mn) $i.val(mn);
        if (v > mx) $i.val(mx);
    });

    // ── ADD TO QUOTE ──────────────────────────────────────────────
    $(document).on('click', '.argen-add-quote-btn', function (e) {
        e.preventDefault();
        var $btn      = $(this);
        var $form     = $btn.closest('.argen-quote-loop-form');
        var $feedback = $form.find('.argen-quote-feedback');
        var productId = $btn.data('product-id');
        var nonce     = $btn.data('nonce');
        var quantity  = $form.find('.argen-qty-input').val() || 1;

        var $selects = $form.find('.argen-variation-select');
        var ok = true;
        $selects.each(function () { if ($(this).val() === '') { ok = false; return false; } });

        if (!ok) { showFeedback($feedback, argenQuote.i18n.selectOption, 'error'); return; }

        var data = { action: argenQuote.action, product_id: productId, nonce: nonce, quantity: quantity };
        $selects.each(function () { data['attribute_' + $(this).data('attribute')] = $(this).val(); });

        $btn.prop('disabled', true).text(argenQuote.i18n.adding);
        $feedback.removeClass('argen-success argen-error').text('');

        $.ajax({
            url: argenQuote.ajaxUrl, type: 'POST', data: data,
            success: function (res) {
                if (res.success) {
                    showFeedback($feedback, argenQuote.i18n.added, 'success');
                    if (res.data && res.data.quote_count !== undefined) { updateQuoteCount(res.data.quote_count); }
                    $(document.body).trigger('argen_quote_added', [res.data]);
                } else {
                    showFeedback($feedback, (res.data && res.data.message) ? res.data.message : argenQuote.i18n.error, 'error');
                }
            },
            error: function () { showFeedback($feedback, argenQuote.i18n.error, 'error'); },
            complete: function () { $btn.prop('disabled', false).text('Agregar'); },
        });
    });

    function showFeedback($el, msg, type) {
        $el.removeClass('argen-success argen-error').addClass('argen-' + type).text(msg);
        if (type === 'success') { setTimeout(function () { $el.text('').removeClass('argen-success'); }, 3500); }
    }

    function updateQuoteCount(count) {
        $('.yith-ywraq-add-to-quote-button .count, .ywraq-count, .quote-count, [data-quote-count]').text(count);
        $('[data-quote-count]').attr('data-quote-count', count);
    }

    $(document).ready(function () {
        initViewToggle();
    });

})(jQuery);
