<?php
/**
 * Plugin Name: Quote en Listado de Tienda
 * Plugin URI:  https://argechemical.com
 * Description: Agrega selector de variaciones (Presentaciones), cantidad y botón "Add to Quote" directamente en el listado de productos de la tienda WooCommerce.
 * Version:     1.0.0
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
    // Inicializar el plugin
    new Argen_Quote_Loop();
});


// ─────────────────────────────────────────────────────────────────
// CLASE PRINCIPAL
// ─────────────────────────────────────────────────────────────────
class Argen_Quote_Loop {

    public function __construct() {
        // 1. Inyectar el formulario de variación en las cards del loop
        add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_quote_form_in_loop' ), 5 );

        // 2. Ocultar el botón nativo de WooCommerce/Catalog Mode en el loop
        //    Prioridad 999 para ejecutarse DESPUÉS de YITH Catalog Mode (que usa ~10)
        add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'maybe_hide_native_button' ), 999, 2 );

        // 3. Encolar scripts y estilos
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // 4. AJAX: agregar al quote desde el loop
        add_action( 'wp_ajax_argen_add_to_quote_loop',        array( $this, 'ajax_add_to_quote' ) );
        add_action( 'wp_ajax_nopriv_argen_add_to_quote_loop', array( $this, 'ajax_add_to_quote' ) );

        // 5. Bypass YITH Catalog Mode para nuestra acción AJAX propia
        //    Catalog Mode bloquea wp_ajax_ hooks — lo desactivamos solo para el nuestro
        add_action( 'wp_ajax_argen_add_to_quote_loop',        array( $this, 'bypass_catalog_mode' ), 1 );
        add_action( 'wp_ajax_nopriv_argen_add_to_quote_loop', array( $this, 'bypass_catalog_mode' ), 1 );
    }

    // ─────────────────────────────────────────────────────────────
    // BYPASS: Desactiva temporalmente los filtros de Catalog Mode
    // antes de procesar nuestra solicitud AJAX
    // ─────────────────────────────────────────────────────────────
    public function bypass_catalog_mode() {
        // YITH Catalog Mode usa este filtro para bloquear add_to_cart
        remove_all_filters( 'woocommerce_add_to_cart_validation' );

        // También puede bloquear mediante este hook
        if ( class_exists( 'YITH_WC_Catalog_Mode' ) ) {
            $instance = YITH_WC_Catalog_Mode();
            remove_action( 'wp_ajax_woocommerce_add_to_cart',        array( $instance, 'block_ajax_add_to_cart' ) );
            remove_action( 'wp_ajax_nopriv_woocommerce_add_to_cart', array( $instance, 'block_ajax_add_to_cart' ) );
        }
    }


    // ─────────────────────────────────────────────────────────────
    // 1. RENDERIZAR el formulario en cada card del loop
    // ─────────────────────────────────────────────────────────────
    public function render_quote_form_in_loop() {
        global $product;

        if ( ! $product ) return;

        // Solo actuar en productos variables (los que tienen "Presentaciones")
        if ( ! $product->is_type( 'variable' ) ) {
            // Para productos simples: mostrar solo cantidad + botón quote
            $this->render_simple_quote_form( $product );
            return;
        }

        $variations       = $product->get_available_variations();
        $attributes       = $product->get_variation_attributes();
        $product_id       = $product->get_id();

        if ( empty( $variations ) ) return;

        ?>
        <div class="argen-quote-loop-form" data-product-id="<?php echo esc_attr( $product_id ); ?>">

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
                        // ── Construir array [slug => label] para poder ordenar ──
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

                        // ── Ordenar numéricamente extrayendo el primer número del label ──
                        // Ejemplos: "1 Litro" -> 1, "10 Litros" -> 10, "500ml" -> 500
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

                <button class="argen-add-quote-btn"
                        type="button"
                        data-product-id="<?php echo esc_attr( $product_id ); ?>"
                        data-nonce="<?php echo wp_create_nonce( 'argen_quote_loop_' . $product_id ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><line x1="3" x2="21" y1="6" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                    <?php _e( 'Agregar al presupuesto', 'argen-quote-loop' ); ?>
                </button>
            </div>

            <div class="argen-quote-feedback" aria-live="polite"></div>

        </div>
        <?php
    }


    // ─────────────────────────────────────────────
    // Formulario para productos SIMPLES
    // ─────────────────────────────────────────────
    private function render_simple_quote_form( $product ) {
        $product_id = $product->get_id();
        ?>
        <div class="argen-quote-loop-form argen-simple" data-product-id="<?php echo esc_attr( $product_id ); ?>">
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

                <button class="argen-add-quote-btn"
                        type="button"
                        data-product-id="<?php echo esc_attr( $product_id ); ?>"
                        data-nonce="<?php echo wp_create_nonce( 'argen_quote_loop_' . $product_id ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><line x1="3" x2="21" y1="6" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                    <?php _e( 'Agregar al presupuesto', 'argen-quote-loop' ); ?>
                </button>
            </div>
            <div class="argen-quote-feedback" aria-live="polite"></div>
        </div>
        <?php
    }


    // ─────────────────────────────────────────────────────────────
    // 2. OCULTAR botón nativo en loop para productos variables
    // ─────────────────────────────────────────────────────────────
    public function maybe_hide_native_button( $html, $product ) {
        // Si el producto es variable, ocultamos el botón nativo de WooCommerce
        // porque nuestro formulario ya lo reemplaza.
        if ( $product->is_type( 'variable' ) ) {
            return ''; // retornar vacío oculta el botón
        }
        // Para simples, también ocultamos porque nuestro form lo maneja
        return '';
    }


    // ─────────────────────────────────────────────────────────────
    // 3. ENCOLAR assets (CSS + JS)
    //    CORREGIDO: agregar is_product() para cargar también en
    //    páginas de producto individual (donde están los relacionados)
    // ─────────────────────────────────────────────────────────────
    public function enqueue_assets() {

        // ANTES (bug): solo cargaba en tienda y categorías
        // if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
        //     return;
        // }

        // AHORA: también carga en página de producto individual
        // porque ahí aparece la sección "Productos Relacionados"
        if ( ! is_shop() && ! is_product_category() && ! is_product_tag() && ! is_product() ) {
            return;
        }

        wp_enqueue_style(
            'argen-quote-loop',
            plugin_dir_url( __FILE__ ) . 'assets/quote-loop.css',
            array(),
            '1.0.1' // bump de versión para forzar recarga de caché
        );

        wp_enqueue_script(
            'argen-quote-loop',
            plugin_dir_url( __FILE__ ) . 'assets/quote-loop.js',
            array( 'jquery' ),
            '1.0.1', // bump de versión para forzar recarga de caché
            true
        );

        // Pasar datos de PHP a JS — ahora disponible también en is_product()
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
    // 4. AJAX — YITH Request a Quote v2.47.1
    //
    // Firma real de add_item() confirmada:
    //   add_item( array $product_raq )
    //
    // El array debe tener:
    //   - product_id, variation_id, quantity
    //   - los atributos como keys directas: attribute_pa_xxx => valor
    //     (NO dentro de un sub-array 'variations')
    //
    // YITH guarda en $this->raq_content y llama set_session() internamente.
    // El objeto debe obtenerse via el hook 'init' ya ejecutado,
    // porque ahí YITH inicializa raq_content desde su session.
    // ─────────────────────────────────────────────────────────────
    public function ajax_add_to_quote() {

        // ── Seguridad ─────────────────────────────
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        if ( ! $product_id || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'argen_quote_loop_' . $product_id ) ) {
            wp_send_json_error( array( 'message' => 'Solicitud inválida.' ) );
        }

        $quantity = max( 1, isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1 );
        $product  = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => 'Producto no encontrado.' ) );
        }

        // ── Recolectar variaciones ────────────────
        $variation_id = 0;
        $variations   = array(); // formato: [ 'attribute_pa_xxx' => 'valor' ]

        if ( $product->is_type( 'variable' ) ) {
            foreach ( $_POST as $key => $value ) {
                if ( strpos( $key, 'attribute_' ) === 0 ) {
                    // sanitize_key: minúsculas, sin espacios — correcto para WC
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

        // ── Construir el array que add_item() espera ──────────
        // CRÍTICO: YITH usa isset( $product_raq['variation_id'] ) para
        // distinguir entre producto simple y variable.
        // → Para SIMPLES: NO incluir 'variation_id' en el array.
        // → Para VARIABLES: incluir 'variation_id' + atributos como keys directas.
        if ( $variation_id ) {
            // Producto VARIABLE: variation_id + atributos en la raíz del array
            $product_raq = array_merge(
                array(
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'quantity'     => $quantity,
                ),
                $variations  // [ 'attribute_pa_presentaciones' => '1-litro' ]
            );
        } else {
            // Producto SIMPLE: solo product_id y quantity, sin variation_id
            $product_raq = array(
                'product_id' => $product_id,
                'quantity'   => $quantity,
            );
        }

        // ── Obtener instancia de YITH_Request_Quote ───────────
        // YITH inicializa raq_content en el hook 'init' (prioridad 1).
        // En contexto AJAX ese hook ya se ejecutó, así que el objeto
        // ya tiene la sesión del usuario cargada correctamente.
        $quote = null;
        $added = false;

        if ( class_exists( 'YITH_Request_Quote' ) ) {

            // Forma 1: get_instance() — singleton estándar
            if ( method_exists( 'YITH_Request_Quote', 'get_instance' ) ) {
                $quote = YITH_Request_Quote::get_instance();
            }

            // Forma 2: variable global registrada por YITH en su bootstrap
            if ( ! $quote || ! is_object( $quote ) ) {
                global $yith_request_quote;
                if ( isset( $yith_request_quote ) && is_object( $yith_request_quote ) ) {
                    $quote = $yith_request_quote;
                }
            }

            // Forma 3: instanciar directamente (último recurso)
            // En este caso raq_content puede no estar cargado aún,
            // por eso llamamos init_raq_content() si existe
            if ( ! $quote || ! is_object( $quote ) ) {
                $quote = new YITH_Request_Quote();
                if ( method_exists( $quote, 'init_raq_content' ) ) {
                    $quote->init_raq_content();
                } elseif ( method_exists( $quote, 'get_raq_content' ) ) {
                    $quote->get_raq_content();
                }
            }

            // Asegurarse de que raq_content es un array antes de llamar add_item()
            // Esto previene "Cannot use scalar as array" si la session está vacía
            if ( isset( $quote->raq_content ) && ! is_array( $quote->raq_content ) ) {
                $quote->raq_content = array();
            }

            if ( $quote && method_exists( $quote, 'add_item' ) ) {
                try {
                    $result = $quote->add_item( $product_raq );
                    // add_item() devuelve 'true' (string) en éxito, 'exists' si ya estaba
                    $added = ( $result === 'true' || $result === 'exists' );
                } catch ( Throwable $e ) {
                    error_log( 'ArgenQuote add_item Throwable: ' . $e->getMessage() );
                    $added = false;
                }
            }
        }

        if ( $added ) {
            // Contar ítems del quote para actualizar el ícono del header
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
                'message'     => 'No se pudo agregar al presupuesto.',
                'debug'       => array(
                    'quote_class'   => $quote ? get_class( $quote ) : 'null',
                    'raq_content'   => $quote ? ( isset( $quote->raq_content ) ? $quote->raq_content : 'no existe' ) : 'sin objeto',
                    'product_raq'   => $product_raq,
                    'variation_id'  => $variation_id,
                ),
            ) );
        }
    }
}
