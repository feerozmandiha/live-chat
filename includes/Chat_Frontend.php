<?php
namespace WP_Live_Chat;
use WP_Live_Chat;

/**
 * مدیریت منطق چت، AJAX و Pusher Auth در فرانت‌اند
 */
class Chat_Frontend {
    
    private WP_Live_Chat $container;

    public function __construct(WP_Live_Chat $container) {
        $this->container = $container;
    }

    public function hooks(): void {
        // احراز هویت Pusher (برای کانال‌های خصوصی و حضور)
        add_action('wp_ajax_wplc_pusher_auth', [$this, 'pusher_auth']);
        add_action('wp_ajax_nopriv_wplc_pusher_auth', [$this, 'pusher_auth']);

        // دریافت تاریخچه چت
        add_action('wp_ajax_wplc_get_history', [$this, 'get_chat_history']);
        add_action('wp_ajax_nopriv_wplc_get_history', [$this, 'get_chat_history']);
        
        // ارسال پیام جدید از طرف کاربر
        add_action('wp_ajax_wplc_send_message', [$this, 'handle_user_message']);
        add_action('wp_ajax_nopriv_wplc_send_message', [$this, 'handle_user_message']);

        add_action('wp_ajax_wplc_upload_file_user', [$this, 'handle_user_file_upload']);
        add_action('wp_ajax_nopriv_wplc_upload_file_user', [$this, 'handle_user_file_upload']);

        add_action('wp_ajax_wplc_upload_file_user', [$this, 'handle_user_file_upload']);
        add_action('wp_ajax_nopriv_wplc_upload_file_user', [$this, 'handle_user_file_upload']);
    }

    /**
     * مدیریت آپلود فایل از طرف کاربر
     */
    public function handle_user_file_upload(): void {
        check_ajax_referer('wplc_ajax_nonce', 'security');
        
        // بررسی وجود فایل
        if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            wp_send_json_error(['message' => 'هیچ فایلی انتخاب نشده است.']);
        }
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $sender_type = 'user';
        
        if (!$session_id) {
            wp_send_json_error(['message' => 'Session ID is missing.']);
        }
        
