# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack & runtime

WordPress + WooCommerce site for argenchemical.com.ar, run via Docker Compose. Three services: `wordpress` (built from `Dockerfile`, image is `wordpress:latest` plus WP-CLI), `db` (MariaDB), `redis` (object cache), and `phpmyadmin`. WordPress source lives bind-mounted at `./wordpress` so edits are live.

- Frontend: `http://localhost:8081`
- phpMyAdmin: `http://localhost:8082`
- DB credentials and `WP_REDIS_HOST=redis` are wired through env vars in `docker-compose.yml` and consumed by `wordpress/wp-config.php` (which uses `getenv_docker()` and hard-defines `WP_REDIS_HOST`/`PORT` at lines 182–186).

The container runs as `1000:33` (host UID `fideo` : group `www-data`) so files written by Claude on the host stay writable by WordPress inside the container.

## Common commands

```bash
# Bring the stack up / down
docker compose up -d
docker compose down

# Tail PHP/Apache logs
docker compose logs -f wordpress

# WP-CLI (already installed in the wordpress image)
docker compose exec -u 1000:33 wordpress wp <subcommand> --path=/var/www/html
# e.g.:
docker compose exec -u 1000:33 wordpress wp plugin list --path=/var/www/html
docker compose exec -u 1000:33 wordpress wp cache flush --path=/var/www/html

# Redis cache (provided by redis-cache plugin)
docker compose exec -u 1000:33 wordpress wp redis status  --path=/var/www/html
docker compose exec -u 1000:33 wordpress wp redis flush   --path=/var/www/html

# DB shell
docker compose exec db mysql -ufideo -p20607154 argenchemical_db
```

There is no PHP test suite, no JS bundler, and no linter wired in — JS/CSS are hand-edited and shipped as static assets.

## Where the custom code lives

Everything project-specific is under `wordpress/wp-content/`. The rest of the WordPress tree is upstream and should not be hand-edited.

- `themes/argenchemical-child/` — child theme of **Astra**. Astra enqueues its own parent stylesheet, so `functions.php` deliberately does **not** enqueue the parent via `wp_enqueue_style`; doing so causes conflicts. CSS is split per zone under `assets/css/` (`head.css`, `body.css`, `sidebar.css`, `content.css`, `footer.css`) and enqueued individually by `argenchemical_enqueue_styles()`. While in development the version string is `time()` for cache-busting — switch to `wp_get_theme()->get('Version')` before going to production.
- `plugins/argen-quote-loop/` — custom plugin (`Argen_Quote_Loop` class) that injects a variation selector + qty + "Add to Quote" form into each WooCommerce shop-loop card via the `woocommerce_after_shop_loop_item` hook (priority 5), provides a Grilla/Lista view toggle, hides the native add-to-cart button, and bypasses YITH Catalog Mode for its own AJAX action `argen_add_to_quote_loop`. It is the bridge between the catalog/loop UI and the YITH "Request a Quote" plugin.
- `plugins/argen-category-filter/` — custom plugin that registers a sidebar widget (`ACF_Category_Widget`) with a checkbox tree of `product_cat` terms. Selection fires AJAX action `acf_filter_by_category`, which re-runs the product `WP_Query` and returns rendered loop HTML + pagination. Inside the AJAX renderer it calls `do_action('woocommerce_after_shop_loop_item')` so `argen-quote-loop` can re-inject its quote form into AJAX-replaced cards. Loads only on `is_shop() / is_product_category() / is_product_tag()`.
- Third-party plugins relevant to the data model: `woocommerce`, `yith-woocommerce-request-a-quote`, `yith-woocommerce-catalog-mode`, `woocommerce-products-filter`, `ajax-search-for-woocommerce`, `redis-cache`, `smart-slider-3`, `contact-form-7`, `creame-whatsapp-me`, `post-smtp`.

## Architectural notes that aren't obvious from one file

1. **The shop loop is a coordination point between three pieces:** `argen-quote-loop` (form rendering + AJAX), `argen-category-filter` (re-renders the loop server-side and hands control back via the `woocommerce_after_shop_loop_item` hook), and the child theme (`functions.php` injects a `wp_footer` script that re-orders `.astra-shop-summary-wrap` to sit before the quote form on each card). When changing any of these, verify the others still render correctly after AJAX filter changes — they share DOM contracts (`ul.products li.product`, `.argen-quote-loop-form`, `.acf-cat-list`).
2. **Catalog Mode bypass:** YITH Catalog Mode strips add-to-cart UX globally. `argen-quote-loop` registers `bypass_catalog_mode` at priority 1 on its own AJAX action so quote submissions still work. Don't add a generic "remove catalog mode" — keep the bypass scoped to the plugin's action.
3. **Redis object cache** is enabled via `redis-cache` plugin and the defines in `wp-config.php`. After deploys or schema-touching changes run `wp cache flush` (and optionally `wp redis flush`) inside the container.
4. **`extra_hosts` in `docker-compose.yml`** maps `argenchemical.federicomazzei.com.ar` to the host gateway so the container can resolve the public hostname back to the host (used when the site URL is set to that domain).
5. **Database & Redis data are committed-adjacent:** `mysql_data/` and `redis_data/` are gitignored bind-mounts. Don't `rm -rf` them without an explicit DB dump first — they are the only copy of local state.

## Conventions in the custom plugins

- Plugin headers declare `Requires Plugins: woocommerce` and additionally guard with `class_exists('WooCommerce')` before doing anything.
- All AJAX endpoints register both `wp_ajax_` and `wp_ajax_nopriv_` variants and verify a nonce with `check_ajax_referer()`.
- User-facing strings are wrapped in `__()` / `esc_html__()` with text domains `argen-quote-loop` / `argen-category-filter`. `argen-quote-loop` ships an `es_AR` `.po`/`.mo` pair — regenerate both when adding strings.
- Asset versions use the plugin's `*_VERSION` constant (e.g. `ACF_VERSION`) for cache-busting; bump the constant *and* the plugin header `Version:` together.
