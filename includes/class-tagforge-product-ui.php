<?php
namespace TagForge;
if ( ! defined( 'ABSPATH' ) ) exit;
class Product_UI {
    public static function init() : void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_filter('woocommerce_product_data_tabs', [__CLASS__, 'add_tab']);
        add_action('woocommerce_product_data_panels', [__CLASS__, 'render_panel']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_fields']);
    }
    public static function enqueue($hook = null) : void {
        $screen = get_current_screen(); if (!$screen || $screen->id !== 'product') return;
        wp_enqueue_style('tagforge-product-ui', TAGFORGE_URL.'assets/tagforge-product-ui.css', [], '1.0.6');
        wp_enqueue_script('tagforge-product-ui', TAGFORGE_URL.'assets/tagforge-product-ui.js', ['jquery'], '1.0.6', true);
        wp_localize_script('tagforge-product-ui','TagForgeUI',[ 'modules'=>array_keys(\TagForge\Factory::get_module_map()) ]);
    }
    public static function add_tab($tabs){ $tabs['tagforge'] = ['label'=>__('TagForge','tagforge'),'target'=>'tagforge_product_data','class'=>['show_if_simple','show_if_variable'],'priority'=>56]; return $tabs; }
    public static function render_panel(){
        global $post; $saved = (array) get_post_meta($post->ID, '_tagforge_default_modules_array', true);
        $csv   = (string) get_post_meta($post->ID, '_tagforge_default_modules', true);
        if (empty($saved) && !empty($csv)) $saved = array_filter(array_map('trim', explode(',', $csv)));
        $saved = array_values(array_unique(array_map('sanitize_key', $saved)));
        ?>
        <div id="tagforge_product_data" class="panel woocommerce_options_panel">
            <div class="tagforge-wrap">
                <h3><?php esc_html_e('TagForge defaults for this product','tagforge'); ?></h3>
                <p class="description"><?php esc_html_e('These modules will be included if the customer does not choose any add-ons.','tagforge'); ?></p>
                <?php if (in_array('gtag-basic', $saved, true)) : ?>
                <div class="notice inline notice-info">
                    <p><strong><?php esc_html_e('Heads up:','tagforge'); ?></strong>
                    <?php esc_html_e('This product includes GA4 Config. Ensure GA4 ID is collected on the product.','tagforge'); ?></p>
                </div>
                <?php endif; ?>
                <div class="tagforge-chip-select">
                    <label><?php esc_html_e('Default modules','tagforge'); ?></label>
                    <div class="chips" data-chips>
                        <?php foreach ($saved as $slug): ?>
                            <span class="chip" data-value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($slug); ?><button type="button" class="remove" aria-label="<?php esc_attr_e('Remove','tagforge'); ?>">×</button></span>
                        <?php endforeach; ?>
                        <input type="text" class="chip-input" placeholder="<?php esc_attr_e('Type a module and press Enter…','tagforge'); ?>">
                    </div>
                    <input type="hidden" name="_tagforge_default_modules_array" value="<?php echo esc_attr(implode(',', $saved)); ?>">
                    <p class="hint"><?php esc_html_e('Available:','tagforge'); ?> <code><?php echo esc_html(implode(', ', array_keys(\TagForge\Factory::get_module_map()))); ?></code></p>
                </div>
                <div class="tagforge-helpers">
                    <button type="button" class="button tagforge-add-common" data-mods="ecom-advanced">+ <?php esc_html_e('Ecom advanced','tagforge'); ?></button>
                    <button type="button" class="button tagforge-add-common" data-mods="ecom-base,click-tracking,scroll-depth">+ <?php esc_html_e('Base + click + scroll','tagforge'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
    public static function save_fields($product){
        if (! current_user_can('edit_product', $product->get_id())) return;
        if (isset($_POST['_tagforge_default_modules_array'])) {
            $csv = sanitize_text_field(wp_unslash($_POST['_tagforge_default_modules_array']));
            $arr = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $csv))));
            update_post_meta($product->get_id(), '_tagforge_default_modules_array', $arr);
            update_post_meta($product->get_id(), '_tagforge_default_modules', implode(',', $arr));
        }
    }
}
Product_UI::init();
