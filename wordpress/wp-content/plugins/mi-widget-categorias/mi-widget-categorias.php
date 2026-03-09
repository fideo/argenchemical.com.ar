<?php
/**
 * Plugin Name: Widget de Categorías Dinámico
 * Plugin URI:  https://tusitio.com
 * Description: Widget personalizado que muestra todas las categorías de forma dinámica. Incluye soporte para shortcode y menú desplegable con productos WooCommerce.
 * Version:     1.1.0
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
            'mwc_categorias_widget',
            __( 'Categorías Dinámicas', 'mi-widget-categorias' ),
            array(
                'description' => __( 'Muestra todas las categorías del sitio de forma dinámica.', 'mi-widget-categorias' ),
                'classname'   => 'mwc-categorias-widget',
            )
        );
    }

    // ── FRONTEND: lo que ve el visitante ──────
    public function widget( $args, $instance ) {

        $taxonomy      = ! empty( $instance['taxonomy'] ) ? $instance['taxonomy'] : 'category';
        $mostrar_count = ! empty( $instance['mostrar_count'] ) ? true : false;
        $mostrar_hijos = ! empty( $instance['mostrar_hijos'] ) ? true : false;
        $titulo        = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Categorías', 'mi-widget-categorias' );

        // Detectar categoría/término actual para mantenerla abierta
        $queried_object  = get_queried_object();
        $current_term_id = 0;
        if ( $queried_object instanceof WP_Term ) {
            $current_term_id = $queried_object->term_id;
        }

        echo $args['before_widget'];

        if ( $titulo ) {
            echo $args['before_title'] . apply_filters( 'widget_title', $titulo ) . $args['after_title'];
        }

        $categorias = get_terms( array(
            'taxonomy'   => $taxonomy,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'hide_empty' => true,
            'parent'     => 0,
        ) );

        if ( ! empty( $categorias ) && ! is_wp_error( $categorias ) ) {
            echo '<ul class="mwc-lista-categorias">';

            $primer_item = true; // Bandera para abrir la primera categoría

            foreach ( $categorias as $cat ) {
                $url   = get_term_link( $cat );
                $count = $mostrar_count ? ' <span class="mwc-count">' . $cat->count . '</span>' : '';

                // Obtener hijos para saber si hay subcategorías
                $hijos = array();
                if ( $mostrar_hijos ) {
                    $hijos = get_terms( array(
                        'taxonomy'   => $taxonomy,
                        'parent'     => $cat->term_id,
                        'hide_empty' => true,
                    ) );
                }
                $tiene_hijos = ! empty( $hijos ) && ! is_wp_error( $hijos );

                // Determinar si este ítem debe estar abierto:
                // - Es el primero de la lista (siempre abierto por defecto), O
                // - Es la categoría actualmente visitada o su ancestro
                $es_actual   = ( $current_term_id === $cat->term_id );
                $es_ancestro = false;
                if ( $current_term_id && $tiene_hijos ) {
                    foreach ( $hijos as $hijo ) {
                        if ( $hijo->term_id === $current_term_id ) {
                            $es_ancestro = true;
                            break;
                        }
                    }
                }

                $clases = array( 'mwc-item', 'cat-item-' . $cat->term_id );

                if ( $es_actual ) {
                    $clases[] = 'current-cat';
                }
                if ( $es_ancestro ) {
                    $clases[] = 'current-cat-ancestor';
                }

                // Abrir: el primero, la categoría activa o su ancestro
                if ( $primer_item || $es_actual || $es_ancestro ) {
                    $clases[] = 'open';
                }

                echo '<li class="' . esc_attr( implode( ' ', $clases ) ) . '">';

                // Fila: link + botón toggle (separados para que el click en el link navegue normalmente)
                echo '<div class="mwc-item-row">';
                echo '<a href="' . esc_url( $url ) . '" class="mwc-cat-link">' . esc_html( $cat->name ) . $count . '</a>';

                if ( $tiene_hijos ) {
                    // Botón toggle: solo controla apertura/cierre, NO navega
                    echo '<button class="mwc-toggle" aria-label="' . esc_attr__( 'Expandir subcategorías', 'mi-widget-categorias' ) . '" aria-expanded="' . ( ( $primer_item || $es_actual || $es_ancestro ) ? 'true' : 'false' ) . '">';
                    echo '<span class="mwc-toggle-icon"></span>';
                    echo '</button>';
                }

                echo '</div>'; // .mwc-item-row

                // Subcategorías
                if ( $tiene_hijos ) {
                    echo '<ul class="mwc-subcategorias">';
                    foreach ( $hijos as $hijo ) {
                        $url_hijo   = get_term_link( $hijo );
                        $count_hijo = $mostrar_count ? ' <span class="mwc-count">' . $hijo->count . '</span>' : '';
                        $clases_hijo = array( 'mwc-subitem' );
                        if ( $hijo->term_id === $current_term_id ) {
                            $clases_hijo[] = 'current-cat';
                        }
                        echo '<li class="' . esc_attr( implode( ' ', $clases_hijo ) ) . '">';
                        echo '<a href="' . esc_url( $url_hijo ) . '">' . esc_html( $hijo->name ) . $count_hijo . '</a>';
                        echo '</li>';
                    }
                    echo '</ul>';
                }

                echo '</li>';

                $primer_item = false; // Solo el primero se marca como abierto por defecto
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
// 3. SHORTCODE
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

    $primer_item = true;
    $html = '<ul class="mwc-lista-categorias mwc-shortcode">';

    foreach ( $categorias as $cat ) {
        $url   = get_term_link( $cat );
        $count = ( $atts['count'] === 'true' ) ? ' <span class="mwc-count">' . $cat->count . '</span>' : '';

        $clases = 'mwc-item' . ( $primer_item ? ' open' : '' );
        $html .= '<li class="' . esc_attr( $clases ) . '">';
        $html .= '<div class="mwc-item-row">';
        $html .= '<a href="' . esc_url( $url ) . '" class="mwc-cat-link">' . esc_html( $cat->name ) . $count . '</a>';

        if ( $atts['subcategorias'] === 'true' ) {
            $hijos = get_terms( array(
                'taxonomy'   => sanitize_key( $atts['taxonomy'] ),
                'parent'     => $cat->term_id,
                'hide_empty' => true,
            ) );

            if ( ! empty( $hijos ) && ! is_wp_error( $hijos ) ) {
                $html .= '<button class="mwc-toggle" aria-expanded="' . ( $primer_item ? 'true' : 'false' ) . '"><span class="mwc-toggle-icon"></span></button>';
                $html .= '</div>'; // cierra mwc-item-row
                $html .= '<ul class="mwc-subcategorias">';
                foreach ( $hijos as $hijo ) {
                    $url_hijo = get_term_link( $hijo );
                    $html .= '<li class="mwc-subitem"><a href="' . esc_url( $url_hijo ) . '">' . esc_html( $hijo->name ) . '</a></li>';
                }
                $html .= '</ul>';
            } else {
                $html .= '</div>'; // cierra mwc-item-row sin hijos
            }
        } else {
            $html .= '</div>'; // cierra mwc-item-row
        }

        $html .= '</li>';
        $primer_item = false;
    }

    $html .= '</ul>';
    return $html;
}
add_shortcode( 'categorias_dinamicas', 'mwc_shortcode_categorias' );


// ─────────────────────────────────────────────
// 4. CARGAR ESTILOS Y SCRIPTS
// ─────────────────────────────────────────────
function mwc_enqueue_styles() {
    wp_enqueue_style(
        'mwc-categorias-style',
        plugin_dir_url( __FILE__ ) . 'assets/categorias.css',
        array(),
        '1.1.0'
    );
    wp_enqueue_script(
        'mwc-categorias-script',
        plugin_dir_url( __FILE__ ) . 'assets/categorias.js',
        array(),
        '1.1.0',
        true // Cargar en el footer
    );
}
add_action( 'wp_enqueue_scripts', 'mwc_enqueue_styles' );

