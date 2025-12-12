<?php
namespace WP_Live_Chat;
use WP_Live_Chat;

/**
 * مدیریت درخواست‌های AJAX از سمت پنل مدیریت
 */
class Ajax_Admin {
    
    private WP_Live_Chat $container;

    public function __construct(WP_Live_Chat $container) {
        $this->container = $container;
    }

    public function hooks(): void {
        // مطمئن شوید فقط کاربران با دسترسی 'manage_options' یا 'chat_operator' بتوانند این توابع را فراخوانی کنند
        add_action('wp_ajax_wplc_admin_send_message', [$this, 'admin_send_message']);
        add_action('wp_ajax_wplc_admin_close_session', [$this, 'admin_close_session']);
        add_action('wp_ajax_wplc_admin_get_session_details', [$this, 'admin_get_session_details']); 
        
        // دریافت لیست جلسات
        add_action('wp_ajax_wplc_admin_get_sessions', [$this, 'admin_get_sessions']);
        
        // دریافت تاریخچه پیام‌های یک جلسه
        add_action('wp_ajax_wplc_admin_get_chat_history', [$this, 'admin_get_chat_history']);
        
        // ردیابی فعالیت کاربران از طریق AJAX (اضافه شده)
        add_action('wp_ajax_wplc_admin_get_sessions', [$this, 'track_operator_activity'], 1);
        add_action('wp_ajax_wplc_admin_send_message', [$this, 'track_operator_activity'], 1);
        add_action('wp_ajax_wplc_admin_get_chat_history', [$this, 'track_operator_activity'], 1);
        add_action('wp_ajax_wplc_admin_get_session_details', [$this, 'track_operator_activity'], 1);
        add_action('wp_ajax_wplc_admin_close_session', [$this, 'track_operator_activity'], 1);
        add_action('wp_ajax_wplc_pusher_auth_admin', [$this, 'pusher_auth_admin']);
        
        // بررسی وضعیت آنلاین (برای فرانت‌اند)
        add_action('wp_ajax_wplc_check_admin_online', [$this, 'check_admin_online']);
        add_action('wp_ajax_nopriv_wplc_check_admin_online', [$this, 'check_admin_online']);

            // آپلود فایل
        add_action('wp_ajax_wplc_upload_file', [$this, 'handle_file_upload']);
        add_action('wp_ajax_wplc_delete_file', [$this, 'handle_file_delete']);
        
        // دریافت لیست فایل‌ها
        add_action('wp_ajax_wplc_get_files', [$this, 'get_session_files']);
    }

    /**
     * مدیریت آپلود فایل
     */
    public function handle_file_upload(): void {
        if (!check_ajax_referer('wplc_admin_nonce', 'security', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }
        
        if (!$this->check_chat_access()) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }
        
        // بررسی وجود فایل
        if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            wp_send_json_error(['message' => 'هیچ فایلی انتخاب نشده است.']);
        }
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $sender_type = 'admin';
        $sender_id = get_current_user_id();
        
        if (!$session_id) {
            wp_send_json_error(['message' => 'Session ID is missing.']);
        }
        
