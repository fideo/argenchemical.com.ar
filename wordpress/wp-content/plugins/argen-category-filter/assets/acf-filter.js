/**
 * Argen Category Filter — acf-filter.js  v1.0.1
 *
 * Fix v1.0.1:
 * Al iniciar, guardamos referencias exactas al loop nativo y su paginación.
 * Cuando hay un filtro activo los ocultamos; cuando se limpia los restauramos.
 * Así nunca aparece el loop nativo debajo de los resultados filtrados.
 */

(function ($) {
    'use strict';

    // ── Estado ─────────────────────────────────────────────────────
    var state = {
        termId:   0,
        termName: '',
        paged:    1,
        perPage:  0,
        loading:  false,
        filtered: false,   // true = hay un filtro activo
    };

    // ── Referencias al DOM (se capturan UNA SOLA VEZ en init) ─────
    var $productsWrap;          // .woocommerce — contenedor principal
    var $nativeProducts;        // ul.products original de WooCommerce
    var $nativePagination;      // .woocommerce-pagination original
    var $nativeOrdering;        // .woocommerce-ordering (selector de orden)
    var $resultCount;           // .woocommerce-result-count
    var $nativeResultCountText; // texto original del conteo (para restaurar)

    // Elementos inyectados por este plugin (para limpiar fácil)
    var $filteredProducts   = null;
    var $filteredPagination = null;

    // ──────────────────────────────────────────────────────────────
    // A. INICIALIZACIÓN
    // ──────────────────────────────────────────────────────────────
    function init() {
        $productsWrap = $('.woocommerce');
        if ( ! $productsWrap.length ) return;

        // Guardar referencias exactas ANTES de cualquier modificación
        $nativeProducts   = $productsWrap.find('ul.products').first();
        $nativePagination = $productsWrap.find('.woocommerce-pagination').first();
        $nativeOrdering   = $productsWrap.find('.woocommerce-ordering').first();
        $resultCount      = $('.woocommerce-result-count').first();
        $nativeResultCountText = $resultCount.html();

        state.perPage = $nativeProducts.data('per-page') || 0;

        bindCategoryLinks();
        bindPagination();
    }

    // ──────────────────────────────────────────────────────────────
    // B. INTERCEPTAR LINKS DE CATEGORÍAS
    // ──────────────────────────────────────────────────────────────
    function bindCategoryLinks() {
        $(document).on('click', '.widget_product_categories a', function (e) {
            e.preventDefault();

            var $link   = $(this);
            var $li     = $link.closest('li');
            var termId  = 0;
            var classes = $li.attr('class') || '';
            var match   = classes.match(/\bcat-item-(\d+)\b/);
            if ( match ) termId = parseInt( match[1], 10 );

            var termName = $.trim( $link.text() );

            // Click en la categoría ya activa → limpiar filtro
            if ( termId === state.termId && state.termId !== 0 ) {
                clearFilter();
                return;
            }

            setActiveCategory( termId, $li );
            state.termId   = termId;
            state.termName = termName;
            state.paged    = 1;
            fetchProducts();
        });
    }

    // ──────────────────────────────────────────────────────────────
    // C. PAGINACIÓN AJAX
    // ──────────────────────────────────────────────────────────────
    function bindPagination() {
        $productsWrap.on('click', '.acf-page:not(:disabled)', function () {
            var page = parseInt( $(this).data('page'), 10 );
            if ( !isNaN(page) && page > 0 ) {
                state.paged = page;
                fetchProducts();
                $('html, body').animate({ scrollTop: $productsWrap.offset().top - 80 }, 300);
            }
        });
    }

    // ──────────────────────────────────────────────────────────────
    // D. FETCH AJAX
    // ──────────────────────────────────────────────────────────────
    function fetchProducts() {
        if ( state.loading ) return;
        state.loading = true;
        setLoading( true );

        var data = {
            action:  acfData.action,
            nonce:   acfData.nonce,
            term_id: state.termId,
            paged:   state.paged,
        };
        if ( state.perPage > 0 ) data.per_page = state.perPage;

        $.ajax({
            url:  acfData.ajaxUrl,
            type: 'POST',
            data: data,
            success: function (response) {
                if ( response.success ) renderProducts( response.data );
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
    // E. RENDERIZAR RESULTADOS FILTRADOS
    // ──────────────────────────────────────────────────────────────
    function renderProducts( data ) {

        // 1. Ocultar TODO el contenido nativo (loop + paginación + orden)
        hideNativeLoop();

        // 2. Limpiar resultados filtrados anteriores
        if ( $filteredProducts )   { $filteredProducts.remove();   $filteredProducts   = null; }
        if ( $filteredPagination ) { $filteredPagination.remove(); $filteredPagination = null; }

        // 3. Insertar nuevos productos ANTES del loop nativo oculto
        $filteredProducts = $( data.html );
        $nativeProducts.before( $filteredProducts );

        // 4. Insertar paginación filtrada si existe
        if ( data.pagination ) {
            $filteredPagination = $( data.pagination );
            $filteredProducts.after( $filteredPagination );
        }

        // 5. Actualizar conteo
        updateResultCount( data.total, data.current, state.perPage || data.total );

        // 6. Aplicar vista grilla/lista guardada
        applyCurrentView();

        state.filtered = true;
    }

    // ──────────────────────────────────────────────────────────────
    // F. OCULTAR / MOSTRAR LOOP NATIVO
    // ──────────────────────────────────────────────────────────────
    function hideNativeLoop() {
        $nativeProducts.hide();
        $nativePagination.hide();
        // Ocultar también el argen-list-header que pueda estar antes del ul nativo
        $nativeProducts.prev('.argen-list-header').hide();
    }

    function showNativeLoop() {
        $nativeProducts.show();
        $nativePagination.show();
        $nativeProducts.prev('.argen-list-header').show();
    }

    // ──────────────────────────────────────────────────────────────
    // G. LIMPIAR FILTRO — volver al estado original
    // ──────────────────────────────────────────────────────────────
    function clearFilter() {
        // Eliminar resultados filtrados
        if ( $filteredProducts )   { $filteredProducts.remove();   $filteredProducts   = null; }
        if ( $filteredPagination ) { $filteredPagination.remove(); $filteredPagination = null; }
        $productsWrap.find('.argen-list-header').not( $nativeProducts.prev() ).remove();

        // Restaurar loop nativo
        showNativeLoop();

        // Restaurar conteo original
        $resultCount.html( $nativeResultCountText );

        // Limpiar estado
        state.termId   = 0;
        state.termName = '';
        state.paged    = 1;
        state.filtered = false;

        // Quitar marcas visuales del sidebar
        $('.widget_product_categories li').removeClass('current-cat current-cat-ancestor');
    }

    // ──────────────────────────────────────────────────────────────
    // H. APLICAR VISTA GRILLA / LISTA
    // ──────────────────────────────────────────────────────────────
    function applyCurrentView() {
        var view = 'grid';
        try { view = localStorage.getItem('argenViewMode') || 'grid'; } catch(e) {}

        if ( !$filteredProducts ) return;

        var $ul = $filteredProducts.is('ul.products') ? $filteredProducts : $filteredProducts.find('ul.products');
        if ( !$ul.length ) $ul = $filteredProducts;

        var $prev = $ul.prev('.argen-list-header');

        if ( view === 'list' ) {
            $ul.addClass('argen-list-view');
            if ( !$prev.length ) {
                $([
                    '<div class="argen-list-header">',
                    '<span></span><span>Producto</span>',
                    '<span>Presentación</span>',
                    '<span style="text-align:center">Cantidad</span>',
                    '<span></span>',
                    '</div>'
                ].join('')).insertBefore( $ul );
            }
        } else {
            $ul.removeClass('argen-list-view');
            $prev.remove();
        }
    }

    // ──────────────────────────────────────────────────────────────
    // I. HELPERS
    // ──────────────────────────────────────────────────────────────

    function setActiveCategory( termId, $clickedLi ) {
        var $widget = $clickedLi.closest('.widget_product_categories');
        $widget.find('li').removeClass('current-cat current-cat-ancestor');
        if ( termId > 0 ) {
            $clickedLi.addClass('current-cat');
            $clickedLi.parents('li').addClass('current-cat-ancestor');
        }
    }

    function setLoading( on ) {
        if ( on ) {
            if ( !$productsWrap.find('.acf-loading-overlay').length ) {
                $productsWrap.css('position', 'relative').prepend(
                    '<div class="acf-loading-overlay" aria-hidden="true">'
                    + '<span class="acf-spinner"></span></div>'
                );
            }
        } else {
            $productsWrap.find('.acf-loading-overlay').remove();
        }
    }

    function updateResultCount( total, current, perPage ) {
        if ( !$resultCount.length ) return;
        if ( total === 0 ) {
            $resultCount.text('Sin resultados');
            return;
        }
        var from = ( current - 1 ) * perPage + 1;
        var to   = Math.min( current * perPage, total );
        $resultCount.text( 'Mostrando ' + from + '\u2013' + to + ' de ' + total + ' resultados' );
    }

    function showError() {
        if ( $filteredProducts ) $filteredProducts.remove();
        $filteredProducts = $('<p class="woocommerce-info">' + acfData.i18n.error + '</p>');
        $nativeProducts.before( $filteredProducts );
    }

    // Re-aplicar vista cuando el usuario cambia Grilla/Lista
    $(document).on('click', '.argen-toggle-btn', function () {
        if ( state.filtered ) setTimeout( applyCurrentView, 50 );
    });

    // ──────────────────────────────────────────────────────────────
    // INIT
    // ──────────────────────────────────────────────────────────────
    $(document).ready( init );

})(jQuery);
