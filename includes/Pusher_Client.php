<?php
namespace WP_Live_Chat;

/**
 * مدیریت تمام تعاملات با سرور Pusher
 */
class Pusher_Client {
    
    private $pusher = null;
    private bool $is_initialized = false;
    private array $config = [];
    private static ?Pusher_Client $instance = null;

    public function __construct(string $app_id, string $key, string $secret, string $cluster) {
        $this->config = [
            'app_id' => $app_id,
            'key' => $key,
            'secret' => $secret,
            'cluster' => $cluster
        ];
        
        $this->init();
    }

    /**
     * راه‌اندازی Pusher (Singleton)
     */
    private function init(): void {
        // جلوگیری از راه‌اندازی مکرر
        if ($this->is_initialized) {
            return;
        }
        
        try {
            // بررسی وجود کلاس Pusher
            if (!class_exists('Pusher\Pusher')) {
                error_log('WP Live Chat: Pusher\Pusher class not found');
                $this->is_initialized = false;
                return;
            }
            
            $this->pusher = new \Pusher\Pusher(
                $this->config['key'],
                $this->config['secret'],
                $this->config['app_id'],
                [
                    'cluster' => $this->config['cluster'], 
                    'useTLS' => true,
                    'encrypted' => true,
                    'timeout' => 10
                ]
            );
            
            $this->is_initialized = true;
            
        } catch (\Exception $e) {
            error_log('WP Live Chat: Failed to initialize Pusher: ' . $e->getMessage());
            $this->is_initialized = false;
        }
    }

    /**
     * بررسی اینکه آیا Pusher به درستی راه‌اندازی شده است
     */
    public function is_initialized(): bool {
        return $this->is_initialized;
    }

