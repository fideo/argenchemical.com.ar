<?php
/**
 * Plugin Name: Argen Category Filter
 * Plugin URI:  https://argenchemical.federicomazzei.com.ar
 * Description: Widget propio con checkboxes para filtrar productos WooCommerce
 *              por una o varias categorías/subcategorías sin recargar la página.
 *              Compatible con argen-quote-loop (grilla/lista, variaciones, Agregar).
 * Version:     2.0.0
 * Author:      Federico Mazzei
 * License:     GPL-2.0+
 * Text Domain: argen-category-filter
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ACF_VERSION',     '2.0.0' );
define( 'ACF_URL',         plugin_dir_url( __FILE__ ) );
define( 'ACF_AJAX_ACTION', 'acf_filter_by_category' );


// ─────────────────────────────────────────────
// 1. REGISTRAR WIDGET
// ─────────────────────────────────────────────
add_action( 'widgets_init', function () {
    register_widget( 'ACF_Category_Widget' );
} );


// ─────────────────────────────────────────────
// 2. CLASE DEL WIDGET
// ─────────────────────────────────────────────
class ACF_Category_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'acf_category_widget',
            __( 'Filtro de Categorías (Argen)', 'argen-category-filter' ),
            [
                'description' => __( 'Checkboxes para filtrar productos por categoría sin recargar la página. Compatible con argen-quote-loop.', 'argen-category-filter' ),
                'classname'   => 'acf-category-widget',
            ]
        );
    }

    // ── Frontend ──────────────────────────────
    public function widget( $args, $instance ) {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        $titulo        = ! empty( $instance['title'] )         ? $instance['title']              : __( 'Categorías', 'argen-category-filter' );
        $mostrar_count = ! empty( $instance['mostrar_count'] );

        // Categorías raíz con sus hijos
        $categorias = get_terms( [
            'taxonomy'   => 'product_cat',
            'orderby'    => 'name',
            'order'      => 'ASC',
            'hide_empty' => true,
            'parent'     => 0,
        ] );

        if ( empty( $categorias ) || is_wp_error( $categorias ) ) return;

        echo $args['before_widget'];

        if ( $titulo ) {
            echo $args['before_title']
                . apply_filters( 'widget_title', esc_html( $titulo ) )
                . $args['after_title'];
        }

        echo '<div class="acf-widget-inner">';

        // ── Lista de categorías ────────────────
        echo '<ul class="acf-cat-list">';

        foreach ( $categorias as $cat ) {

            // Subcategorías
            $hijos = get_terms( [
                'taxonomy'   => 'product_cat',
                'parent'     => $cat->term_id,
                'orderby'    => 'name',
                'order'      => 'ASC',
                'hide_empty' => true,
            ] );
            $tiene_hijos = ! empty( $hijos ) && ! is_wp_error( $hijos );

            $count_html  = $mostrar_count
                ? '<span class="acf-count">(' . absint( $cat->count ) . ')</span>'
                : '';

            $item_class = 'acf-cat-item' . ( $tiene_hijos ? ' acf-has-children' : '' );

            echo '<li class="' . esc_attr( $item_class ) . '">';

            // Fila de la categoría padre
            echo '<div class="acf-cat-row">';

            // Checkbox + label
            $cb_id = 'acf-cat-' . $cat->term_id;
            echo '<label class="acf-cat-label" for="' . esc_attr( $cb_id ) . '">';
            echo '<input type="checkbox"'
                . ' id="'        . esc_attr( $cb_id ) . '"'
                . ' class="acf-checkbox"'
                . ' value="'     . esc_attr( $cat->term_id ) . '"'
                . ' data-name="' . esc_attr( $cat->name ) . '">';
            echo '<span class="acf-checkbox-visual" aria-hidden="true"></span>';
            echo '<span class="acf-cat-name">' . esc_html( $cat->name ) . '</span>';
            echo $count_html;
            echo '</label>';

            // Botón para expandir/colapsar subcategorías
            if ( $tiene_hijos ) {
                echo '<button type="button" class="acf-toggle-btn" '
                    . 'aria-expanded="false" '
                    . 'aria-label="' . esc_attr( sprintf( __( 'Expandir %s', 'argen-category-filter' ), $cat->name ) ) . '">'
                    . '<span class="acf-toggle-icon" aria-hidden="true"></span>'
                    . '</button>';
            }

            echo '</div>'; // .acf-cat-row

            // Subcategorías (acordeón)
            if ( $tiene_hijos ) {
                echo '<ul class="acf-subcat-list">';
                foreach ( $hijos as $hijo ) {
                    $hijo_count  = $mostrar_count
                        ? '<span class="acf-count">(' . absint( $hijo->count ) . ')</span>'
                        : '';
                    $hijo_cb_id = 'acf-cat-' . $hijo->term_id;

                    echo '<li class="acf-cat-item acf-subcat-item">';
                    echo '<div class="acf-cat-row">';
                    echo '<label class="acf-cat-label" for="' . esc_attr( $hijo_cb_id ) . '">';
                    echo '<input type="checkbox"'
                        . ' id="'          . esc_attr( $hijo_cb_id ) . '"'
                        . ' class="acf-checkbox"'
                        . ' value="'       . esc_attr( $hijo->term_id ) . '"'
                        . ' data-name="'   . esc_attr( $hijo->name ) . '"'
                        . ' data-parent="' . esc_attr( $cat->term_id ) . '">';
                    echo '<span class="acf-checkbox-visual" aria-hidden="true"></span>';
                    echo '<span class="acf-cat-name">' . esc_html( $hijo->name ) . '</span>';
                    echo $hijo_count;
                    echo '</label>';
                    echo '</div>';
                    echo '</li>';
                }
                echo '</ul>'; // .acf-subcat-list
            }

            echo '</li>'; // .acf-cat-item
        }

        echo '</ul>'; // .acf-cat-list

        // Botón limpiar (oculto hasta que haya selección)
        echo '<button type="button" class="acf-clear-btn" style="display:none;">'
            . '<span aria-hidden="true">✕</span> '
            . esc_html__( 'Limpiar filtros', 'argen-category-filter' )
            . '</button>';

        echo '</div>'; // .acf-widget-inner

        echo $args['after_widget'];
    }

    // ── Backend ───────────────────────────────
    public function form( $instance ) {
        $title         = ! empty( $instance['title'] )         ? $instance['title'] : __( 'Categorías', 'argen-category-filter' );
        $mostrar_count = ! empty( $instance['mostrar_count'] ) ? '1' : '0';
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
                <?php esc_html_e( 'Título:', 'argen-category-filter' ); ?>
            </label>
            <input class="widefat"
                id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <input type="checkbox"
                id="<?php echo esc_attr( $this->get_field_id( 'mostrar_count' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'mostrar_count' ) ); ?>"
                value="1" <?php checked( $mostrar_count, '1' ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id( 'mostrar_count' ) ); ?>">
                <?php esc_html_e( 'Mostrar cantidad de productos', 'argen-category-filter' ); ?>
            </label>
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return [
            'title'         => sanitize_text_field( $new_instance['title'] ),
            'mostrar_count' => ! empty( $new_instance['mostrar_count'] ) ? '1' : '0',
        ];
    }
}


// ─────────────────────────────────────────────
// 3. ENDPOINT AJAX
// ─────────────────────────────────────────────
add_action( 'wp_ajax_'        . ACF_AJAX_ACTION, 'acf_ajax_handler' );
add_action( 'wp_ajax_nopriv_' . ACF_AJAX_ACTION, 'acf_ajax_handler' );

function acf_ajax_handler() {

    check_ajax_referer( ACF_AJAX_ACTION, 'nonce' );

    // Array de term_ids seleccionados (puede ser uno o varios)
    $term_ids = isset( $_POST['term_ids'] )
        ? array_filter( array_map( 'absint', (array) $_POST['term_ids'] ) )
        : [];

    $paged    = isset( $_POST['paged'] )    ? max( 1, absint( $_POST['paged'] ) )                : 1;
    $per_page = isset( $_POST['per_page'] ) ? max( 1, min( 96, absint( $_POST['per_page'] ) ) ) : acf_get_per_page();

    // ── Query ─────────────────────────────────
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ];

    if ( ! empty( $term_ids ) ) {
        $args['tax_query'] = [ [
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => $term_ids,
            'operator'         => 'IN',
            'include_children' => true,
        ] ];
    }

    $query = new WP_Query( $args );

    // ── Generar HTML del loop ─────────────────
    ob_start();

    if ( $query->have_posts() ) {
        $cols = acf_get_columns();
        echo '<ul class="products columns-' . esc_attr( $cols ) . '">';

        while ( $query->have_posts() ) {
            $query->the_post();
            global $product;
            $product = wc_get_product( get_the_ID() );
            if ( ! $product ) continue;

            echo '<li class="' . esc_attr( implode( ' ', wc_get_product_class( '', $product ) ) ) . '">';

            // Imagen + link (igual que el loop nativo)
            woocommerce_template_loop_product_link_open();
            woocommerce_template_loop_product_thumbnail();
            woocommerce_template_loop_product_link_close();

            // Título
            woocommerce_template_loop_product_title();

            // Precio
            woocommerce_template_loop_price();

            // Hook donde argen-quote-loop inyecta el formulario
            do_action( 'woocommerce_after_shop_loop_item' );

            echo '</li>';
        }

        echo '</ul>';

    } else {
        echo '<p class="woocommerce-info acf-no-results">'
            . esc_html__( 'No se encontraron productos para las categorías seleccionadas.', 'argen-category-filter' )
            . '</p>';
    }

    $loop_html = ob_get_clean();
    wp_reset_postdata();

    // ── Paginación ────────────────────────────
    $pagination_html = '';
    if ( $query->max_num_pages > 1 ) {
        $pagination_html = acf_build_pagination( $paged, (int) $query->max_num_pages );
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
// 4. HELPERS
// ─────────────────────────────────────────────
function acf_get_per_page() {
    return (int) get_option( 'posts_per_page', 12 );
}

function acf_get_columns() {
    return max( 1, min( 6, (int) get_option( 'woocommerce_catalog_columns', 4 ) ) );
}

function acf_build_pagination( $current, $total ) {
    $html  = '<nav class="woocommerce-pagination acf-pagination">';
    $html .= '<ul class="page-numbers">';

    if ( $current > 1 ) {
        $html .= '<li><button class="page-numbers acf-page" data-page="' . ( $current - 1 ) . '">‹</button></li>';
    }

    $range = 2;
    for ( $i = 1; $i <= $total; $i++ ) {
        if ( $i === 1 || $i === $total || ( $i >= $current - $range && $i <= $current + $range ) ) {
            $active = $i === $current;
            $html  .= '<li><button class="page-numbers acf-page' . ( $active ? ' current' : '' ) . '" '
                . 'data-page="' . $i . '" '
                . ( $active ? 'disabled aria-current="page"' : '' )
                . '>' . $i . '</button></li>';
        } elseif ( $i === $current - $range - 1 || $i === $current + $range + 1 ) {
            $html .= '<li><span class="page-numbers dots">…</span></li>';
        }
    }

    if ( $current < $total ) {
        $html .= '<li><button class="page-numbers acf-page" data-page="' . ( $current + 1 ) . '">›</button></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}


// ─────────────────────────────────────────────
// 5. ASSETS
// ─────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'acf_enqueue_assets' );

function acf_enqueue_assets() {
    if ( ! class_exists( 'WooCommerce' ) ) return;
    if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) return;

    wp_enqueue_style(
        'acf-style',
        ACF_URL . 'assets/acf-style.css',
        [],
        ACF_VERSION
    );

    wp_enqueue_script(
        'acf-filter',
        ACF_URL . 'assets/acf-filter.js',
        [ 'jquery' ],
        ACF_VERSION,
        true
    );

    wp_localize_script( 'acf-filter', 'acfData', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( ACF_AJAX_ACTION ),
        'action'  => ACF_AJAX_ACTION,
        'i18n'    => [
            'error'    => __( 'Error al cargar. Intentá de nuevo.', 'argen-category-filter' ),
            'results'  => __( 'resultados', 'argen-category-filter' ),
            'noResult' => __( 'No se encontraron productos.', 'argen-category-filter' ),
        ],
    ] );
}
