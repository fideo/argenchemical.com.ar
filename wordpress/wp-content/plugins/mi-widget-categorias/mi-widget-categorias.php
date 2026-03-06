<?php
/**
 * Plugin Name: Widget de Categorías Dinámico
 * Plugin URI:  https://tusitio.com
 * Description: Widget personalizado que muestra todas las categorías de forma dinámica. Incluye soporte para shortcode y menú desplegable con productos WooCommerce.
 * Version:     1.0.0
 * Author:      Tu Nombre
 * License:     GPL-2.0+
 * Text Domain: mi-widget-categorias
 */

// Seguridad: bloquear acceso directo al archivo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────
// 1. REGISTRAR EL WIDGET
// ─────────────────────────────────────────────
function mwc_register_widget() {
    register_widget( 'MWC_Categorias_Widget' );
}
add_action( 'widgets_init', 'mwc_register_widget' );


// ─────────────────────────────────────────────
// 2. CLASE DEL WIDGET
// ─────────────────────────────────────────────
class MWC_Categorias_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'mwc_categorias_widget',           // ID único del widget
            __( 'Categorías Dinámicas', 'mi-widget-categorias' ), // Nombre visible en el panel
            array(
                'description' => __( 'Muestra todas las categorías del sitio de forma dinámica.', 'mi-widget-categorias' ),
                'classname'   => 'mwc-categorias-widget',
            )
        );
    }

    // ── FRONTEND: lo que ve el visitante ──────
    public function widget( $args, $instance ) {

        // Determinar tipo de taxonomía (categorías de posts o de WooCommerce)
        $taxonomy      = ! empty( $instance['taxonomy'] ) ? $instance['taxonomy'] : 'category';
        $mostrar_count = ! empty( $instance['mostrar_count'] ) ? true : false;
        $mostrar_hijos = ! empty( $instance['mostrar_hijos'] ) ? true : false;
        $titulo        = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Categorías', 'mi-widget-categorias' );

        echo $args['before_widget'];

        if ( $titulo ) {
            echo $args['before_title'] . apply_filters( 'widget_title', $titulo ) . $args['after_title'];
        }

        // Obtener categorías dinámicamente desde la base de datos
        $categorias = get_terms( array(
            'taxonomy'   => $taxonomy,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'hide_empty' => true,  // Solo mostrar categorías con contenido
            'parent'     => 0,     // Solo categorías padre (nivel raíz)
        ) );

        if ( ! empty( $categorias ) && ! is_wp_error( $categorias ) ) {
            echo '<ul class="mwc-lista-categorias">';

            foreach ( $categorias as $cat ) {
                $url   = get_term_link( $cat );
                $count = $mostrar_count ? ' <span class="mwc-count">(' . $cat->count . ')</span>' : '';

                echo '<li class="mwc-item cat-item-' . esc_attr( $cat->term_id ) . '">';
                echo '<a href="' . esc_url( $url ) . '">' . esc_html( $cat->name ) . $count . '</a>';

                // Mostrar subcategorías si la opción está activa
                if ( $mostrar_hijos ) {
                    $hijos = get_terms( array(
                        'taxonomy'   => $taxonomy,
                        'parent'     => $cat->term_id,
                        'hide_empty' => true,
                    ) );

                    if ( ! empty( $hijos ) && ! is_wp_error( $hijos ) ) {
                        echo '<ul class="mwc-subcategorias">';
                        foreach ( $hijos as $hijo ) {
                            $url_hijo   = get_term_link( $hijo );
                            $count_hijo = $mostrar_count ? ' <span class="mwc-count">(' . $hijo->count . ')</span>' : '';
                            echo '<li class="mwc-subitem">';
                            echo '<a href="' . esc_url( $url_hijo ) . '">' . esc_html( $hijo->name ) . $count_hijo . '</a>';
                            echo '</li>';
                        }
                        echo '</ul>';
                    }
                }

                echo '</li>';
            }

            echo '</ul>';
        } else {
            echo '<p class="mwc-sin-categorias">' . __( 'No hay categorías disponibles.', 'mi-widget-categorias' ) . '</p>';
        }

        echo $args['after_widget'];
    }

    // ── BACKEND: formulario de configuración en el panel ──
    public function form( $instance ) {
        $title         = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Categorías', 'mi-widget-categorias' );
        $taxonomy      = ! empty( $instance['taxonomy'] ) ? $instance['taxonomy'] : 'category';
        $mostrar_count = ! empty( $instance['mostrar_count'] ) ? '1' : '0';
        $mostrar_hijos = ! empty( $instance['mostrar_hijos'] ) ? '1' : '0';

        // Obtener todas las taxonomías disponibles
        $taxonomias = get_taxonomies( array( 'public' => true ), 'objects' );
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
                <?php _e( 'Título:', 'mi-widget-categorias' ); ?>
            </label>
            <input class="widefat"
                   id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                   type="text"
                   value="<?php echo esc_attr( $title ); ?>">
        </p>

        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'taxonomy' ) ); ?>">
                <?php _e( 'Taxonomía:', 'mi-widget-categorias' ); ?>
            </label>
            <select class="widefat"
                    id="<?php echo esc_attr( $this->get_field_id( 'taxonomy' ) ); ?>"
                    name="<?php echo esc_attr( $this->get_field_name( 'taxonomy' ) ); ?>">
                <?php foreach ( $taxonomias as $tax_slug => $tax_obj ) : ?>
                    <option value="<?php echo esc_attr( $tax_slug ); ?>"
                        <?php selected( $taxonomy, $tax_slug ); ?>>
                        <?php echo esc_html( $tax_obj->label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <input type="checkbox"
                   id="<?php echo esc_attr( $this->get_field_id( 'mostrar_count' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'mostrar_count' ) ); ?>"
                   value="1" <?php checked( $mostrar_count, '1' ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id( 'mostrar_count' ) ); ?>">
                <?php _e( 'Mostrar cantidad de artículos', 'mi-widget-categorias' ); ?>
            </label>
        </p>

        <p>
            <input type="checkbox"
                   id="<?php echo esc_attr( $this->get_field_id( 'mostrar_hijos' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'mostrar_hijos' ) ); ?>"
                   value="1" <?php checked( $mostrar_hijos, '1' ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id( 'mostrar_hijos' ) ); ?>">
                <?php _e( 'Mostrar subcategorías', 'mi-widget-categorias' ); ?>
            </label>
        </p>
        <?php
    }

    // ── GUARDAR configuración del widget ──────
    public function update( $new_instance, $old_instance ) {
        $instance                  = array();
        $instance['title']         = sanitize_text_field( $new_instance['title'] );
        $instance['taxonomy']      = sanitize_key( $new_instance['taxonomy'] );
        $instance['mostrar_count'] = ! empty( $new_instance['mostrar_count'] ) ? '1' : '0';
        $instance['mostrar_hijos'] = ! empty( $new_instance['mostrar_hijos'] ) ? '1' : '0';
        return $instance;
    }
}


