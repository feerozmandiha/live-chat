<?php
namespace WP_Live_Chat;

/**
 * مدیریت جریان گفتگو برای جمع‌آوری اطلاعات اولیه کاربر
 */
class ConversationFlowManager {
    
    // تعریف مراحل جریان گفتگو
    const STEP_INITIAL = 'initial';         // شروع گفتگو، پیام اول کاربر
    const STEP_ASK_PHONE = 'ask_phone';     // درخواست شماره تماس
    const STEP_ASK_NAME = 'ask_name';       // درخواست نام کاربر/شرکت
    const STEP_COMPLETED = 'completed';     // اطلاعات کامل شد، چت واقعی آغاز می‌شود
    
    /**
     * وضعیت فعلی کاربر را از Session مرورگر دریافت می‌کند
     */
    public static function get_current_step(string $session_id): string {
        // استفاده از Session سمت سرور
        if (session_status() === PHP_SESSION_ACTIVE) {
            $session_key = 'wplc_flow_' . $session_id;
            if (isset($_SESSION[$session_key])) {
                return $_SESSION[$session_key];
            }
        }
        
        // استفاده از کوکی به عنوان fallback
        $cookie_key = 'wplc_flow_' . md5($session_id);
        if (isset($_COOKIE[$cookie_key])) {
            return sanitize_text_field($_COOKIE[$cookie_key]);
        }
        
        return self::STEP_INITIAL;
    }

    /**
     * وضعیت فعلی کاربر را به‌روزرسانی می‌کند
     */
    public static function set_current_step(string $session_id, string $step): void {
        // ذخیره در Session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['wplc_flow_' . $session_id] = $step;
        }
        
        // ذخیره در کوکی به مدت 30 روز
        $cookie_key = 'wplc_flow_' . md5($session_id);
        setcookie($cookie_key, $step, time() + (86400 * 30), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }
    
    /**
     * ذخیره داده‌های موقت کاربر
     */
    public static function set_temp_data(string $session_id, array $data): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['wplc_user_data_' . $session_id] = $data;
        }
        
        $cookie_key = 'wplc_user_data_' . md5($session_id);
        setcookie($cookie_key, json_encode($data), time() + (86400 * 30), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }
    
    /**
     * دریافت داده‌های موقت کاربر
     */
    public static function get_temp_data(string $session_id): array {
        // اولویت با Session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $session_key = 'wplc_user_data_' . $session_id;
            if (isset($_SESSION[$session_key])) {
                return $_SESSION[$session_key];
            }
        }
        
        // fallback به کوکی
        $cookie_key = 'wplc_user_data_' . md5($session_id);
        if (isset($_COOKIE[$cookie_key])) {
            $data = json_decode(wp_unslash($_COOKIE[$cookie_key]), true);
            if (is_array($data)) {
                return $data;
            }
        }
        
        return ['phone' => '', 'name' => ''];
    }
    
    /**
     * پاک کردن داده‌های موقت
     */
    public static function clear_temp_data(string $session_id): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['wplc_user_data_' . $session_id]);
        }
        
        $cookie_key = 'wplc_user_data_' . md5($session_id);
        setcookie($cookie_key, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }
    
    /**
     * اعتبارسنجی شماره تلفن ایرانی
     */
    private function validate_iranian_phone(string $phone): bool {
        // حذف فاصله و کاراکترهای غیرعددی
        $phone = preg_replace('/\D/', '', $phone);
        
        // بررسی طول
        if (strlen($phone) < 10 || strlen($phone) > 11) {
            return false;
        }
        
        // بررسی شروع با 09 یا 9
        if (!preg_match('/^(09|9)/', $phone)) {
            return false;
        }
        
        // تبدیل به فرمت 09xxxxxxxxx
        if (strlen($phone) === 10 && strpos($phone, '9') === 0) {
            $phone = '0' . $phone;
        }
        
        return preg_match('/^09[0-9]{9}$/', $phone);
    }
    
    /**
     * پردازش پیام کاربر و تعیین گام بعدی
     * @param string $session_id
     * @param string $message
     * @param DB_Manager $db_manager
     * @return array شامل {step: گام بعدی, response_message: پیام سیستمی به کاربر}
     */
    public function process_user_message(string $session_id, string $message, DB_Manager $db_manager): array {
        $current_step = self::get_current_step($session_id);
        
        // داده‌های موقت کاربر
        $temp_data = self::get_temp_data($session_id);
        
        $response = [
            'step' => $current_step,
            'response_message' => ''
        ];

        switch ($current_step) {
            case self::STEP_INITIAL:
                // اولین پیام کاربر. جلسه را ایجاد کن و درخواست شماره تماس بده
                if (!$db_manager->session_exists($session_id)) {
                    $db_manager->create_session($session_id);
                }
                
                $response['step'] = self::STEP_ASK_PHONE;
                $response['response_message'] = '👋 سلام! برای شروع گفتگو، لطفاً شماره تماس خود را وارد نمایید:';
                break;
                
            case self::STEP_ASK_PHONE:
                // کاربر شماره تماس را وارد کرده است
                $phone = trim($message);
                
                if (!$this->validate_iranian_phone($phone)) {
                    $response['response_message'] = '❌ شماره تماس وارد شده معتبر نیست. لطفاً یک شماره موبایل معتبر وارد کنید (مثال: 09123456789):';
                    break;
                }
                
                // فرمت کردن شماره تلفن
                $phone = preg_replace('/\D/', '', $phone);
                if (strlen($phone) === 10 && strpos($phone, '9') === 0) {
                    $phone = '0' . $phone;
                }
                
                $temp_data['phone'] = $phone;
                self::set_temp_data($session_id, $temp_data);
                
                $response['step'] = self::STEP_ASK_NAME;
                $response['response_message'] = '✅ شماره شما ثبت شد. اکنون لطفاً نام کامل یا نام شرکت خود را وارد نمایید:';
                break;
                
            case self::STEP_ASK_NAME:
                // کاربر نام را وارد کرده است
                $name = trim($message);
                
                if (strlen($name) < 2) {
                    $response['response_message'] = '❌ نام وارد شده کوتاه است. لطفاً نام کامل خود را وارد نمایید:';
                    break;
                }
                
                // حذف کاراکترهای خطرناک
                $name = sanitize_text_field($name);
                
                $temp_data['name'] = $name;
                
                // اطلاعات کامل است، در دیتابیس ثبت کن
                $db_manager->update_session_user_info(
                    $session_id, 
                    $temp_data['name'], 
                    $temp_data['phone']
                );
                
                $response['step'] = self::STEP_COMPLETED;
                $response['response_message'] = '🎉 اطلاعات شما با موفقیت ثبت شد. همکاران ما به زودی با شما تماس خواهند گرفت.';
                
                // پاک کردن داده‌های موقت
                self::clear_temp_data($session_id);
                break;
                
            default:
                // در حالت تکمیل، پیام کاربر صرفاً یک پیام عادی است
                $response['step'] = self::STEP_COMPLETED;
                $response['response_message'] = ''; // هیچ پیام سیستمی صادر نمی‌شود
                break;
        }

        self::set_current_step($session_id, $response['step']);
        
        return $response;
    }
    
    /**
     * بازنشانی جریان گفتگو برای یک Session
     */
    public static function reset_flow(string $session_id): void {
        self::set_current_step($session_id, self::STEP_INITIAL);
        self::clear_temp_data($session_id);
    }
}