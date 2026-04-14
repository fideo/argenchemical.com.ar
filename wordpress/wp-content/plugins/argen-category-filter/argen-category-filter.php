<?php
/**
 * Plugin Name: Argen Category Filter
 * Plugin URI:  https://argenchemical.federicomazzei.com.ar
 * Description: Intercepta los clicks en el widget de categorías del sidebar
 *              y filtra los productos sin recargar la página. Compatible con
 *              argen-quote-loop (grilla/lista, variaciones, botón Agregar).
 * Version:     1.0.0
 * Author:      Federico Mazzei
 * License:     GPL-2.0+
 * Text Domain: argen-category-filter
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ACF_VERSION',     '1.0.0' );
define( 'ACF_URL',         plugin_dir_url( __FILE__ ) );
define( 'ACF_AJAX_ACTION', 'acf_filter_by_category' );


// ─────────────────────────────────────────────
// 1. ASSETS — solo en páginas de tienda
// ─────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'acf_enqueue_assets' );

function acf_enqueue_assets() {
    if ( ! class_exists( 'WooCommerce' ) ) return;
    if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) return;

    wp_enqueue_script(
        'acf-filter',
        ACF_URL . 'assets/acf-filter.js',
        [ 'jquery' ],
        ACF_VERSION,
        true  // footer
    );

    wp_localize_script( 'acf-filter', 'acfData', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( ACF_AJAX_ACTION ),
        'action'  => ACF_AJAX_ACTION,
        'i18n'    => [
            'loading' => __( 'Cargando productos...', 'argen-category-filter' ),
            'error'   => __( 'Error al cargar. Intentá de nuevo.', 'argen-category-filter' ),
            'results' => __( 'resultados', 'argen-category-filter' ),
            'all'     => __( 'todos los productos', 'argen-category-filter' ),
        ],
    ] );
}


// ─────────────────────────────────────────────
// 2. ENDPOINT AJAX
//    Recibe un term_id (o 0 = todos), ejecuta
//    el loop de WooCommerce con los hooks de
//    argen-quote-loop activos, y devuelve el
//    HTML listo para reemplazar ul.products.
// ─────────────────────────────────────────────
add_action( 'wp_ajax_'        . ACF_AJAX_ACTION, 'acf_ajax_handler' );
add_action( 'wp_ajax_nopriv_' . ACF_AJAX_ACTION, 'acf_ajax_handler' );

function acf_ajax_handler() {

    check_ajax_referer( ACF_AJAX_ACTION, 'nonce' );

    $term_id  = isset( $_POST['term_id'] )  ? absint( $_POST['term_id'] )              : 0;
    $paged    = isset( $_POST['paged'] )    ? max( 1, absint( $_POST['paged'] ) )       : 1;
    $per_page = isset( $_POST['per_page'] ) ? max( 1, min( 96, absint( $_POST['per_page'] ) ) ) : acf_get_products_per_page();

    // ── Query args ────────────────────────────
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ];

    if ( $term_id > 0 ) {
        $args['tax_query'] = [ [
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => $term_id,
            'operator'         => 'IN',
            'include_children' => true,
        ] ];
    }

    $query = new WP_Query( $args );

    // ── Construir HTML del loop ───────────────
    ob_start();

    if ( $query->have_posts() ) {

        // Abrir ul.products con la misma clase que usa Astra/WooCommerce
        $cols = acf_get_loop_columns();
        echo '<ul class="products columns-' . esc_attr( $cols ) . '">';

        while ( $query->have_posts() ) {
            $query->the_post();
            global $product;
            $product = wc_get_product( get_the_ID() );
            if ( ! $product ) continue;

            // li.product con las clases estándar de WooCommerce
            echo '<li class="' . esc_attr( implode( ' ', wc_get_product_class( '', $product ) ) ) . '">';

            // Gancho de argen-quote-loop: woocommerce_before_shop_loop_item_title
            // genera la imagen y el enlace igual que en el loop nativo.
            woocommerce_template_loop_product_link_open();
            woocommerce_template_loop_product_thumbnail();
            woocommerce_template_loop_product_link_close();

            // Título
            woocommerce_template_loop_product_title();

            // Precio (puede estar oculto por Catalog Mode — respetamos eso)
            woocommerce_template_loop_price();

            // Hook: aquí argen-quote-loop inyecta el formulario de variaciones
            do_action( 'woocommerce_after_shop_loop_item' );

            echo '</li>';
        }

        echo '</ul>';

    } else {
        echo '<p class="woocommerce-info acf-no-results">'
            . esc_html__( 'No se encontraron productos en esta categoría.', 'argen-category-filter' )
            . '</p>';
    }

    $loop_html = ob_get_clean();
    wp_reset_postdata();

    // ── Paginación ────────────────────────────
    $pagination_html = '';
    if ( $query->max_num_pages > 1 ) {
        $pagination_html = acf_build_pagination( $paged, $query->max_num_pages );
    }

    wp_send_json_success( [
        'html'       => $loop_html,
        'pagination' => $pagination_html,
        'total'      => (int) $query->found_posts,
        'pages'      => (int) $query->max_num_pages,
        'current'    => $paged,
    ] );
}


// ─────────────────────────────────────────────
// 3. HELPERS
// ─────────────────────────────────────────────

/** Obtiene los productos por página configurados en WooCommerce */
function acf_get_products_per_page() {
    return (int) get_option( 'posts_per_page', 12 );
}

/** Obtiene la cantidad de columnas del loop */
function acf_get_loop_columns() {
    $cols = (int) get_option( 'woocommerce_catalog_columns', 4 );
    return max( 1, min( 6, $cols ) );
}

/** Construye HTML de paginación numérica */
function acf_build_pagination( $current, $total ) {
    $html  = '<nav class="woocommerce-pagination acf-pagination">';
    $html .= '<ul class="page-numbers">';

    // Anterior
    if ( $current > 1 ) {
        $html .= '<li><button class="page-numbers acf-page" data-page="' . ( $current - 1 ) . '">‹</button></li>';
    }

    // Páginas numeradas (máximo 7 visibles con elipsis)
    $range = 2;
    for ( $i = 1; $i <= $total; $i++ ) {
        if ( $i === 1 || $i === $total || ( $i >= $current - $range && $i <= $current + $range ) ) {
            $class = $i === $current ? 'page-numbers current acf-page' : 'page-numbers acf-page';
            $html .= '<li><button class="' . $class . '" data-page="' . $i . '" '
                . ( $i === $current ? 'aria-current="page" disabled' : '' )
                . '>' . $i . '</button></li>';
        } elseif ( $i === $current - $range - 1 || $i === $current + $range + 1 ) {
            $html .= '<li><span class="page-numbers dots">…</span></li>';
        }
    }

    // Siguiente
    if ( $current < $total ) {
        $html .= '<li><button class="page-numbers acf-page" data-page="' . ( $current + 1 ) . '">›</button></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}