// ─────────────────────────────────────────────
// 3. SHORTCODE: úsalo en cualquier página/post
//    Ejemplo: [categorias_dinamicas taxonomy="product_cat" count="true"]
// ─────────────────────────────────────────────
function mwc_shortcode_categorias( $atts ) {
    $atts = shortcode_atts( array(
        'taxonomy'      => 'category',
        'count'         => 'false',
        'subcategorias' => 'false',
        'orderby'       => 'name',
    ), $atts, 'categorias_dinamicas' );

    $categorias = get_terms( array(
        'taxonomy'   => sanitize_key( $atts['taxonomy'] ),
        'orderby'    => sanitize_key( $atts['orderby'] ),
        'order'      => 'ASC',
        'hide_empty' => true,
        'parent'     => 0,
    ) );

    if ( empty( $categorias ) || is_wp_error( $categorias ) ) {
        return '<p class="mwc-sin-categorias">No hay categorías disponibles.</p>';
    }

    $html = '<ul class="mwc-lista-categorias mwc-shortcode">';

    foreach ( $categorias as $cat ) {
        $url   = get_term_link( $cat );
        $count = ( $atts['count'] === 'true' ) ? ' <span class="mwc-count">(' . $cat->count . ')</span>' : '';

        $html .= '<li class="mwc-item">';
        $html .= '<a href="' . esc_url( $url ) . '">' . esc_html( $cat->name ) . $count . '</a>';

        if ( $atts['subcategorias'] === 'true' ) {
            $hijos = get_terms( array(
                'taxonomy'   => sanitize_key( $atts['taxonomy'] ),
                'parent'     => $cat->term_id,
                'hide_empty' => true,
            ) );

            if ( ! empty( $hijos ) && ! is_wp_error( $hijos ) ) {
                $html .= '<ul class="mwc-subcategorias">';
                foreach ( $hijos as $hijo ) {
                    $url_hijo = get_term_link( $hijo );
                    $html .= '<li><a href="' . esc_url( $url_hijo ) . '">' . esc_html( $hijo->name ) . '</a></li>';
                }
                $html .= '</ul>';
            }
        }

        $html .= '</li>';
    }

    $html .= '</ul>';

    return $html;
}
add_shortcode( 'categorias_dinamicas', 'mwc_shortcode_categorias' );


// ─────────────────────────────────────────────
// 4. CARGAR ESTILOS CSS del widget
// ─────────────────────────────────────────────
function mwc_enqueue_styles() {
    wp_enqueue_style(
        'mwc-categorias-style',
        plugin_dir_url( __FILE__ ) . 'assets/categorias.css',
        array(),
        '1.0.0'
    );
}
add_action( 'wp_enqueue_scripts', 'mwc_enqueue_styles' );