        try {
            $db_manager = $this->container->get_service('db_manager');
            $file_manager = new File_Manager($db_manager);
            
            $result = $file_manager->upload_file(
                $_FILES['file'],
                $session_id,
                $sender_type,
                $sender_id
            );
            
            // ارسال پیام از طریق Pusher برای نمایش فایل در چت
            $pusher_client = $this->container->get_service('pusher_client');
            if ($pusher_client && $pusher_client->is_initialized()) {
                $file_message = [
                    'session_id' => $session_id,
                    'sender_type' => $sender_type,
                    'sender_id' => $sender_id,
                    'message_type' => 'file',
                    'file_data' => $result,
                    'content' => 'فایل ارسال شد: ' . $result['file_name'],
                    'created_at' => current_time('mysql'),
                ];
                
                $pusher_client->trigger_event(
                    'private-session-' . $session_id,
                    'new-message',
                    $file_message
                );
            }
            
            wp_send_json_success([
                'success' => true,
                'file' => $result,
                'message' => 'فایل با موفقیت آپلود شد.'
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * مدیریت حذف فایل
     */
    public function handle_file_delete(): void {
        if (!check_ajax_referer('wplc_admin_nonce', 'security', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }
        
        if (!$this->check_chat_access()) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }
        
        $file_id = (int)($_POST['file_id'] ?? 0);
        
        if (!$file_id) {
            wp_send_json_error(['message' => 'File ID is missing.']);
        }
        
        try {
            $db_manager = $this->container->get_service('db_manager');
            $file_manager = new File_Manager($db_manager);
            
            $deleted = $file_manager->delete_file($file_id);
            
            if ($deleted) {
                wp_send_json_success(['message' => 'فایل با موفقیت حذف شد.']);
            } else {
                wp_send_json_error(['message' => 'خطا در حذف فایل.']);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * دریافت فایل‌های یک session
     */
    public function get_session_files(): void {
        if (!check_ajax_referer('wplc_admin_nonce', 'security', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }
        
        if (!$this->check_chat_access()) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (!$session_id) {
            wp_send_json_error(['message' => 'Session ID is missing.']);
        }
        
        $db_manager = $this->container->get_service('db_manager');
        $files = $db_manager->get_session_files($session_id);
        
        // اضافه کردن آیکون به هر فایل
        $file_manager = new File_Manager($db_manager);
        foreach ($files as &$file) {
            $file['icon'] = $file_manager->get_file_icon($file['file_type']);
            $file['formatted_size'] = $file_manager->format_file_size($file['file_size']);
        }
        
        wp_send_json_success([
            'files' => $files,
            'count' => count($files)
        ]);
    }

    // اضافه کردن این متد به کلاس Ajax_Admin
    public function admin_upload_file(): void {
        if (!check_ajax_referer('wplc_admin_nonce', 'security', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }
        
        if (!$this->check_chat_access()) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }
        
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'File upload error.']);
        }
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $sender_id = get_current_user_id();
        
        try {
            $db_manager = $this->container->get_service('db_manager');
            $file_manager = new File_Manager($db_manager);
            
            $result = $file_manager->upload_file(
                $_FILES['file'],
                $session_id,
                'admin',
                $sender_id
            );
            
            // ارسال از طریق Pusher
            $pusher_client = $this->container->get_service('pusher_client');
            if ($pusher_client && $pusher_client->is_initialized()) {
                $file_message = [
                    'session_id' => $session_id,
                    'sender_type' => 'admin',
                    'sender_id' => $sender_id,
                    'message_type' => 'file',
                    'file_data' => $result,
                    'created_at' => current_time('mysql'),
                ];
                
                $pusher_client->trigger_event(
                    'private-session-' . $session_id,
                    'new-message',
                    $file_message
                );
            }
            
            wp_send_json_success([
                'success' => true,
                'file_data' => $result
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
        
    /**
     * ردیابی فعالیت اپراتورها و مدیران (اضافه شده)
     */
    public function track_operator_activity(): void {
        // بررسی اینکه آیا کاربر مجوز دسترسی به چت را دارد
        if (!current_user_can('manage_options') && !$this->is_chat_operator()) {
            return;
        }
        
        $user_id = get_current_user_id();
        
        // ذخیره آخرین فعالیت
        update_user_meta($user_id, 'wplc_last_activity', current_time('timestamp'));
        update_user_meta($user_id, 'wplc_last_activity_time', current_time('mysql'));
        update_user_meta($user_id, 'wplc_last_activity_action', current_action());
        
        // ذخیره اطلاعات جلسه اگر موجود باشد
        if (isset($_POST['session_id'])) {
            $session_id = sanitize_text_field($_POST['session_id']);
            update_user_meta($user_id, 'wplc_last_active_session', $session_id);
        }
        
        error_log('WP Live Chat: Operator activity tracked - User: ' . $user_id . ', Action: ' . current_action());
    }
    
    /**
     * بررسی اینکه آیا کاربر اپراتور چت است
     */
    private function is_chat_operator(): bool {
        $user = wp_get_current_user();
        return in_array('chat_operator', (array) $user->roles);
    }
    
    /**
     * بررسی دسترسی کاربر (اضافه شده)
     */
    private function check_chat_access(): bool {
        // مدیران و اپراتورهای چت دسترسی دارند
        return current_user_can('manage_options') || $this->is_chat_operator();
    }
    
    /**
     * بررسی وضعیت آنلاین ادمین‌ها و اپراتورها
     */
    public function check_admin_online(): void {
        check_ajax_referer('wplc_check_admin', 'security');
        
        $pusher_client = $this->container->get_service('pusher_client');
        $is_online = false;
        $online_count = 0;
        $operators = [];
        
        // روش 1: استفاده از Pusher Client (اگر مقداردهی شده)
        if ($pusher_client && $pusher_client->is_initialized()) {
            try {
                // استفاده از متد جدید get_online_operators
                if (method_exists($pusher_client, 'get_online_operators')) {
                    $operators = $pusher_client->get_online_operators();
                    $online_count = count($operators);
                    $is_online = $online_count > 0;
                } else {
                    // fallback به متد قدیمی
                    $is_online = $pusher_client->is_admin_online();
                }
            } catch (\Exception $e) {
                error_log('WP Live Chat Admin Check Error: ' . $e->getMessage());
            }
        }
        
        // روش 2: استفاده از متدهای کمکی در کلاس Ajax_Admin
        if (!$is_online || empty($operators)) {
            $is_online = self::is_any_operator_online();
            $online_count = self::get_online_operators_count();
            
            // اگر اپراتور آنلاین داریم، اطلاعاتشان را بگیر
            if ($is_online && $online_count > 0) {
                $operators = $this->get_online_operators_info();
            }
        }
        
        wp_send_json_success([
            'is_online' => $is_online,
            'online_count' => $online_count,
            'operators' => $operators,
            'timestamp' => current_time('timestamp'),
            'message' => $is_online ? 
                ($online_count > 1 ? "{$online_count} اپراتور آنلاین" : 'اپراتور آنلاین') : 
                'هیچ اپراتوری آنلاین نیست'
        ]);
    }

    /**
     * دریافت لیستی از Sessions برای نمایش در پنل ادمین
     */
    public function admin_get_sessions(): void {
        // بررسی nonce
        if (!check_ajax_referer('wplc_admin_nonce', 'security', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }
        
        // بررسی دسترسی جدید
        if (!$this->check_chat_access()) {
            wp_send_json_error(['message' => 'Permission denied. You need administrator or chat operator role.']);
        }
        
        $db_manager = $this->container->get_service('db_manager');
        
        if (!$db_manager) {
            wp_send_json_error(['message' => 'Database service not available.']);
        }
        
        // دریافت وضعیت‌های مورد نظر
        $status = sanitize_text_field($_POST['status'] ?? 'new,open');
        $limit = (int)($_POST['limit'] ?? 50);
        
        error_log('WP Live Chat: Getting sessions with status: ' . $status . ', limit: ' . $limit);
        
        // تبدیل وضعیت به آرایه
        $status_array = explode(',', $status);
        $status_array = array_map('trim', $status_array);
        $status_array = array_filter($status_array); // حذف مقادیر خالی
        
        try {
            // دریافت sessions فعال - استفاده از متد جدید
            $sessions = $db_manager->get_sessions_with_last_message($status_array, $limit);
            
            error_log('WP Live Chat: Found ' . count($sessions) . ' sessions');
            
            if (empty($sessions)) {
                wp_send_json_success([
                    'message' => 'No sessions found.',
                    'sessions' => [],
                    'count' => 0
                ]);
            }
            
            // فرمت کردن زمان
            foreach ($sessions as &$session) {
                $session['time_ago'] = $this->format_time_ago($session['updated_at']);
                $session['message_preview'] = $session['last_message'] ?? 'گفتگوی جدید';
                
                // اگر نام کاربر نداریم، از session_id استفاده کنیم
                if (empty($session['user_name'])) {
                    $session['user_name'] = 'کاربر ' . substr($session['session_id'], -6);
                }
            }
            
            wp_send_json_success([
                'sessions' => $sessions,
                'count' => count($sessions),
                'current_user' => [
                    'id' => get_current_user_id(),
                    'name' => wp_get_current_user()->display_name,
                    'role' => $this->is_chat_operator() ? 'chat_operator' : 'administrator'
                ]
            ]);
            
        } catch (\Exception $e) {
            error_log('WP Live Chat: Error getting sessions: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error retrieving sessions: ' . $e->getMessage()]);
        }
    }

    // اضافه کردن تابع کمکی برای فرمت زمان
    private function format_time_ago($date_string): string {
        if (empty($date_string)) {
            return 'همین الآن';
        }
        
        $now = new \DateTime();
        $date = new \DateTime($date_string);
        $diff = $now->diff($date);
        
        if ($diff->y > 0) {
            return $diff->y . ' سال پیش';
        }
        if ($diff->m > 0) {
            return $diff->m . ' ماه پیش';
        }
        if ($diff->d > 0) {
            return $diff->d . ' روز پیش';
        }
        if ($diff->h > 0) {
            return $diff->h . ' ساعت پیش';
        }
        if ($diff->i > 0) {
            return $diff->i . ' دقیقه پیش';
        }
        
        return 'همین الآن';
    }
    
    /**
     * دریافت اطلاعات اپراتورهای آنلاین (اضافه شده)
     */
    private function get_online_operators_info(): array {
        $online_operators = [];
        $online_threshold = 5 * 60; // 5 دقیقه
        $allowed_roles = ['administrator', 'chat_operator'];
        
        foreach ($allowed_roles as $role) {
            $users = get_users([
                'role' => $role,
                'fields' => ['ID', 'display_name', 'user_email'],
            ]);
            
            foreach ($users as $user) {
                $last_activity = get_user_meta($user->ID, 'wplc_last_activity', true);
                $is_online = false;
                
                if ($last_activity) {
                    $time_diff = current_time('timestamp') - (int)$last_activity;
                    $is_online = $time_diff <= $online_threshold;
                } else {
                    // اگر اطلاعات فعالیت نداریم، بررسی کنیم آیا کاربر در حال حاضر لاگین است
                    $is_online = is_user_logged_in() && get_current_user_id() == $user->ID;
                }
                
                if ($is_online) {
                    $online_operators[] = [
                        'id' => $user->ID,
                        'name' => $user->display_name,
                        'email' => $user->user_email,
                        'role' => $role,
                        'role_label' => $role === 'administrator' ? 'مدیر کل' : 'اپراتور چت',
                        'last_activity' => $last_activity ? date('H:i:s', $last_activity) : 'نامشخص',
                        'is_current_user' => (get_current_user_id() == $user->ID)
                    ];
                }
            }
        }
        
        return $online_operators;
    }
    
    /**
     * دریافت تاریخچه چت (بهینه‌سازی شده)
     */
    public function admin_get_chat_history(): void {
        if (!check_ajax_referer('wplc_admin_nonce', 'security', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
            return;
        }
        
        // بررسی دسترسی جدید
        if (!$this->check_chat_access()) {
            wp_send_json_error(['message' => 'Permission denied. You need administrator or chat operator role.']);
            return;
        }
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $last_message_id = sanitize_text_field($_POST['last_message_id'] ?? '');
        
        if (!$session_id) {
            wp_send_json_error(['message' => 'Session ID is missing.']);
            return;
        }
        
        $db_manager = $this->container->get_service('db_manager');
        
        if (!$db_manager) {
            wp_send_json_error(['message' => 'Database service not available.']);
            return;
        }
        
        try {
            // فقط پیام‌های جدیدتر را دریافت کن
            $history = $db_manager->get_session_history($session_id, $last_message_id);
            
            // اگر تاریخچه خالی است اما session وجود دارد، فقط اطلاعات session را برگردان
            if (empty($history)) {
                $session_details = $db_manager->get_session_details($session_id);
                wp_send_json_success([
                    'history' => [],
                    'session' => $session_details,
                    'count' => 0
                ]);
                return;
            }
            
            // پیدا کردن آخرین پیام برای دفعه بعد
            $last_message = end($history);
            reset($history);
            
            wp_send_json_success([
                'history' => $history,
                'last_message_id' => $last_message ? ($last_message['id'] ?? $last_message['created_at']) : null,
                'count' => count($history)
            ]);
            
        } catch (\Exception $e) {
            error_log('WP Live Chat DEBUG: Error getting history: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error retrieving chat history: ' . $e->getMessage()]);
        }
    }

    /**
     * دریافت جزئیات یک Session برای نمایش در پنجره چت ادمین
     */
    public function admin_get_session_details(): void {
        if (!check_ajax_referer('wplc_admin_nonce', 'security', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }
        
        // بررسی دسترسی جدید
        if (!$this->check_chat_access()) {
            wp_send_json_error(['message' => 'Permission denied. You need administrator or chat operator role.']);
        }
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (!$session_id) {
            wp_send_json_error(['message' => 'Session ID is missing.']);
        }
        
        $db_manager = $this->container->get_service('db_manager');
        
        if (!$db_manager) {
            wp_send_json_error(['message' => 'Database service not available.']);
        }
        
        $session = $db_manager->get_session_details($session_id);
        
        if (!$session) {
            wp_send_json_error(['message' => 'Session not found.']);
        }

        wp_send_json_success([
            'session' => $session,
            'message' => 'Session details retrieved.'
        ]);
    }

    /**
     * ارسال پیام از طرف ادمین (اصلاح شده)
     */
    public function admin_send_message(): void {
        if (!check_ajax_referer('wplc_admin_nonce', 'security', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }
        
        // بررسی دسترسی جدید
        if (!$this->check_chat_access()) {
            wp_send_json_error(['message' => 'Permission denied. You need administrator or chat operator role.']);
        }

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $message_content = sanitize_text_field($_POST['message'] ?? '');
        $user_id = get_current_user_id();
        $user_name = get_user_by('id', $user_id)->display_name ?? 'ادمین';
        
        if (!$session_id || empty($message_content)) {
            wp_send_json_error(['message' => 'Missing data.']);
        }
        
        $db_manager = $this->container->get_service('db_manager');
        $pusher_client = $this->container->get_service('pusher_client');

        if (!$db_manager) {
            wp_send_json_error(['message' => 'Database service not available.']);
        }

        // 1. ذخیره پیام در دیتابیس و دریافت ID
        global $wpdb;
        $table = $wpdb->prefix . 'wplc_messages';
        
        $message_data = [
            'session_id' => $session_id,
            'sender_type' => 'admin',
            'sender_id' => $user_id,
            'message_content' => $message_content,
            'created_at' => current_time('mysql', 1),
        ];
        
        $result = $wpdb->insert($table, $message_data);
        
        if (!$result) {
            wp_send_json_error(['message' => 'Failed to save admin message.']);
        }
        
        $message_id = $wpdb->insert_id;
        
        // 2. به‌روزرسانی وضعیت Session
        $db_manager->update_session_status($session_id, 'open');

        // 3. ارسال پیام به کاربر از طریق Pusher
        $pusher_sent = false;
        if ($pusher_client && $pusher_client->is_initialized()) {
            try {
                $admin_message_data = [
                    'session_id' => $session_id,
                    'sender_type' => 'admin',
                    'sender_id' => $user_id, // اضافه کردن sender_id
                    'message_id' => $message_id, // اضافه کردن message_id
                    'content' => $message_content,
                    'user_name' => $user_name,
                    'created_at' => current_time('mysql'),
                    'operator_role' => $this->is_chat_operator() ? 'chat_operator' : 'administrator'
                ];
                
                $pusher_sent = $pusher_client->trigger_event(
                    'private-session-' . $session_id, 
                    'new-message', 
                    $admin_message_data
                );
                
            } catch (\Exception $e) {
                error_log('WP Live Chat: Pusher error sending admin message: ' . $e->getMessage());
            }
        }

        // 4. پاسخ شامل message_id برای جلوگیری از تکرار
        wp_send_json_success([
            'success' => true,
            'message_saved' => true,
            'message_id' => $message_id, // برگرداندن شناسه پیام
            'pusher_sent' => $pusher_sent,
            'timestamp' => current_time('mysql'),
            'sender_id' => $user_id,
            'sender_role' => $this->is_chat_operator() ? 'chat_operator' : 'administrator'
        ]);
    }

    /**
     * بستن یک Session توسط ادمین
     */
    public function admin_close_session(): void {
        if (!check_ajax_referer('wplc_admin_nonce', 'security', false)) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }
        
        // بررسی دسترسی جدید
        if (!$this->check_chat_access()) {
            wp_send_json_error(['message' => 'Permission denied. You need administrator or chat operator role.']);
        }

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (!$session_id) {
            wp_send_json_error(['message' => 'Session ID is missing.']);
        }
        
        $db_manager = $this->container->get_service('db_manager');
        $pusher_client = $this->container->get_service('pusher_client');

        if (!$db_manager) {
            wp_send_json_error(['message' => 'Database service not available.']);
        }

        // به‌روزرسانی وضعیت Session
        $status_updated = $db_manager->update_session_status($session_id, 'closed');
        
        if (!$status_updated) {
            wp_send_json_error(['message' => 'Failed to close session.']);
        }

        // ارسال رویداد بسته شدن چت
        $pusher_sent = false;
        if ($pusher_client && $pusher_client->is_initialized()) {
            $data = [
                'session_id' => $session_id,
                'closed_by' => get_user_by('id', get_current_user_id())->display_name ?? 'ادمین',
                'closed_by_role' => $this->is_chat_operator() ? 'اپراتور چت' : 'مدیر کل',
                'timestamp' => current_time('mysql'),
            ];

            try {
                $pusher_sent = $pusher_client->trigger_event('private-session-' . $session_id, 'chat-closed', $data);
            } catch (\Exception $e) {
                error_log('WP Live Chat: Pusher error closing session: ' . $e->getMessage());
            }
        }

        wp_send_json_success([
            'message' => 'Session closed successfully.',
            'status_updated' => $status_updated,
            'pusher_sent' => $pusher_sent
        ]);
    }
    
    /**
     * متد استاتیک برای بررسی آنلاین بودن هر اپراتور/مدیر
     */
    public static function is_any_operator_online(): bool {
        $online_threshold = 5 * 60; // 5 دقیقه
        $allowed_roles = ['administrator', 'chat_operator'];
        
        foreach ($allowed_roles as $role) {
            $users = get_users([
                'role' => $role,
                'fields' => 'ID',
            ]);
            
            foreach ($users as $user_id) {
                $last_activity = get_user_meta($user_id, 'wplc_last_activity', true);
                
                if ($last_activity) {
                    $time_diff = current_time('timestamp') - (int)$last_activity;
                    if ($time_diff <= $online_threshold) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * متد استاتیک برای دریافت تعداد اپراتورهای آنلاین
     */
    public static function get_online_operators_count(): int {
        $count = 0;
        $online_threshold = 5 * 60;
        $allowed_roles = ['administrator', 'chat_operator'];
        
        foreach ($allowed_roles as $role) {
            $users = get_users([
                'role' => $role,
                'fields' => 'ID',
            ]);
            
            foreach ($users as $user_id) {
                $last_activity = get_user_meta($user_id, 'wplc_last_activity', true);
                
                if ($last_activity) {
                    $time_diff = current_time('timestamp') - (int)$last_activity;
                    if ($time_diff <= $online_threshold) {
                        $count++;
                    }
                } elseif (is_user_logged_in() && get_current_user_id() == $user_id) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
    
    /**
     * متد استاتیک برای ردیابی فعالیت از خارج کلاس (برای هوک admin_init)
     */
    public static function track_admin_page_activity(): void {
        // فقط در صفحات ادمین و برای کاربران مجاز
        if (!is_admin() || (!current_user_can('manage_options') && !self::is_user_chat_operator())) {
            return;
        }
        
        $user_id = get_current_user_id();
        update_user_meta($user_id, 'wplc_last_activity', current_time('timestamp'));
        
        // اگر در صفحه چت هستیم، مشخص کنیم
        $current_screen = get_current_screen();
        if ($current_screen && strpos($current_screen->id, 'wplc-chat-console') !== false) {
            update_user_meta($user_id, 'wplc_on_chat_page', true);
            update_user_meta($user_id, 'wplc_chat_page_last_seen', current_time('mysql'));
        } else {
            delete_user_meta($user_id, 'wplc_on_chat_page');
        }
    }
    
    /**
     * متد استاتیک برای بررسی اینکه آیا کاربر اپراتور چت است
     */
    public static function is_user_chat_operator(int $user_id = null): bool {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }
        
        return in_array('chat_operator', (array) $user->roles);
    }

        /**
     * احراز هویت Pusher برای ادمین
     */
    public function pusher_auth_admin(): void {
        // بررسی nonce مخصوص ادمین
        if (!check_ajax_referer('wplc_pusher_auth', 'security', false)) {
            wp_send_json_error('Invalid nonce', 403);
        }
        
        // فقط ادمین‌ها و اپراتورها دسترسی دارند
        if (!current_user_can('manage_options') && !$this->is_chat_operator()) {
            wp_send_json_error('Access denied', 403);
        }
        
        $socket_id = sanitize_text_field($_POST['socket_id'] ?? '');
        $channel_name = sanitize_text_field($_POST['channel_name'] ?? '');

        if (!$socket_id || !$channel_name) {
            wp_send_json_error('Missing socket ID or channel name.', 400);
        }
        
        $pusher_client = $this->container->get_service('pusher_client');
        
        if (!$pusher_client || !$pusher_client->is_initialized()) {
            wp_send_json_error('Pusher not initialized.', 500);
        }

        try {
            $auth_response = $pusher_client->authenticate_channel($channel_name, $socket_id);
            
            header('Content-Type: application/json');
            echo $auth_response;
            exit;
        } catch (\Exception $e) {
            error_log('WP Live Chat Admin Pusher Auth Error: ' . $e->getMessage());
            wp_send_json_error('Authentication failed: ' . $e->getMessage(), 500);
        }
    }
}