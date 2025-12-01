<?php

/**
 * Kiloships Admin Settings with Tabs.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Kiloships_Admin
{

    /**
     * Initialize the admin settings.
     */
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Load tab classes
        require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin-api.php';
        require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin-suppliers.php';
        require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin-reports.php';

        // Initialize tab hooks
        Kiloships_Admin_Suppliers::init_hooks();
        Kiloships_Admin_Reports::init_hooks();

        // Create database table on plugin activation
        register_activation_hook(KILOSHIPS_PLUGIN_DIR . 'kiloships-shipping.php', array('Kiloships_Admin_Reports', 'create_table'));
    }

    /**
     * Add the settings page to the admin menu.
     */
    public function add_admin_menu()
    {
        add_options_page(
            'Kiloships Shipping',
            'Kiloships Shipping',
            'manage_options',
            'kiloships-shipping',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register the plugin settings.
     */
    public function register_settings()
    {
        Kiloships_Admin_API::register_settings();
        Kiloships_Admin_Suppliers::register_settings();
    }

    /**
     * Render the settings page with tabs.
     */
    public function render_settings_page()
    {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api';
        ?>
        <div class="wrap">
            <h1>Kiloships Shipping Settings</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=kiloships-shipping&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    API Configuration
                </a>
                <a href="?page=kiloships-shipping&tab=suppliers" class="nav-tab <?php echo $active_tab === 'suppliers' ? 'nav-tab-active' : ''; ?>">
                    Suppliers
                </a>
                <a href="?page=kiloships-shipping&tab=reports" class="nav-tab <?php echo $active_tab === 'reports' ? 'nav-tab-active' : ''; ?>">
                    Reports
                </a>
            </h2>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                switch ($active_tab) {
                    case 'suppliers':
                        Kiloships_Admin_Suppliers::render();
                        break;

                    case 'reports':
                        Kiloships_Admin_Reports::render();
                        break;

                    case 'api':
                    default:
                        Kiloships_Admin_API::render();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
}
