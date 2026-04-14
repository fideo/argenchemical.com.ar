<?php
/**
 * Plugin Name: Quote en Listado de Tienda
 * Plugin URI:  https://argechemical.com
 * Description: Agrega selector de variaciones (Presentaciones), cantidad y botón "Add to Quote"
 *              directamente en el listado de productos. Incluye toggle de vista Grilla / Lista.
 * Version:     1.1.1
 * Author:      ArgenChemical Dev
 * License:     GPL-2.0+
 * Text Domain: argen-quote-loop
 * Requires Plugins: woocommerce
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
// ─────────────────────────────────────────────────────────────────
// GUARD: Solo ejecutar si WooCommerce está activo
// ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Quote en Listado:</strong> Requiere WooCommerce activo.</p></div>';
        });
        return;
    }
    new Argen_Quote_Loop();
});
 
 
// ─────────────────────────────────────────────────────────────────
// CLASE PRINCIPAL
// ─────────────────────────────────────────────────────────────────
class Argen_Quote_Loop {
 
    public function __construct() {
        // 1. Botón toggle Grilla / Lista antes del loop
        add_action( 'woocommerce_before_shop_loop', array( $this, 'render_view_toggle' ), 30 );
 
        // 2. Inyectar el formulario de variación en las cards del loop
        add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_quote_form_in_loop' ), 5 );
 
        // 3. Ocultar el botón nativo de WooCommerce/Catalog Mode en el loop
        add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'maybe_hide_native_button' ), 999, 2 );
 
        // 4. Encolar scripts y estilos
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
 
        // 5. AJAX: agregar al quote desde el loop
        add_action( 'wp_ajax_argen_add_to_quote_loop',        array( $this, 'ajax_add_to_quote' ) );
        add_action( 'wp_ajax_nopriv_argen_add_to_quote_loop', array( $this, 'ajax_add_to_quote' ) );
 
        // 6. Bypass YITH Catalog Mode para nuestra acción AJAX propia
        add_action( 'wp_ajax_argen_add_to_quote_loop',        array( $this, 'bypass_catalog_mode' ), 1 );
        add_action( 'wp_ajax_nopriv_argen_add_to_quote_loop', array( $this, 'bypass_catalog_mode' ), 1 );
    }
 
 
    // ─────────────────────────────────────────────────────────────
    // BYPASS: Desactiva temporalmente los filtros de Catalog Mode
    // ─────────────────────────────────────────────────────────────
    public function bypass_catalog_mode() {
        remove_all_filters( 'woocommerce_add_to_cart_validation' );
        if ( class_exists( 'YITH_WC_Catalog_Mode' ) ) {
            $instance = YITH_WC_Catalog_Mode();
            remove_action( 'wp_ajax_woocommerce_add_to_cart',        array( $instance, 'block_ajax_add_to_cart' ) );
            remove_action( 'wp_ajax_nopriv_woocommerce_add_to_cart', array( $instance, 'block_ajax_add_to_cart' ) );
        }
    }
 
 
    // ─────────────────────────────────────────────────────────────
    // TOGGLE: Botones para cambiar entre vista Grilla y Lista
    // ─────────────────────────────────────────────────────────────
    public function render_view_toggle() {
        // Solo en páginas de tienda/categorías
        if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
            return;
        }
        ?>
        <div class="argen-view-toggle" role="group" aria-label="<?php esc_attr_e( 'Cambiar vista', 'argen-quote-loop' ); ?>">
            <button class="argen-toggle-btn argen-toggle-grid active"
                    data-view="grid"
                    title="<?php esc_attr_e( 'Vista grilla', 'argen-quote-loop' ); ?>"
                    aria-pressed="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
                </svg>
                <span><?php _e( 'Grilla', 'argen-quote-loop' ); ?></span>
            </button>
            <button class="argen-toggle-btn argen-toggle-list"
                    data-view="list"
                    title="<?php esc_attr_e( 'Vista lista', 'argen-quote-loop' ); ?>"
                    aria-pressed="false">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
                    <line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/>
                    <line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
                </svg>
                <span><?php _e( 'Lista', 'argen-quote-loop' ); ?></span>
            </button>
        </div>
        <?php
    }

 
    // ─────────────────────────────────────────────────────────────
    // 1. RENDERIZAR el formulario en cada card del loop
    //    En vista lista, se agrega también la imagen del producto.
    // ─────────────────────────────────────────────────────────────
    public function render_quote_form_in_loop() {
        global $product;
 
        if ( ! $product ) return;
 
        // Imagen del producto (64×43) — visible solo en vista lista via CSS
        $image_html = '';
        $product_id = $product->get_id();
        $thumbnail  = get_the_post_thumbnail( $product_id, array( 64, 43 ), array(
            'class'   => 'argen-list-thumb',
            'loading' => 'lazy',
            'alt'     => esc_attr( $product->get_name() ),
        ) );
 
        if ( $thumbnail ) {
            $image_html = '<div class="argen-list-img-wrap">' . $thumbnail . '</div>';
        } else {
            // Placeholder si no hay imagen
            $image_html = '<div class="argen-list-img-wrap argen-list-img-placeholder"></div>';
        }
 
        if ( ! $product->is_type( 'variable' ) ) {
            $this->render_simple_quote_form( $product, $image_html );
            return;
        }
 
        $variations = $product->get_available_variations();
        $attributes = $product->get_variation_attributes();
 
        if ( empty( $variations ) ) return;
 
        ?>
        <div class="argen-quote-loop-form" data-product-id="<?php echo esc_attr( $product_id ); ?>">
 
            <?php echo $image_html; ?>
 
            <div class="argen-form-inner">
 
                <?php
                // Nombre del producto (visible en vista lista)
                echo '<div class="argen-list-name"><a href="' . esc_url( get_permalink( $product_id ) ) . '">' . esc_html( $product->get_name() ) . '</a></div>';
                ?>
 
                <div class="argen-variations-wrap">
                    <?php foreach ( $attributes as $attr_name => $attr_options ) :
                        $attr_label = wc_attribute_label( $attr_name );
                        $taxonomy   = 'pa_' . sanitize_title( str_replace( 'pa_', '', $attr_name ) );
                    ?>
                        <div class="argen-variation-row">
                            <label class="argen-variation-label">
                                <?php echo esc_html( $attr_label ); ?>
                            </label>
                            <select class="argen-variation-select"
                                    name="<?php echo esc_attr( $attr_name ); ?>"
                                    data-attribute="<?php echo esc_attr( $attr_name ); ?>">
                                <option value=""><?php _e( 'Elegí una opción', 'argen-quote-loop' ); ?></option>
                                <?php
                                $options_to_render = array();
                                foreach ( $attr_options as $option ) {
                                    if ( taxonomy_exists( $taxonomy ) ) {
                                        $term  = get_term_by( 'slug', $option, $taxonomy );
                                        $label = $term ? $term->name : $option;
                                    } else {
                                        $label = $option;
                                    }
                                    $options_to_render[ $option ] = $label;
                                }
 
                                uasort( $options_to_render, function( $a, $b ) {
                                    preg_match( '/[\d]+([.,]\d+)?/', $a, $match_a );
                                    preg_match( '/[\d]+([.,]\d+)?/', $b, $match_b );
                                    $num_a = isset( $match_a[0] ) ? (float) str_replace( ',', '.', $match_a[0] ) : 0;
                                    $num_b = isset( $match_b[0] ) ? (float) str_replace( ',', '.', $match_b[0] ) : 0;
                                    return $num_a <=> $num_b;
                                });
 
                                foreach ( $options_to_render as $slug => $label ) :
                                ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>">
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
 
                <div class="argen-qty-quote-row">
                    <div class="argen-qty-wrapper">
                        <button class="argen-qty-btn argen-qty-minus" type="button" aria-label="Disminuir">−</button>
                        <input  class="argen-qty-input"
                                type="number"
                                name="quantity"
                                value="1"
                                min="1"
                                max="9999"
                                aria-label="Cantidad">
                        <button class="argen-qty-btn argen-qty-plus" type="button" aria-label="Aumentar">+</button>
                    </div>
 
                    <div class="argen-add-quote-wrap">
                        <button class="argen-add-quote-btn"
                                type="button"
                                data-product-id="<?php echo esc_attr( $product_id ); ?>"
                                data-nonce="<?php echo wp_create_nonce( 'argen_quote_loop_' . $product_id ); ?>">
                            <?php _e( 'Agregar', 'argen-quote-loop' ); ?>
                        </button>
                    </div>
                </div>
 
                <div class="argen-quote-feedback" aria-live="polite"></div>
 
            </div><!-- .argen-form-inner -->
 
        </div><!-- .argen-quote-loop-form -->
        <?php
    }
 
 
    // ─────────────────────────────────────────────────────────────
    // Formulario para productos SIMPLES
    // ─────────────────────────────────────────────────────────────
    private function render_simple_quote_form( $product, $image_html = '' ) {
        $product_id = $product->get_id();
        ?>
        <div class="argen-quote-loop-form argen-simple" data-product-id="<?php echo esc_attr( $product_id ); ?>">
 
            <?php echo $image_html; ?>
 
            <div class="argen-form-inner">
                <div class="argen-list-name"><a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></div>
 
                <div class="argen-qty-quote-row">
                    <div class="argen-qty-wrapper">
                        <button class="argen-qty-btn argen-qty-minus" type="button" aria-label="Disminuir">−</button>
                        <input  class="argen-qty-input"
                                type="number"
                                name="quantity"
                                value="1"
                                min="1"
                                aria-label="Cantidad">
                        <button class="argen-qty-btn argen-qty-plus" type="button" aria-label="Aumentar">+</button>
                    </div>
 
                    <div class="argen-add-quote-wrap">
                        <button class="argen-add-quote-btn"
                                type="button"
                                data-product-id="<?php echo esc_attr( $product_id ); ?>"
                                data-nonce="<?php echo wp_create_nonce( 'argen_quote_loop_' . $product_id ); ?>">
                            <?php _e( 'Agregar', 'argen-quote-loop' ); ?>
                        </button>
                    </div>
                </div>
                <div class="argen-quote-feedback" aria-live="polite"></div>
            </div>
 
        </div>
        <?php
    }
 
 
    // ─────────────────────────────────────────────────────────────
    // 2. OCULTAR botón nativo en loop
    // ─────────────────────────────────────────────────────────────
    public function maybe_hide_native_button( $html, $product ) {
        return '';
    }
 
 
    // ─────────────────────────────────────────────────────────────
    // 3. ENCOLAR assets (CSS + JS)
    // ─────────────────────────────────────────────────────────────
    public function enqueue_assets() {
        if ( ! is_shop() && ! is_product_category() && ! is_product_tag() && ! is_product() ) {
            return;
        }
 
        wp_enqueue_style(
            'argen-quote-loop',
            plugin_dir_url( __FILE__ ) . 'assets/quote-loop.css',
            array(),
            '1.1.0'
        );
 
        wp_enqueue_script(
            'argen-quote-loop',
            plugin_dir_url( __FILE__ ) . 'assets/quote-loop.js',
            array( 'jquery' ),
            '1.1.0',
            true
        );
 
        wp_localize_script( 'argen-quote-loop', 'argenQuote', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'action'  => 'argen_add_to_quote_loop',
            'i18n'    => array(
                'added'        => __( '¡Agregado al presupuesto!', 'argen-quote-loop' ),
                'error'        => __( 'Error al agregar. Intentá de nuevo.', 'argen-quote-loop' ),
                'selectOption' => __( 'Por favor seleccioná una opción.', 'argen-quote-loop' ),
                'adding'       => __( 'Agregando...', 'argen-quote-loop' ),
            ),
        ));
    }
 
 
    // ─────────────────────────────────────────────────────────────
    // 4. AJAX — YITH Request a Quote
    // ─────────────────────────────────────────────────────────────
    public function ajax_add_to_quote() {
 
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        if ( ! $product_id || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'argen_quote_loop_' . $product_id ) ) {
            wp_send_json_error( array( 'message' => 'Solicitud inválida.' ) );
        }
 
        $quantity = max( 1, isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1 );
        $product  = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => 'Producto no encontrado.' ) );
        }
 
        $variation_id = 0;
        $variations   = array();
 
        if ( $product->is_type( 'variable' ) ) {
            foreach ( $_POST as $key => $value ) {
                if ( strpos( $key, 'attribute_' ) === 0 ) {
                    $variations[ sanitize_key( $key ) ] = sanitize_text_field( $value );
                }
            }
            $data_store   = WC_Data_Store::load( 'product' );
            $variation_id = $data_store->find_matching_product_variation( $product, $variations );
            if ( ! $variation_id ) {
                wp_send_json_error( array(
                    'message' => 'Variación no encontrada.',
                    'debug'   => $variations,
                ) );
            }
        }
 
        if ( $variation_id ) {
            $product_raq = array_merge(
                array(
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'quantity'     => $quantity,
                ),
                $variations
            );
        } else {
            $product_raq = array(
                'product_id' => $product_id,
                'quantity'   => $quantity,
            );
        }
 
        $quote = null;
        $added = false;
 
        if ( class_exists( 'YITH_Request_Quote' ) ) {
            if ( method_exists( 'YITH_Request_Quote', 'get_instance' ) ) {
                $quote = YITH_Request_Quote::get_instance();
            }
            if ( ! $quote || ! is_object( $quote ) ) {
                global $yith_request_quote;
                if ( isset( $yith_request_quote ) && is_object( $yith_request_quote ) ) {
                    $quote = $yith_request_quote;
                }
            }
            if ( ! $quote || ! is_object( $quote ) ) {
                $quote = new YITH_Request_Quote();
                if ( method_exists( $quote, 'init_raq_content' ) ) {
                    $quote->init_raq_content();
                } elseif ( method_exists( $quote, 'get_raq_content' ) ) {
                    $quote->get_raq_content();
                }
            }
            if ( isset( $quote->raq_content ) && ! is_array( $quote->raq_content ) ) {
                $quote->raq_content = array();
            }
            if ( $quote && method_exists( $quote, 'add_item' ) ) {
                try {
                    $result = $quote->add_item( $product_raq );
                    $added  = ( $result === 'true' || $result === 'exists' );
                } catch ( Throwable $e ) {
                    error_log( 'ArgenQuote add_item Throwable: ' . $e->getMessage() );
                    $added = false;
                }
            }
        }
 
        if ( $added ) {
            $quote_count = 0;
            if ( $quote && isset( $quote->raq_content ) && is_array( $quote->raq_content ) ) {
                foreach ( $quote->raq_content as $item ) {
                    $quote_count += isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
                }
            }
            wp_send_json_success( array(
                'message'     => '¡Agregado al presupuesto!',
                'product'     => $product->get_name(),
                'quote_count' => $quote_count,
            ) );
        } else {
            wp_send_json_error( array(
                'message'    => 'No se pudo agregar al presupuesto.',
                'debug'      => array(
                    'quote_class'  => $quote ? get_class( $quote ) : 'null',
                    'raq_content'  => $quote ? ( isset( $quote->raq_content ) ? $quote->raq_content : 'no existe' ) : 'sin objeto',
                    'product_raq'  => $product_raq,
                    'variation_id' => $variation_id,
                ),
            ) );
        }
    }
}