    /**
     * ارسال یک رویداد به یک کانال خاص
     */
    public function trigger_event(string $channel_name, string $event_name, array $data): bool {
        if (!$this->is_initialized) {
            return false;
        }

        try {
            $this->pusher->trigger($channel_name, $event_name, $data);
            return true;
        } catch (\Exception $e) {
            error_log('WP Live Chat Pusher Trigger Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * احراز هویت برای کانال‌های خصوصی و حضور
     */
    public function authenticate(string $socket_id, string $channel_name): string {
        if (!$this->is_initialized) {
            return json_encode(['error' => 'Pusher not initialized']);
        }

        try {
            // برای کانال‌های خصوصی
            if (str_starts_with($channel_name, 'private-')) {
                $auth = $this->pusher->socket_auth($channel_name, $socket_id);
                return $auth;
            }
            
            // برای کانال‌های حضور (فقط ادمین‌ها)
            if (str_starts_with($channel_name, 'presence-')) {
                if (!current_user_can('manage_options')) {
                    throw new \Exception('Access Denied');
                }
                
                $user = wp_get_current_user();
                $user_data = [
                    'user_id' => (string) $user->ID,
                    'user_info' => [
                        'name' => $user->display_name ?: 'Admin',
                        'email' => $user->user_email,
                    ]
                ];
                
                $auth = $this->pusher->presence_auth($channel_name, $socket_id, $user_data['user_id'], $user_data);
                return $auth;
            }
            
            throw new \Exception('Invalid channel type');
            
        } catch (\Exception $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * بررسی وضعیت آنلاین بودن ادمین‌ها (روش ساده)
     */
    /**
     * بررسی وضعیت آنلاین بودن ادمین‌ها و اپراتورها
     */
    public function is_admin_online(): bool {
        global $wpdb;
        
        // روش 1: بررسی از طریق حضور در Pusher (اگر ممکن باشد)
        // برای سادگی، از روش وردپرس استفاده می‌کنیم
        
        // روش 2: بررسی کاربران با نقش‌های مشخص که در 5 دقیقه گذشته فعال بوده‌اند
        $online_threshold = 5 * 60; // 5 دقیقه در ثانیه
        
        // کاربران با این نقش‌ها می‌توانند به چت پاسخ دهند
        $allowed_roles = ['administrator', 'chat_operator']; // نقش اپراتور چت
        
        // پیدا کردن کاربران با این نقش‌ها که آنلاین هستند
        $online_users = [];
        
        foreach ($allowed_roles as $role) {
            // گرفتن کاربران با این نقش
            $users = get_users([
                'role' => $role,
                'fields' => 'ID',
            ]);
            
            foreach ($users as $user_id) {
                // بررسی آخرین فعالیت کاربر
                $last_activity = get_user_meta($user_id, 'last_activity', true);
                
                if ($last_activity) {
                    $time_diff = current_time('timestamp') - (int)$last_activity;
                    
                    // اگر کاربر در 5 دقیقه گذشته فعال بوده، آنلاین در نظر بگیر
                    if ($time_diff <= $online_threshold) {
                        $online_users[] = $user_id;
                    }
                } else {
                    // اگر اطلاعات فعالیت نداریم، بررسی کنیم آیا کاربر در حال حاضر لاگین است
                    if (is_user_logged_in() && get_current_user_id() == $user_id) {
                        $online_users[] = $user_id;
                    }
                }
            }
        }
        
        return !empty($online_users);
    }

    /**
    * بررسی آنلاین بودن یک کاربر خاص
    */
    public function is_user_online(int $user_id): bool {
        $online_threshold = 5 * 60; // 5 دقیقه
        
        $last_activity = get_user_meta($user_id, 'last_activity', true);
        
        if ($last_activity) {
            $time_diff = current_time('timestamp') - (int)$last_activity;
            return $time_diff <= $online_threshold;
        }
        
        // اگر اطلاعات فعالیت نداریم، بررسی کنیم آیا کاربر در حال حاضر لاگین است
        return is_user_logged_in() && get_current_user_id() == $user_id;
    }

    /**
    * دریافت لیست کاربران آنلاین (مدیران و اپراتورها)
    */
    public function get_online_operators(): array {
        $online_operators = [];
        $allowed_roles = ['administrator', 'chat_operator'];
        $online_threshold = 5 * 60;
        
        foreach ($allowed_roles as $role) {
            $users = get_users([
                'role' => $role,
                'fields' => ['ID', 'display_name', 'user_email'],
            ]);
            
            foreach ($users as $user) {
                $last_activity = get_user_meta($user->ID, 'last_activity', true);
                $is_online = false;
                
                if ($last_activity) {
                    $time_diff = current_time('timestamp') - (int)$last_activity;
                    $is_online = $time_diff <= $online_threshold;
                } else {
                    $is_online = is_user_logged_in() && get_current_user_id() == $user->ID;
                }
                
                if ($is_online) {
                    $online_operators[] = [
                        'id' => $user->ID,
                        'name' => $user->display_name,
                        'email' => $user->user_email,
                        'role' => $role,
                        'role_label' => $role === 'administrator' ? 'مدیر کل' : 'اپراتور چت'
                    ];
                }
            }
        }
        
        return $online_operators;
    }
    
    /**
     * ارسال پیام به کاربر خاص
     */
    public function send_to_user(string $session_id, string $event_name, array $data): bool {
        return $this->trigger_event('private-session-' . $session_id, $event_name, $data);
    }
    
    /**
     * ارسال پیام به تمام ادمین‌ها
     */
    public function send_to_admins(string $event_name, array $data): bool {
        return $this->trigger_event('presence-chat-admins', $event_name, $data);
    }

    /**
     * احراز هویت کانال (برای AJAX endpoint)
     */
    public function authenticate_channel(string $channel_name, string $socket_id): string {
        return $this->authenticate($socket_id, $channel_name);
    }

    /**
     * ایجاد کانال خصوصی برای ادمین‌ها برای هر Session
     */
    public function create_admin_session_channel(string $session_id): string {
        return 'private-admin-session-' . $session_id;
    }

    /**
     * ارسال پیام به کانال ادمین‌های یک Session خاص
     */
    public function send_to_session_admins(string $session_id, string $event_name, array $data): bool {
        $channel_name = $this->create_admin_session_channel($session_id);
        return $this->trigger_event($channel_name, $event_name, $data);
    }
}