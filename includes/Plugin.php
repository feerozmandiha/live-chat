<?php
namespace WP_Live_Chat;

use WP_Live_Chat;

/**
 * مدیریت هوک‌های اصلی، فعال‌سازی و راه‌اندازی بخش‌ها
 */
class Plugin {
    
    private WP_Live_Chat $container;

    public function __construct(WP_Live_Chat $container) {
        $this->container = $container;
    }

    /**
     * ثبت هوک‌های اصلی
     */
    public function hooks(): void {
        // هوک به‌روزرسانی افزونه
        add_action('upgrader_process_complete', [$this, 'upgrade'], 10, 2);
        
        // هوک‌های عادی
        add_action('init', [$this, 'init']);
        
        // بررسی جداول هنگام لود
        add_action('admin_init', [$this, 'check_tables_exist']);
    }
    
    /**
     * عملیات هنگام به‌روزرسانی
     */
    public function upgrade($upgrader_object, $options): void {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            if (isset($options['plugins']) && is_array($options['plugins'])) {
                foreach ($options['plugins'] as $plugin) {
                    if ($plugin === plugin_basename(WP_LIVE_CHAT_PLUGIN_FILE)) {
                        $this->check_db_updates();
                    }
                }
            }
        }
    }
    
    /**
     * بررسی به‌روزرسانی‌های دیتابیس
     */
    private function check_db_updates(): void {
        $current_version = get_option('wp_live_chat_version', '1.0.0');
        
        if (version_compare($current_version, WP_LIVE_CHAT_VERSION, '<')) {
            error_log('WP Live Chat: Updating database from ' . $current_version . ' to ' . WP_LIVE_CHAT_VERSION);
            
            $db_manager = $this->container->get_service('db_manager');
            if ($db_manager) {
                $db_manager->create_tables();
            }
            
            update_option('wp_live_chat_version', WP_LIVE_CHAT_VERSION);
        }
    }

    /**
     * راه‌اندازی بخش‌های ادمین و فرانت‌اند
     */
    public function init(): void {
        // مدیریت بخش مدیریت
        if (is_admin()) {
            $admin = new Admin($this->container);
            $admin->hooks();
        }

        // مدیریت بخش فرانت‌اند
        if (!is_admin() || wp_doing_ajax()) {
            $frontend = new Frontend($this->container);
            $frontend->hooks();
        }
    }
    
    /**
     * بررسی وجود جداول
     */
    public function check_tables_exist(): void {
        // فقط برای مدیران بررسی کن
        if (!current_user_can('manage_options')) {
            return;
        }
        
        global $wpdb;
        
        $sessions_table = $wpdb->prefix . 'wplc_sessions';
        $messages_table = $wpdb->prefix . 'wplc_messages';
        
        // بررسی وجود جداول
        $sessions_exists = $wpdb->get_var("SHOW TABLES LIKE '{$sessions_table}'") === $sessions_table;
        $messages_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") === $messages_table;
        
        if (!$sessions_exists || !$messages_exists) {
            error_log('WP Live Chat: Tables missing. Sessions: ' . ($sessions_exists ? 'Yes' : 'No') . ', Messages: ' . ($messages_exists ? 'Yes' : 'No'));
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error">';
                echo '<p><strong>WP Live Chat:</strong> جداول دیتابیس ایجاد نشده‌اند. لطفا افزونه را غیرفعال و مجددا فعال کنید.</p>';
                echo '</div>';
            });
        }
    }
}