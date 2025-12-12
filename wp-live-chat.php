<?php
/**
 * Plugin Name: WP Live Chat
 * Plugin URI: https://example.com/wp-live-chat
 * Description: یک سیستم چت آنلاین سبک و مدرن با قابلیت اتصال به Pusher و معماری بهینه.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wp-live-chat
 * Requires at least: 6.8
 * Requires PHP: 8.0
 */

// جلوگیری از دسترسی مستقیم
defined('ABSPATH') || exit;

// تعریف ثابت‌های اصلی
define('WP_LIVE_CHAT_VERSION', '1.0.0');
define('WP_LIVE_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_LIVE_CHAT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WP_LIVE_CHAT_PLUGIN_FILE', __FILE__);

// بررسی نسخه PHP
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>WP Live Chat نیاز به PHP 8.0 یا بالاتر دارد.</p></div>';
    });
    return;
}

// ---------------------------------------------------------------------
// 1. بارگذاری کلاس‌ها
// ---------------------------------------------------------------------

// لیست کلاس‌ها و مسیرهایشان
$classes = [
    'Settings' => 'includes/Settings.php',
    'DB_Manager' => 'includes/DB_Manager.php',
    'File_Manager' => 'includes/File_Manager.php', // اضافه کردن این خط
    'Pusher_Client' => 'includes/Pusher_Client.php',
    'ConversationFlowManager' => 'includes/ConversationFlowManager.php',
    'Frontend' => 'includes/Frontend.php',
    'Chat_Frontend' => 'includes/Chat_Frontend.php',
    'Admin' => 'includes/Admin.php',
    'Ajax_Admin' => 'includes/Ajax_Admin.php',
    'Plugin' => 'includes/Plugin.php',
];

foreach ($classes as $class_name => $file_path) {
    $full_path = WP_LIVE_CHAT_PLUGIN_PATH . $file_path;
    if (file_exists($full_path)) {
        require_once $full_path;
    } else {
        error_log("WP Live Chat: File not found: {$full_path}");
    }
}

// بارگذاری اتولودر Composer
$autoloader = WP_LIVE_CHAT_PLUGIN_PATH . 'vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    // ⚠️ اگر این اجرا نشود، خطا رخ می‌دهد.
    // در اینجا باید یک نوتیفیکیشن خطا نمایش دهید
}

use WP_Live_Chat\Plugin;
use WP_Live_Chat\DB_Manager;
use WP_Live_Chat\Pusher_Client;
use WP_Live_Chat\Settings;

/**
 * کلاس اصلی افزونه و Service Container
 */
final class WP_Live_Chat {

    /**
     * نمونه (Instance) یگانه از کلاس
     * @var WP_Live_Chat|null
     */
    private static ?WP_Live_Chat $instance = null;

    /**
     * آرایه برای نگهداری نمونه‌های سرویس
     * @var array
     */
    private array $services = [];

    /**
     * وضعیت خطاها
     * @var array
     */
    private array $errors = [];

    private function __construct() {
        // شروع Session برای مدیریت جریان گفتگو
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        // بارگذاری سرویس‌ها
        $this->setup_services();
        
        // راه‌اندازی هسته افزونه
        $plugin = $this->get_service('plugin');
        if ($plugin) {
            $plugin->hooks();
        }
    }