        try {
            $db_manager = $this->container->get_service('db_manager');
            
            if (!$db_manager) {
                throw new \Exception('Database service not available.');
            }
            
            $file_manager = new File_Manager($db_manager);
            
            $result = $file_manager->upload_file(
                $_FILES['file'],
                $session_id,
                $sender_type
            );
            
            // ✅ دریافت اطلاعات session برای نام کاربر
            $session_details = $db_manager->get_session_details($session_id);
            $user_name = $session_details['user_name'] ?? 'کاربر';
            
            // ✅ ذخیره پیام در دیتابیس با اطلاعات کامل
            $message_content = sprintf(
                '📎 فایل: %s (%s)',
                $result['file_name'],
                $file_manager->format_file_size($_FILES['file']['size'])
            );
            
            $db_manager->save_message($session_id, 'user', $message_content);
            
            // ✅ ارسال نوتیفیکیشن به ادمین‌ها با اطلاعات کامل
            $pusher_client = $this->container->get_service('pusher_client');
            if ($pusher_client && $pusher_client->is_initialized()) {
                $file_message = [
                    'session_id' => $session_id,
                    'sender_type' => $sender_type,
                    'sender_id' => $session_id,
                    'user_name' => $user_name,
                    'message_type' => 'file',
                    'file_data' => $result, // ارسال کامل داده‌های فایل
                    'content' => $message_content,
                    'created_at' => current_time('mysql'),
                ];
                
                $pusher_client->trigger_event(
                    'private-admin-new-sessions',
                    'new-user-message',
                    $file_message
                );
            }
            
            // ✅ پاسخ به کاربر با داده‌های کامل
            wp_send_json_success([
                'success' => true,
                'message' => 'فایل با موفقیت آپلود شد.',
                'file_data' => [
                    'file_id' => $result['file_id'] ?? null,
                    'file_name' => $result['file_name'] ?? '',
                    'file_url' => $result['file_url'] ?? '',
                    'file_type' => $result['file_type'] ?? '',
                    'file_size' => $result['file_size'] ?? 0,
                    'mime_type' => $result['mime_type'] ?? '',
                    'formatted_size' => $file_manager->format_file_size($result['file_size'] ?? 0),
                ],
                'user_name' => $user_name
            ]);
            
        } catch (\Exception $e) {
            error_log('WP Live Chat: File upload error - ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    /**
     * تابع کمکی برای بررسی خطاها
     */
    private function check_upload_permissions(): array {
        $errors = [];
        
        // بررسی memory limit
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = $this->convert_to_bytes($memory_limit);
        
        if ($memory_limit_bytes < 64 * 1024 * 1024) { // کمتر از 64MB
            $errors[] = "Memory limit کم است: {$memory_limit} (حداقل 64M توصیه می‌شود)";
        }
        
        // بررسی upload_max_filesize
        $upload_max = ini_get('upload_max_filesize');
        $upload_max_bytes = $this->convert_to_bytes($upload_max);
        
        if ($upload_max_bytes < 10 * 1024 * 1024) { // کمتر از 10MB
            $errors[] = "upload_max_filesize کم است: {$upload_max} (حداقل 10M نیاز است)";
        }
        
        // بررسی post_max_size
        $post_max = ini_get('post_max_size');
        $post_max_bytes = $this->convert_to_bytes($post_max);
        
        if ($post_max_bytes < 10 * 1024 * 1024) { // کمتر از 10MB
            $errors[] = "post_max_size کم است: {$post_max} (حداقل 10M نیاز است)";
        }
        
        return $errors;
    }

    /**
     * تبدیل مقدار حافظه به بایت
     */
    private function convert_to_bytes(string $value): int {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;
        
        switch ($last) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        
        return $value;
    }


    /**
     * احراز هویت درخواست‌های Pusher
     */
    public function pusher_auth(): void {
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
            error_log('WP Live Chat Pusher Auth Error: ' . $e->getMessage());
            wp_send_json_error('Authentication failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * دریافت تاریخچه چت - اصلاح شده
     */
    public function get_chat_history(): void {
        check_ajax_referer('wplc_ajax_nonce', 'security');

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (!$session_id) {
            wp_send_json_error(['message' => 'Session ID is missing.']);
        }
        
        $db_manager = $this->container->get_service('db_manager');
        
        if (!$db_manager) {
            wp_send_json_error(['message' => 'Database service not available.']);
        }
        
        error_log('WP Live Chat DEBUG: Getting history for session: ' . $session_id);
        
        // دریافت تاریخچه پیام‌ها
        $history = $db_manager->get_session_history($session_id);
        
        error_log('WP Live Chat DEBUG: History count: ' . count($history));
        
        // اگر تاریخچه خالی است، ولی جلسه وجود دارد
        if (empty($history)) {
            // بررسی اینکه آیا این جلسه اصلاً وجود دارد
            if (!$db_manager->session_exists($session_id)) {
                // اگر جلسه وجود ندارد، یک جلسه جدید ایجاد کنیم
                $db_manager->create_session($session_id);
                error_log('WP Live Chat DEBUG: Created new session');
                
                wp_send_json_success([
                    'history' => [],
                    'count' => 0
                ]);
                return;
            }
            
            wp_send_json_success([
                'history' => [],
                'count' => 0
            ]);
            return;
        }
        
        // فرمت کردن تاریخچه برای نمایش بهتر
        $formatted_history = [];
        foreach ($history as $message) {
            $formatted_history[] = [
                'id' => $message['id'] ?? null,
                'session_id' => $message['session_id'],
                'sender_type' => $message['sender_type'],
                'sender_id' => $message['sender_id'] ?? null,
                'content' => $message['content'] ?? $message['message_content'],
                'created_at' => $message['created_at'],
                'sender_name' => $this->get_sender_name($message, $db_manager)
            ];
        }
        
        wp_send_json_success([
            'history' => $formatted_history,
            'count' => count($formatted_history)
        ]);
    }

        /**
     * دریافت نام فرستنده
     */
    private function get_sender_name(array $message, DB_Manager $db_manager): string {
        if ($message['sender_type'] === 'user') {
            $session_details = $db_manager->get_session_details($message['session_id']);
            return $session_details['user_name'] ?? 'کاربر';
        } elseif ($message['sender_type'] === 'admin') {
            if ($message['sender_id']) {
                $user = get_user_by('id', $message['sender_id']);
                return $user ? $user->display_name : 'پشتیبان';
            }
            return 'پشتیبان';
        }
        return 'سیستم';
    }
    

    /**
     * مدیریت پیام‌های ورودی کاربر (ساده شده)
     */

    /**
     * مدیریت پیام‌های ورودی کاربر (اصلاح شده)
     */
    public function handle_user_message(): void {
        check_ajax_referer('wplc_ajax_nonce', 'security');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $message_content = sanitize_text_field($_POST['message'] ?? '');
        
        if (!$session_id || empty($message_content)) {
            wp_send_json_error(['message' => 'Missing data.']);
        }
        
        $db_manager = $this->container->get_service('db_manager');
        $pusher_client = $this->container->get_service('pusher_client');
        
        if (!$db_manager) {
            wp_send_json_error(['message' => 'Database service not available.']);
        }
        
        // 1. ایجاد session اگر وجود ندارد
        if (!$db_manager->session_exists($session_id)) {
            $db_manager->create_session($session_id);
        }
        
        // 2. ذخیره پیام کاربر
        $message_saved = $db_manager->save_message($session_id, 'user', $message_content);
    // دریافت ID پیام ذخیره شده
        $new_message_id = $this->get_last_message_id($session_id);
        
        if (!$message_saved) {
            wp_send_json_error(['message' => 'Failed to save message.']);
        }
        
        // 3. دریافت اطلاعات جلسه
        $session_details = $db_manager->get_session_details($session_id);
        
        // 4. پردازش جریان گفتگو
        $flow_manager = new ConversationFlowManager();
        $flow_result = $flow_manager->process_user_message($session_id, $message_content, $db_manager);
        
        // 5. ارسال نوتیفیکیشن به ادمین‌ها
        $pusher_sent = false;
        if ($pusher_client && $pusher_client->is_initialized()) {
            try {
                $user_message_data = [
                    'session_id' => $session_id,
                    'sender_type' => 'user',
                    'sender_id' => $session_id,
                    'content' => $message_content,
                    'user_name' => $session_details['user_name'] ?? 'کاربر',
                    'created_at' => current_time('mysql'),
                ];
                
                $pusher_sent = $pusher_client->trigger_event(
                    'private-admin-new-sessions',
                    'new-user-message',
                    $user_message_data
                );
                
            } catch (\Exception $e) {
                error_log('WP Live Chat: Pusher error: ' . $e->getMessage());
            }
        }
        
        // 6. آماده کردن پاسخ
        $response = [
            'success' => true,
            'message_saved' => true,
            'message_id' => $new_message_id, // اضافه شده
            'pusher_sent' => $pusher_sent,
            'system_response' => null
        ];
        
        // 7. اگر پاسخ سیستمی وجود دارد
        if (!empty($flow_result['response_message'])) {
            $response['system_response'] = [
                'content' => $flow_result['response_message'],
                'created_at' => current_time('mysql')
            ];
            
            // پاسخ سیستمی در دیتابیس ذخیره شود
            $db_manager->save_message($session_id, 'system', $flow_result['response_message']);
        }
        
        wp_send_json_success($response);
    }

    // اضافه کردن تابع کمکی برای دریافت آخرین message_id
    private function get_last_message_id($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplc_messages';
        
        $query = $wpdb->prepare(
            "SELECT id FROM {$table} WHERE session_id = %s ORDER BY created_at DESC LIMIT 1",
            $session_id
        );
        
        return $wpdb->get_var($query);
    }    
}