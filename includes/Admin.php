<?php
namespace WP_Live_Chat;
use WP_Live_Chat;

/**
 * مدیریت پنل ادمین (منو، صفحات، Assetها)
 */
class Admin {
    
    private WP_Live_Chat $container;

    public function __construct(WP_Live_Chat $container) {
        $this->container = $container;
    }

    public function hooks(): void {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // فراخوانی متدهای AJAX مخصوص ادمین
        $ajax_admin = new Ajax_Admin($this->container);
        $ajax_admin->hooks();
        
        // ذخیره تنظیمات
        add_action('admin_post_wplc_save_settings', [$this, 'save_settings']);
        
        // ردیابی فعالیت در صفحات ادمین
        add_action('admin_init', ['WP_Live_Chat\Ajax_Admin', 'track_admin_page_activity']);
        
        // ایجاد نقش اپراتور چت هنگام فعال‌سازی
        add_action('admin_init', [$this, 'maybe_create_chat_roles']);
    }
    
    /**
     * ایجاد نقش اپراتور چت در صورت نیاز
     */
    public function maybe_create_chat_roles(): void {
        // فقط مدیران می‌توانند این کار را انجام دهند
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $this->create_chat_operator_role();
    }
    
    /**
     * ایجاد نقش اپراتور چت
     */
    private function create_chat_operator_role(): void {
        // بررسی وجود نقش
        if (get_role('chat_operator')) {
            return;
        }
        
        // ایجاد نقش جدید
        add_role('chat_operator', 'اپراتور چت', [
            'read' => true,
            'edit_posts' => false,
            'delete_posts' => false,
            // دسترسی‌های چت
            'wplc_view_chats' => true,
            'wplc_send_messages' => true,
            'wplc_close_chats' => true,
        ]);
        
        // اضافه کردن قابلیت‌ها به مدیران
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('wplc_view_chats');
            $admin_role->add_cap('wplc_send_messages');
            $admin_role->add_cap('wplc_close_chats');
        }
        