    /**
     * متد Singleton برای دریافت نمونه کلاس
     */
    public static function instance(): WP_Live_Chat {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * تعریف و راه‌اندازی سرویس‌های اصلی (Lazy Loading)
     */
    private function setup_services(): void {
        $this->services['settings'] = new Settings();
    }
    
    /**
     * دریافت یک سرویس از Container
     * @param string $key نام سرویس
     * @return mixed|null
     */
    public function get_service(string $key) {
        if (isset($this->services[$key])) {
            return $this->services[$key];
        }

        // ساخت سرویس‌ها هنگام اولین فراخوانی
        try {
            switch ($key) {
                case 'db_manager':
                    $this->services[$key] = new DB_Manager();
                    break;
                    
                case 'pusher_client':
                    $settings = $this->get_service('settings');
                    if (!$settings) {
                        $this->services[$key] = null;
                        break;
                    }
                    
                    $app_id = $settings->get('pusher_app_id');
                    $key_val = $settings->get('pusher_key');
                    $secret = $settings->get('pusher_secret');
                    $cluster = $settings->get('pusher_cluster');
                    
                    if (empty($app_id) || empty($key_val) || empty($secret) || empty($cluster)) {
                        $this->services[$key] = null;
                        break;
                    }
                    
                    $this->services[$key] = new Pusher_Client($app_id, $key_val, $secret, $cluster);
                    break;
                    
                case 'plugin':
                    $this->services[$key] = new Plugin($this);
                    break;
                    
                default:
                    return null;
            }
        } catch (\Exception $e) {
            error_log('WP Live Chat Service Error (' . $key . '): ' . $e->getMessage());
            $this->errors[] = $key . ' service failed: ' . $e->getMessage();
            $this->services[$key] = null;
        }

        return $this->services[$key] ?? null;
    }
    
    /**
     * دریافت خطاها
     */
    public function get_errors(): array {
        return $this->errors;
    }
    
    /**
     * بررسی وجود خطا
     */
    public function has_errors(): bool {
        return !empty($this->errors);
    }
}

// ---------------------------------------------------------------------
// 2. هوک فعال‌سازی - با قابلیت تلاش مجدد
// ---------------------------------------------------------------------

/**
 * تابع فعال‌سازی افزونه
 */
function wplc_activate_plugin($network_wide = false) {
    error_log('WP Live Chat: Plugin activation started');

        // 1. ایجاد رول اپراتور چت
    wplc_create_chat_operator_role();
    
    // بارگذاری DB_Manager
    require_once WP_LIVE_CHAT_PLUGIN_PATH . 'includes/DB_Manager.php';
    
    try {
        $db_manager = new WP_Live_Chat\DB_Manager();
        
        // تلاش اول برای ایجاد جداول
        $db_manager->create_tables();
        
        // بررسی مجدد پس از 2 ثانیه
        add_action('admin_init', function() use ($db_manager) {
            global $wpdb;
            
            $sessions_table = $wpdb->prefix . 'wplc_sessions';
            $messages_table = $wpdb->prefix . 'wplc_messages';
            
            $sessions_exists = $wpdb->get_var("SHOW TABLES LIKE '{$sessions_table}'") === $sessions_table;
            $messages_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") === $messages_table;
            
            if (!$sessions_exists || !$messages_exists) {
                error_log('WP Live Chat: Tables missing on admin_init, retrying...');
                $db_manager->create_tables();
            }
        }, 5);
        
        // ذخیره نسخه فعلی
        update_option('wp_live_chat_version', WP_LIVE_CHAT_VERSION);
        update_option('wp_live_chat_activated', time());
        
        error_log('WP Live Chat: Activation completed successfully');
        
    } catch (\Exception $e) {
        error_log('WP Live Chat: Table creation failed: ' . $e->getMessage());
        
        // نمایش پیام خطا به کاربر
        add_action('admin_notices', function() use ($e) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>WP Live Chat:</strong> خطا در ایجاد جداول دیتابیس: ' . esc_html($e->getMessage()) . '</p>';
            echo '<p>لطفا افزونه را غیرفعال و مجددا فعال کنید یا با پشتیبانی تماس بگیرید.</p>';
            echo '</div>';
        });
    }
}

/**
 * ایجاد رول اپراتور چت
 */
function wplc_create_chat_operator_role(): void {
    // حذف رول قبلی اگر وجود دارد
    remove_role('chat_operator');
    
    // ایجاد رول جدید
    add_role('chat_operator', 'اپراتور چت', [
        'read' => true,
        'edit_posts' => false,
        'delete_posts' => false,
        // دسترسی به منوی چت
        'manage_options' => false,
        'wplc_access_chat' => true,
        // دسترسی‌های AJAX چت
        'wplc_send_message' => true,
        'wplc_view_chats' => true,
    ]);
    
    // اضافه کردن قابلیت به مدیران
    $admin_role = get_role('administrator');
    if ($admin_role) {
        $admin_role->add_cap('wplc_access_chat');
        $admin_role->add_cap('wplc_send_message');
        $admin_role->add_cap('wplc_view_chats');
    }
    
    error_log('WP Live Chat: Chat operator role created');
}

