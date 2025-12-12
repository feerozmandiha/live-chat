<?php
namespace WP_Live_Chat;

/**
 * مدیریت یکپارچه پیام‌ها - جلوگیری از تکراری بودن
 */
class MessageManager {
    
    private DB_Manager $db_manager;
    private Pusher_Client $pusher_client;
    
    public function __construct(DB_Manager $db_manager, Pusher_Client $pusher_client) {
        $this->db_manager = $db_manager;
        $this->pusher_client = $pusher_client;
    }
    
    /**
     * ارسال پیام از کاربر
     */
    public function sendUserMessage(string $session_id, string $message): array {
        // 1. بررسی session
        if (!$this->db_manager->session_exists($session_id)) {
            $this->db_manager->create_session($session_id);
        }
        
        // 2. ذخیره پیام
        $message_saved = $this->db_manager->save_message($session_id, 'user', $message);
        
        if (!$message_saved) {
            throw new \Exception('Failed to save user message');
        }
        
        // 3. پردازش flow
        $flow_manager = new ConversationFlowManager();
        $flow_result = $flow_manager->process_user_message($session_id, $message, $this->db_manager);
        
        // 4. ارسال از طریق Pusher (فقط برای ادمین‌ها)
        $pusher_sent = false;
        if ($this->pusher_client->is_initialized()) {
            $user_data = [
                'session_id' => $session_id,
                'sender_type' => 'user',
                'content' => $message,
                'created_at' => current_time('mysql'),
                'flow_step' => $flow_result['step']
            ];
            
            $pusher_sent = $this->pusher_client->send_to_admins('new-user-message', $user_data);
        }
        
        return [
            'success' => true,
            'message_saved' => $message_saved,
            'pusher_sent' => $pusher_sent,
            'flow_step' => $flow_result['step'],
            'system_response' => $flow_result['response_message'] ?? null
        ];
    }
    
    /**
     * ارسال پیام از ادمین
     */
    public function sendAdminMessage(string $session_id, string $message, int $admin_id): array {
        // 1. ذخیره پیام
        $message_saved = $this->db_manager->save_message($session_id, 'admin', $message, $admin_id);
        
        if (!$message_saved) {
            throw new \Exception('Failed to save admin message');
        }
        
        // 2. به‌روزرسانی وضعیت session
        $this->db_manager->update_session_status($session_id, 'open');
        
        // 3. ارسال از طریق Pusher (فقط برای کاربر)
        $pusher_sent = false;
        if ($this->pusher_client->is_initialized()) {
            $admin_data = [
                'session_id' => $session_id,
                'sender_type' => 'admin',
                'sender_id' => $admin_id, // شناسه ادمین برای جلوگیری از تکرار
                'content' => $message,
                'created_at' => current_time('mysql')
            ];
            
            $pusher_sent = $this->pusher_client->send_to_user($session_id, 'new-message', $admin_data);
        }
        
        return [
            'success' => true,
            'message_saved' => $message_saved,
            'pusher_sent' => $pusher_sent,
            'admin_id' => $admin_id // برگرداندن شناسه برای جلوگیری از تکرار
        ];
    }
    
    /**
     * دریافت تاریخچه با کش
     */
    public function getHistory(string $session_id, string $last_message_id = null): array {
        static $cache = [];
        $cache_key = $session_id . '_' . ($last_message_id ?: 'all');
        
        // استفاده از کش برای کاهش درخواست‌های دیتابیس
        if (!isset($cache[$cache_key])) {
            $history = $this->db_manager->get_session_history($session_id, $last_message_id);
            $cache[$cache_key] = $history;
            
            // کش را بعد از 30 ثانیه پاک کن
            add_action('shutdown', function() use ($cache_key) {
                unset($cache[$cache_key]);
            });
        }
        
        return $cache[$cache_key];
    }
}