/**
 * Argen Category Filter — acf-filter.js  v2.1.2
 *
 * Cambios v2.1.2:
 * - Fix: showNativeLoop() restaura la clase `products` en el <ul> original
 *   (la quitábamos al ocultar pero no la devolvíamos al mostrar, lo que
 *   rompía el estilo del listado al deseleccionar la categoría).
 *
 * Cambios v2.1.0:
 * - Botones Grilla/Lista siempre visibles (no se ocultan al filtrar)
 * - Eliminado el párrafo "Mostrando X-Y de Z resultados" de los filtrados
 * - hideNativeLoop() oculta ul.products, paginación, resultado-count y ordering
 *   pero NUNCA el toggle de vista
 * - El toggle Grilla/Lista de argen-quote-loop actúa sobre los productos
 *   filtrados gracias al evento delegado
 */

(function ($) {
    'use strict';

    var DEBOUNCE_MS = 300;

    // ── Estado ─────────────────────────────────────────────────────
    var state = {
        termIds:  [],
        paged:    1,
        perPage:  0,
        loading:  false,
        filtered: false,
    };

    // ── Referencias capturadas al iniciar ──────────────────────────
    var $woo;
    var $nativeProducts;
    var $nativePagination;
    var $nativeResultCount;
    var $nativeOrdering;
    var $nativeResultCountHTML;

    // Elementos inyectados
    var $filteredProducts   = null;
    var $filteredPagination = null;

    var debounceTimer = null;

    // ──────────────────────────────────────────────────────────────
    // A. INIT
    // ──────────────────────────────────────────────────────────────
    function init() {
        $woo = $('.woocommerce');
        if ( !$woo.length ) return;

        $nativeProducts        = $woo.find('ul.products').first();
        $nativePagination      = $woo.find('.woocommerce-pagination').first();
        $nativeResultCount     = $('.woocommerce-result-count').first();
        $nativeOrdering        = $('.woocommerce-ordering').first();
        $nativeResultCountHTML = $nativeResultCount.html();

        state.perPage = $nativeProducts.data('per-page') || 0;

        initAccordion();
        bindCheckboxes();
        bindClearButton();
        bindPagination();
    }

    // ──────────────────────────────────────────────────────────────
    // B. ACORDEÓN
    // ──────────────────────────────────────────────────────────────
    function initAccordion() {
        $(document).on('click', '.acf-toggle-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $item = $(this).closest('.acf-cat-item');
            var open  = $item.hasClass('acf-is-open');
            $item.toggleClass('acf-is-open', !open);
            $(this).attr('aria-expanded', !open ? 'true' : 'false');
        });
    }

    // ──────────────────────────────────────────────────────────────
    // C. CHECKBOXES
    // ──────────────────────────────────────────────────────────────
    function bindCheckboxes() {
        $(document).on('change', '.acf-checkbox', function () {
            syncCheckedState();
            state.paged = 1;
            clearTimeout( debounceTimer );
            debounceTimer = setTimeout(function () {
                if ( state.termIds.length === 0 ) {
                    clearFilter();
                } else {
                    fetchProducts();
                }
            }, DEBOUNCE_MS );
        });
    }

    function syncCheckedState() {
        state.termIds = [];
        $('.acf-checkbox').each(function () {
            var $cb      = $(this);
            var $item    = $cb.closest('.acf-cat-item');
            var checked  = $cb.is(':checked');
            $item.toggleClass('acf-is-checked', checked);
            if ( checked ) state.termIds.push( parseInt( $cb.val(), 10 ) );
        });
        // Mostrar/ocultar botón "Limpiar filtros"
        $('.acf-clear-btn').toggle( state.termIds.length > 0 );
    }

    // ──────────────────────────────────────────────────────────────
    // D. LIMPIAR
    // ──────────────────────────────────────────────────────────────
    function bindClearButton() {
        $(document).on('click', '.acf-clear-btn', function () {
            $('.acf-checkbox').prop('checked', false);
            syncCheckedState();
            clearFilter();
        });
    }

    // ──────────────────────────────────────────────────────────────
    // E. PAGINACIÓN
    // ──────────────────────────────────────────────────────────────
    function bindPagination() {
        $woo.on('click', '.acf-page:not([disabled])', function () {
            var page = parseInt( $(this).data('page'), 10 );
            if ( !isNaN(page) && page > 0 ) {
                state.paged = page;
                fetchProducts();
                $('html, body').animate({ scrollTop: $woo.offset().top - 80 }, 300);
            }
        });
    }

    // ──────────────────────────────────────────────────────────────
    // F. FETCH AJAX
    // ──────────────────────────────────────────────────────────────
    function fetchProducts() {
        if ( state.loading ) return;
        state.loading = true;
        setLoading( true );

        var postData = {
            action: acfData.action,
            nonce:  acfData.nonce,
            paged:  state.paged,
        };
        $.each( state.termIds, function (i, id) {
            postData['term_ids[' + i + ']'] = id;
        });
        if ( state.perPage > 0 ) postData.per_page = state.perPage;

        $.ajax({
            url:  acfData.ajaxUrl,
            type: 'POST',
            data: postData,
            success: function (res) {
                if ( res.success ) renderProducts( res.data );
                else showError();
            },
            error: showError,
            complete: function () {
                state.loading = false;
                setLoading( false );
            },
        });
    }

    // ──────────────────────────────────────────────────────────────
    // G. RENDERIZAR
    // ──────────────────────────────────────────────────────────────
    function renderProducts( data ) {
        hideNativeLoop();

        // Limpiar anteriores
        if ( $filteredProducts )   { $filteredProducts.remove();   $filteredProducts   = null; }
        if ( $filteredPagination ) { $filteredPagination.remove(); $filteredPagination = null; }
        $woo.find('.acf-result-count-filtered').remove();

        // Insertar productos filtrados
        $filteredProducts = $( data.html ).addClass('acf-results-enter');
        $nativeProducts.before( $filteredProducts );

        // Insertar paginación si existe
        if ( data.pagination ) {
            $filteredPagination = $( data.pagination );
            $filteredProducts.after( $filteredPagination );
        }

        // Aplicar vista grilla/lista actual
        applyCurrentView();

        state.filtered = true;
    }

    // ──────────────────────────────────────────────────────────────
    // H. OCULTAR / MOSTRAR LOOP NATIVO
    //    IMPORTANTE: el toggle Grilla/Lista (.argen-view-toggle-wrap)
    //    NUNCA se oculta — debe seguir visible siempre.
    // ──────────────────────────────────────────────────────────────
    function hideNativeLoop() {
        $nativeProducts.addClass('acf-force-hide');
        $nativeProducts.removeClass('products');
        $nativePagination.addClass('acf-force-hide');
        $nativeResultCount.addClass('acf-force-hide');
        $nativeOrdering.addClass('acf-force-hide');
        $nativeProducts.prev('.argen-list-header').addClass('acf-force-hide');
        $('.woocommerce-pagination').not( $nativePagination ).addClass('acf-force-hide');
    }

    function showNativeLoop() {
        $nativeProducts.removeClass('acf-force-hide');
        // Restaurar la clase `products` que fue removida en hideNativeLoop()
        // — sin ella el <ul> pierde el estilado nativo del loop de WooCommerce
        // y se ve roto al deseleccionar la categoría.
        $nativeProducts.addClass('products');
        $nativePagination.removeClass('acf-force-hide');
        $nativeResultCount.removeClass('acf-force-hide');
        $nativeOrdering.removeClass('acf-force-hide');
        $nativeProducts.prev('.argen-list-header').removeClass('acf-force-hide');
        $('.woocommerce-pagination').not( $nativePagination ).removeClass('acf-force-hide');
    }

    // ──────────────────────────────────────────────────────────────
    // I. LIMPIAR FILTRO
    // ──────────────────────────────────────────────────────────────
    function clearFilter() {
        if ( $filteredProducts )   { $filteredProducts.remove();   $filteredProducts   = null; }
        if ( $filteredPagination ) { $filteredPagination.remove(); $filteredPagination = null; }
        $woo.find('.acf-result-count-filtered').remove();
        // Encabezados de lista huérfanos
        $woo.find('.argen-list-header').not( $nativeProducts.prev() ).remove();

        showNativeLoop();
        $nativeResultCount.html( $nativeResultCountHTML );

        state.termIds  = [];
        state.paged    = 1;
        state.filtered = false;
    }

    // ──────────────────────────────────────────────────────────────
    // J. APLICAR VISTA GRILLA / LISTA
    //    Los botones Grilla/Lista de argen-quote-loop llaman a
    //    applyView() sobre $('ul.products'). Cuando hay filtro activo,
    //    ese selector también encuentra nuestro ul filtrado, así que
    //    el toggle funciona automáticamente sobre los resultados.
    //    Solo necesitamos manejar el argen-list-header.
    // ──────────────────────────────────────────────────────────────
    function applyCurrentView() {
        if ( !$filteredProducts ) return;

        var view = 'grid';
        try { view = localStorage.getItem('argenViewMode') || 'grid'; } catch(e) {}

        var $ul = $filteredProducts.is('ul.products')
            ? $filteredProducts
            : $filteredProducts.find('ul.products');

        if ( !$ul.length ) return;

        if ( view === 'list' ) {
            $ul.addClass('argen-list-view');
            if ( !$ul.prev('.argen-list-header').length ) {
                $([
                    '<div class="argen-list-header">',
                    '<span></span><span>Producto</span>',
                    '<span>Presentación</span>',
                    '<span style="text-align:center">Cantidad</span>',
                    '<span></span></div>'
                ].join('')).insertBefore( $ul );
            }
        } else {
            $ul.removeClass('argen-list-view');
            $ul.prev('.argen-list-header').remove();
        }
    }

    // ──────────────────────────────────────────────────────────────
    // K. HELPERS
    // ──────────────────────────────────────────────────────────────
    function setLoading( on ) {
        if ( on ) {
            if ( !$woo.find('.acf-loading-overlay').length ) {
                $woo.css('position', 'relative').prepend(
                    '<div class="acf-loading-overlay" role="status" aria-label="Cargando">'
                    + '<span class="acf-spinner"></span></div>'
                );
            }
        } else {
            $woo.find('.acf-loading-overlay').remove();
        }
    }

    function showError() {
        if ( $filteredProducts ) $filteredProducts.remove();
        $filteredProducts = $('<p class="woocommerce-info">' + acfData.i18n.error + '</p>');
        $nativeProducts.before( $filteredProducts );
    }

    // Cuando quote-loop.js cambia la vista, re-aplicar al filtrado también
    $(document).on('click', '.argen-toggle-btn', function () {
        if ( state.filtered ) setTimeout( applyCurrentView, 50 );
    });

    // ──────────────────────────────────────────────────────────────
    // INIT
    // ──────────────────────────────────────────────────────────────
    $(document).ready( init );

})(jQuery);