        error_log('WP Live Chat: Chat operator role created');
    }

    public function add_admin_menu(): void {
        // ایجاد منوی اصلی چت - برای مدیران و اپراتورها
        $menu_title = __('Live Chat', 'wp-live-chat');
        
        // اگر اپراتور آنلاین وجود دارد، نشانگر اضافه کنیم
        if (Ajax_Admin::is_any_operator_online()) {
            $online_count = Ajax_Admin::get_online_operators_count();
            if ($online_count > 0) {
                $menu_title .= ' <span class="awaiting-mod">' . $online_count . '</span>';
            }
        }
        
        add_menu_page(
            __('WP Live Chat', 'wp-live-chat'),
            $menu_title,
            'wplc_view_chats', // capability جدید برای اپراتورها
            'wplc-chat-console',
            [$this, 'render_chat_console'],
            'dashicons-format-chat',
            6
        );
        
        // زیرمنوی کنسول چت (همان صفحه اصلی)
        add_submenu_page(
            'wplc-chat-console',
            __('Chat Console', 'wp-live-chat'),
            __('Chat Console', 'wp-live-chat'),
            'wplc_view_chats',
            'wplc-chat-console',
            [$this, 'render_chat_console']
        );
        
        // زیرمنوی گزارشات - برای مدیران و اپراتورها
        add_submenu_page(
            'wplc-chat-console',
            __('Chat Reports', 'wp-live-chat'),
            __('Reports', 'wp-live-chat'),
            'wplc_view_chats',
            'wplc-reports',
            [$this, 'render_reports_page']
        );
        
        // زیرمنوی تنظیمات - فقط برای مدیران
        add_submenu_page(
            'wplc-chat-console',
            __('Settings', 'wp-live-chat'),
            __('Settings', 'wp-live-chat'),
            'manage_options', // فقط مدیران
            'wplc-settings',
            [$this, 'render_settings_page']
    );
        
        // مخفی کردن منوی اصلی از لیست زیرمنوها
        global $submenu;
        if (isset($submenu['wplc-chat-console'])) {
            foreach ($submenu['wplc-chat-console'] as $key => $item) {
                if ($item[2] === 'wplc-chat-console') {
                    $submenu['wplc-chat-console'][$key][0] = __('Chat Console', 'wp-live-chat');
                    break;
                }
            }
        }
        
        // مخفی کردن منو از کاربرانی که دسترسی ندارند
        if (!current_user_can('wplc_view_chats')) {
            remove_menu_page('wplc-chat-console');
        }
    }
    
    /**
     * رندر صفحه گزارشات
     */
    public function render_reports_page(): void {
        echo '<div class="wrap">';
        echo '<h1>' . __('Chat Reports', 'wp-live-chat') . '</h1>';
        
        // فقط مدیران می‌توانند گزارشات کامل را ببینند
        if (current_user_can('manage_options')) {
            echo '<div class="card">';
            echo '<h2>' . __('آمار کلی', 'wp-live-chat') . '</h2>';
            echo '<p>' . __('در حال توسعه...', 'wp-live-chat') . '</p>';
            echo '</div>';
        }
        
        // اپراتورها فقط گزارشات خودشان را می‌بینند
        echo '<div class="card">';
        echo '<h2>' . __('گفتگوهای شما', 'wp-live-chat') . '</h2>';
        echo '<p>' . __('لیست گفتگوهایی که شما پاسخ داده‌اید.', 'wp-live-chat') . '</p>';
        echo '</div>';
        
        echo '</div>';
    }

    public function enqueue_admin_assets($hook): void {
        // فقط در صفحات افزونه بارگذاری شود
        if (str_contains($hook, 'wplc-chat-console') || str_contains($hook, 'wplc-settings') || str_contains($hook, 'wplc-reports')) {
            $settings = $this->container->get_service('settings');

            wp_enqueue_style(
                'wplc-admin-style', 
                WP_LIVE_CHAT_PLUGIN_URL . 'assets/css/admin.css', 
                [], 
                WP_LIVE_CHAT_VERSION
            );

            // بارگذاری کتابخانه Pusher JS برای ادمین
            wp_enqueue_script(
                'pusher-js',
                'https://js.pusher.com/8.4.0/pusher.min.js',
                [],
                '8.4.0', 
                true
            );

            // بارگذاری اسکریپت اصلی ادمین
            wp_enqueue_script(
                'wplc-admin-script',
                WP_LIVE_CHAT_PLUGIN_URL . 'assets/js/admin.js',
                ['jquery', 'pusher-js'],
                WP_LIVE_CHAT_VERSION,
                true
            );

            $auth_nonce = wp_create_nonce('wplc_pusher_auth');

            // داده‌های مورد نیاز برای اسکریپت JS ادمین
            wp_localize_script('wplc-admin-script', 'wplc_admin_data', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'pusher_key' => $settings->get('pusher_key') ?: '',
                'pusher_cluster' => $settings->get('pusher_cluster') ?: 'eu',
                'pusher_enabled' => $settings->has_required_settings(),
                'user_id' => get_current_user_id(),
                'user_name' => wp_get_current_user()->display_name,
                'user_role' => Ajax_Admin::is_user_chat_operator() ? 'chat_operator' : 'administrator',
                'nonce' => wp_create_nonce('wplc_admin_nonce'),
                'authNonce' => $auth_nonce, // استفاده از متغیر
                'session_id' => 'admin_' . get_current_user_id(),
                'debug' => true // فعال کردن حالت دیباگ
            ]);
        }
    }

    /**
     * رندر صفحه کنسول چت (جایی که ادمین به چت‌ها پاسخ می‌دهد)
     */
    public function render_chat_console(): void {
        $settings = $this->container->get_service('settings');
        
        // بررسی تنظیمات Pusher
        if (!$settings->has_required_settings()) {
            echo '<div class="wrap">';
            echo '<h1>' . __('Live Chat Console', 'wp-live-chat') . '</h1>';
            echo '<div class="notice notice-error"><p>';
            echo __('کلیدهای Pusher تنظیم نشده‌اند. لطفا به تنظیمات افزونه مراجعه کنید.', 'wp-live-chat');
            echo '</p></div>';
            echo '</div>';
            return;
        }
        
        // بررسی دسترسی کاربر
        if (!current_user_can('wplc_view_chats')) {
            echo '<div class="wrap">';
            echo '<h1>' . __('دسترسی غیرمجاز', 'wp-live-chat') . '</h1>';
            echo '<div class="notice notice-error"><p>';
            echo __('شما مجوز دسترسی به کنسول چت را ندارید.', 'wp-live-chat');
            echo '</p></div>';
            echo '</div>';
            return;
        }
        
        // اطلاعات کاربر فعلی
        $current_user = wp_get_current_user();
        $user_role = Ajax_Admin::is_user_chat_operator() ? 'اپراتور چت' : 'مدیر کل';
        
        // محتوای HTML کنسول چت
        echo '<div id="chat-admin-container" class="wrap chat-admin-container">';
        echo '<h1>' . __('Live Chat Console', 'wp-live-chat') . '</h1>';
        
        // Status Bar
        echo '<div class="admin-status-bar">';
        echo '<span class="operator-info">';
        echo 'شما: <strong>' . esc_html($current_user->display_name) . '</strong> (' . $user_role . ')';
        echo '</span>';
        echo '<span class="online-status">';
        echo 'وضعیت: <span class="admin-online-count">...</span> اپراتور آنلاین';
        echo '</span>';
        echo '<button id="refresh-sessions" class="button button-primary">🔄 بروزرسانی لیست</button>';
        echo '</div>';
        
        // Main Container
        echo '<div class="wrap">';
        
        // Session List
        echo '<div class="session-list-area">';
        echo '<div class="session-list-header">';
        echo '<span>گفتگوها</span>';
        echo '<span class="session-list-count">0</span>';
        echo '</div>';
        echo '<div class="session-filters">';
        echo '<button class="filter-btn active" data-status="new,open">فعال</button>';
        echo '<button class="filter-btn" data-status="closed">بسته شده</button>';
        echo '<button class="filter-btn" data-status="all">همه</button>';
        echo '</div>';
        echo '<div class="session-list-content" id="session-list-content">';
        echo '<div class="empty-sessions">';
        echo '<div class="empty-sessions-icon">💬</div>';
        echo '<p>در حال بارگذاری گفتگوها...</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Chat Window
        echo '<div class="chat-window-area" id="chat-window-area">';
        echo '<div class="no-session-selected">یک گفتگو را از لیست انتخاب کنید.</div>';
        echo '</div>';
        
        echo '</div>'; // .wrap
        echo '</div>'; // #chat-admin-container
        
        // استایل‌های اضافی
        echo '<style>
            .admin-status-bar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 15px;
                background: #f0f0f1;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .operator-info {
                font-size: 14px;
            }
            .online-status {
                font-size: 14px;
            }
            .admin-online-count {
                font-weight: bold;
                color: #007cba;
            }
            .chat-admin-container .wrap {
                display: flex;
                gap: 20px;
            }
            .session-list-area {
                width: 300px;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                overflow: hidden;
            }
            .session-list-header {
                padding: 15px;
                background: #f0f0f1;
                border-bottom: 1px solid #ccd0d4;
                display: flex;
                justify-content: space-between;
                font-weight: bold;
            }
            .session-filters {
                padding: 10px;
                background: #f8f9fa;
                border-bottom: 1px solid #ccd0d4;
                display: flex;
                gap: 5px;
            }
            .session-filters .filter-btn {
                padding: 5px 10px;
                font-size: 12px;
            }
            .session-filters .filter-btn.active {
                background: #007cba;
                color: white;
                border-color: #007cba;
            }
            .session-list-content {
                height: 600px;
                overflow-y: auto;
            }
            .chat-window-area {
                flex: 1;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
            }
        </style>';
    }
    
    /**
     * رندر صفحه تنظیمات
     */
    public function render_settings_page(): void {
        // بررسی دسترسی - فقط مدیران
        if (!current_user_can('manage_options')) {
            echo '<div class="wrap">';
            echo '<h1>' . __('دسترسی غیرمجاز', 'wp-live-chat') . '</h1>';
            echo '<div class="notice notice-error"><p>';
            echo __('فقط مدیران سایت می‌توانند به تنظیمات دسترسی داشته باشند.', 'wp-live-chat');
            echo '</p></div>';
            echo '</div>';
            return;
        }
        
        $settings = $this->container->get_service('settings');
        $all_settings = $settings->get_all();
        
        echo '<div class="wrap">';
        echo '<h1>' . __('Live Chat Settings', 'wp-live-chat') . '</h1>';
        
        // نمایش پیام موفقیت
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo __('تنظیمات با موفقیت ذخیره شد.', 'wp-live-chat');
            echo '</p></div>';
        }
        
        // فرم تنظیمات
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wplc_save_settings">';
        wp_nonce_field('wplc_save_settings', 'wplc_settings_nonce');
        
        echo '<div class="wplc-settings-container">';
        
        // بخش Pusher
        echo '<div class="card">';
        echo '<h2 class="title">تنظیمات Pusher</h2>';
        echo '<p class="description">برای فعال کردن چت آنلاین، کلیدهای Pusher را از <a href="https://dashboard.pusher.com/" target="_blank">پنل Pusher</a> دریافت کنید.</p>';
        echo '<table class="form-table">';
        
        $pusher_fields = [
            'pusher_app_id' => 'App ID',
            'pusher_key' => 'Key',
            'pusher_secret' => 'Secret',
            'pusher_cluster' => 'Cluster'
        ];
        
        foreach ($pusher_fields as $key => $label) {
            echo '<tr>';
            echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
            echo '<td>';
            echo '<input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" ';
            echo 'value="' . esc_attr($all_settings[$key] ?? '') . '" class="regular-text" />';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</div>';
        
        // بخش متون
        echo '<div class="card">';
        echo '<h2 class="title">تنظیمات متون</h2>';
        echo '<table class="form-table">';
        
        $text_fields = [
            'chat_title' => 'عنوان چت',
            'welcome_message' => 'پیام خوش‌آمد',
            'offline_message' => 'پیام آفلاین',
            'input_placeholder' => 'متن داخل باکس پیام'
        ];
        
        foreach ($text_fields as $key => $label) {
            echo '<tr>';
            echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
            echo '<td>';
            
            if ($key === 'welcome_message' || $key === 'offline_message') {
                echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" ';
                echo 'rows="3" class="large-text">' . esc_textarea($all_settings[$key] ?? '') . '</textarea>';
            } else {
                echo '<input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" ';
                echo 'value="' . esc_attr($all_settings[$key] ?? '') . '" class="regular-text" />';
            }
            
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</div>';
        
        // بخش تماس
        echo '<div class="card">';
        echo '<h2 class="title">اطلاعات تماس</h2>';
        echo '<table class="form-table">';
        
        $contact_fields = [
            'phone_number' => 'شماره تلفن',
            'whatsapp_number' => 'شماره واتس‌آپ'
        ];
        
        foreach ($contact_fields as $key => $label) {
            echo '<tr>';
            echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
            echo '<td>';
            echo '<input type="tel" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" ';
            echo 'value="' . esc_attr($all_settings[$key] ?? '') . '" class="regular-text" ';
            echo 'placeholder="مثال: 09123456789" />';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</div>';
        
        // بخش مدیریت اپراتورها (فقط برای مدیران)
        echo '<div class="card">';
        echo '<h2 class="title">مدیریت اپراتورها</h2>';
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th scope="row">اپراتورهای فعال</th>';
        echo '<td>';
        
        $online_count = Ajax_Admin::get_online_operators_count();
        $total_operators = $this->count_chat_operators();
        
        echo '<p>تعداد اپراتورهای آنلاین: <strong>' . $online_count . '</strong></p>';
        echo '<p>تعداد کل اپراتورها: <strong>' . $total_operators . '</strong></p>';
        
        // لیست اپراتورها
        $operators = get_users(['role' => 'chat_operator']);
        if (!empty($operators)) {
            echo '<ul>';
            foreach ($operators as $operator) {
                $last_activity = get_user_meta($operator->ID, 'wplc_last_activity', true);
                $is_online = $last_activity && (current_time('timestamp') - (int)$last_activity <= 300);
                
                echo '<li>';
                echo esc_html($operator->display_name) . ' (' . esc_html($operator->user_email) . ')';
                echo ' - <span style="color:' . ($is_online ? 'green' : 'gray') . '">';
                echo $is_online ? 'آنلاین' : 'آفلاین';
                echo '</span>';
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p>هیچ اپراتوری تعریف نشده است.</p>';
        }
        
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        echo '</div>';
        
        // دکمه ذخیره
        echo '<div class="submit">';
        echo '<input type="submit" name="submit" id="submit" class="button button-primary" value="ذخیره تنظیمات">';
        echo '</div>';
        
        echo '</div>';
        echo '</form>';
        echo '</div>';
        
        // استایل‌های داخلی برای صفحه تنظیمات
        echo '<style>
            .wplc-settings-container {
                max-width: 800px;
            }
            .wplc-settings-container .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                margin-bottom: 20px;
                padding: 20px;
            }
            .wplc-settings-container .card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .wplc-settings-container .description {
                color: #646970;
                margin-top: -10px;
                margin-bottom: 20px;
            }
        </style>';
    }
    
    /**
     * شمارش تعداد اپراتورهای چت
     */
    private function count_chat_operators(): int {
        $operators = get_users(['role' => 'chat_operator', 'fields' => 'ID']);
        return count($operators);
    }
    
    /**
     * ذخیره تنظیمات
     */
    public function save_settings(): void {
        // بررسی مجوزها
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز');
        }
        
        // بررسی Nonce
        if (!isset($_POST['wplc_settings_nonce']) || 
            !wp_verify_nonce($_POST['wplc_settings_nonce'], 'wplc_save_settings')) {
            wp_die('Nonce verification failed');
        }
        
        $settings = $this->container->get_service('settings');
        
        // آماده‌سازی داده‌ها
        $new_settings = [];
        
        // تنظیمات Pusher
        $pusher_fields = ['pusher_app_id', 'pusher_key', 'pusher_secret', 'pusher_cluster'];
        foreach ($pusher_fields as $field) {
            if (isset($_POST[$field])) {
                $new_settings[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        
        // متون
        $text_fields = ['chat_title', 'welcome_message', 'offline_message', 'input_placeholder'];
        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                $new_settings[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        
        // اطلاعات تماس
        $contact_fields = ['phone_number', 'whatsapp_number'];
        foreach ($contact_fields as $field) {
            if (isset($_POST[$field])) {
                $new_settings[$field] = preg_replace('/\D/', '', sanitize_text_field($_POST[$field]));
            }
        }
        
        // ذخیره تنظیمات
        $settings->update($new_settings);
        
        // ریدایرکت با پیام موفقیت
        wp_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=wplc-settings')));
        exit;
    }
}