/**
 * اضافه کردن منو برای اپراتور چت
 */
// add_action('admin_menu', function() {
//     // اضافه کردن منوی چت برای اپراتورها
//     add_menu_page(
//         __('WP Live Chat', 'wp-live-chat'),
//         __('Live Chat', 'wp-live-chat'),
//         'wplc_access_chat', // capability جدید
//         'wplc-chat-console',
//         'wplc_render_chat_console',
//         'dashicons-format-chat',
//         6
//     );
    
//     // مخفی کردن منو از کاربران عادی
//     global $submenu;
//     if (!current_user_can('wplc_access_chat')) {
//         remove_menu_page('wplc-chat-console');
//     }
// });

register_activation_hook(WP_LIVE_CHAT_PLUGIN_FILE, 'wplc_activate_plugin');

/**
 * تابع غیرفعال‌سازی
 */
function wplc_deactivate_plugin() {
    error_log('WP Live Chat: Plugin deactivated');
    delete_option('wp_live_chat_activated');
}
register_deactivation_hook(WP_LIVE_CHAT_PLUGIN_FILE, 'wplc_deactivate_plugin');

// ---------------------------------------------------------------------
// 3. بررسی وجود جداول هنگام لود پنل ادمین
// ---------------------------------------------------------------------

add_action('admin_init', function() {
    // فقط برای مدیران و فقط در پنل افزونه
    if (!current_user_can('manage_options') || !isset($_GET['page']) || strpos($_GET['page'], 'wplc') === false) {
        return;
    }
    
    global $wpdb;
    
    $sessions_table = $wpdb->prefix . 'wplc_sessions';
    $messages_table = $wpdb->prefix . 'wplc_messages';
    
    $sessions_exists = $wpdb->get_var("SHOW TABLES LIKE '{$sessions_table}'") === $sessions_table;
    $messages_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") === $messages_table;
    
    if (!$sessions_exists || !$messages_exists) {
        add_action('admin_notices', function() use ($sessions_exists, $messages_exists) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>WP Live Chat:</strong> جداول دیتابیس کامل ایجاد نشده‌اند.</p>';
            echo '<p>وضعیت: Sessions - ' . ($sessions_exists ? '✓' : '✗') . ', Messages - ' . ($messages_exists ? '✓' : '✗') . '</p>';
            echo '<p><a href="' . wp_nonce_url(admin_url('plugins.php?action=activate&plugin=live-chat%2Fwp-live-chat.php'), 'activate-plugin_live-chat/wp-live-chat.php') . '" class="button button-primary">فعال‌سازی مجدد افزونه</a></p>';
            echo '</div>';
        });
    }
}, 1);

// ---------------------------------------------------------------------
// 4. شروع به کار افزونه
// ---------------------------------------------------------------------

/**
 * تابع اصلی برای دسترسی به هسته افزونه
 */
function wp_live_chat() {
    return WP_Live_Chat::instance();
}

// شروع به کار افزونه
add_action('plugins_loaded', 'wp_live_chat');

// Debug mode
if (!defined('WP_DEBUG') || !WP_DEBUG) {
    define('WP_LIVE_CHAT_DEBUG', false);
} else {
    define('WP_LIVE_CHAT_DEBUG', true);
}

// تابع دیباگ
function wplc_debug_log($message, $data = null) {
    if (WP_LIVE_CHAT_DEBUG) {
        if ($data) {
            error_log('WP Live Chat DEBUG: ' . $message . ' - ' . json_encode($data));
        } else {
            error_log('WP Live Chat DEBUG: ' . $message);
        }
    }
}