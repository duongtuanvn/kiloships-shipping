<?php

/**
 * Plugin Name: Kiloships Shipping
 * Plugin URI: https://kiloships.com
 * Description: Integrate Kiloships shipping label generation into WooCommerce.
 * Version: 1.1.0
 * Author: DuongTuanVn
 * Author URI: https://tuan.digital
 * Text Domain: kiloships-shipping
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define plugin constants.
define('KILOSHIPS_VERSION', '1.0.0');
define('KILOSHIPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KILOSHIPS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Kiloships Class.
 */
class Kiloships_Shipping
{

    /**
     * Instance of this class.
     *
     * @var object
     */
    protected static $instance = null;

    /**
     * Return an instance of this class.
     *
     * @return object A single instance of this class.
     */
    public static function get_instance()
    {
        // If the single instance hasn't been set, set it now.
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the plugin.
     */
    private function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files.
     */
    private function includes()
    {
        require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-api.php';
        require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin.php';
        require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-order.php';
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks()
    {
        new Kiloships_Admin();
        new Kiloships_Order();
    }
}

// Check if WooCommerce is active before initializing
add_action('plugins_loaded', 'kiloships_init');

function kiloships_init()
{
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'kiloships_woocommerce_missing_notice');
        return;
    }

    // Initialize the plugin
    Kiloships_Shipping::get_instance();
}

/**
 * Display admin notice if WooCommerce is not active.
 */
function kiloships_woocommerce_missing_notice()
{
?>
    <div class="notice notice-error">
        <p><strong>Kiloships Shipping</strong> requires WooCommerce to be installed and activated. Please install and activate WooCommerce first.</p>
    </div>
<?php
}

// Plugin activation hook to create database table
register_activation_hook(__FILE__, 'kiloships_activate_plugin');

function kiloships_activate_plugin()
{
    // Check if WooCommerce is active during activation
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            '<h1>Plugin Activation Error</h1>' .
                '<p><strong>Kiloships Shipping</strong> requires WooCommerce to be installed and activated.</p>' .
                '<p>Please install and activate WooCommerce first, then try activating this plugin again.</p>' .
                '<p><a href="' . admin_url('plugins.php') . '">&laquo; Return to Plugins</a></p>',
            'Plugin Activation Error',
            array('back_link' => true)
        );
    }

    require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin-reports.php';
    Kiloships_Admin_Reports::create_table();
}
