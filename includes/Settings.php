<?php
namespace WP_Live_Chat;

/**
 * مدیریت تنظیمات افزونه از طریق Option API وردپرس
 */
class Settings {
    
    const OPTIONS_KEY = 'wp_live_chat_settings';
    
    private array $settings;

    public function __construct() {
        $this->settings = get_option(self::OPTIONS_KEY, $this->get_default_settings());
    }

    /**
     * دریافت تنظیمات پیش‌فرض
     */
    private function get_default_settings(): array {
        return [
            'pusher_app_id' => '',
            'pusher_key' => '',
            'pusher_secret' => '',
            'pusher_cluster' => 'eu',
            
            'chat_title' => 'پشتیبانی آنلاین',
            'welcome_message' => '👋 سلام! چطور می‌تونم کمکتون کنم؟',
            'offline_message' => '😴 در حال حاضر آنلاین نیستیم. پیام خود را بگذارید تا با شما تماس بگیریم.',
            'input_placeholder' => 'پیام خود را بنویسید...',
            
            'phone_number' => '',
            'whatsapp_number' => '',
            
            'phone_placeholder' => '۰۹۱۲۰۰۰۰۰۰۰',
            'name_placeholder' => 'نام کامل یا نام شرکت',
        ];
    }

    /**
     * دریافت یک مقدار تنظیمات
     * @param string $key
     * @return mixed|null
     */
    public function get(string $key) {
        return $this->settings[$key] ?? null;
    }

    /**
     * بررسی وجود تنظیمات ضروری
     */
    public function has_required_settings(): bool {
        $required = ['pusher_app_id', 'pusher_key', 'pusher_secret', 'pusher_cluster'];
        
        foreach ($required as $key) {
            if (empty($this->get($key))) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * ذخیره‌سازی تنظیمات
     * @param array $new_settings
     * @return bool
     */
    public function update(array $new_settings): bool {
        // اعتبارسنجی تنظیمات Pusher
        if (isset($new_settings['pusher_key'])) {
            $new_settings['pusher_key'] = sanitize_text_field($new_settings['pusher_key']);
        }
        
        if (isset($new_settings['pusher_secret'])) {
            $new_settings['pusher_secret'] = sanitize_text_field($new_settings['pusher_secret']);
        }
        
        if (isset($new_settings['pusher_app_id'])) {
            $new_settings['pusher_app_id'] = sanitize_text_field($new_settings['pusher_app_id']);
        }
        
        if (isset($new_settings['pusher_cluster'])) {
            $new_settings['pusher_cluster'] = sanitize_text_field($new_settings['pusher_cluster']);
        }
        
        // اعتبارسنجی شماره تلفن
        if (isset($new_settings['phone_number'])) {
            $new_settings['phone_number'] = preg_replace('/\D/', '', $new_settings['phone_number']);
        }
        
        if (isset($new_settings['whatsapp_number'])) {
            $new_settings['whatsapp_number'] = preg_replace('/\D/', '', $new_settings['whatsapp_number']);
        }
        
        // ادغام با تنظیمات موجود
        $merged_settings = array_merge($this->settings, $new_settings);
        $this->settings = $merged_settings;
        
        return update_option(self::OPTIONS_KEY, $this->settings);
    }
    
    /**
     * دریافت تمام تنظیمات
     */
    public function get_all(): array {
        return $this->settings;
    }
    
    /**
     * تنظیم یک مقدار خاص
     */
    public function set(string $key, $value): void {
        $this->settings[$key] = $value;
    }
}