<?php
namespace WP_Live_Chat;

/**
 * مدیریت فایل‌های چت
 */
class File_Manager {
    
    private $upload_dir;
    private $max_file_size; // 10MB
    private $allowed_mime_types;
    private DB_Manager $db_manager;
    
    public function __construct(DB_Manager $db_manager) {
        $this->db_manager = $db_manager;
        $this->max_file_size = 10 * 1024 * 1024; // 10MB
        
        // انواع فایل‌های مجاز
        $this->allowed_mime_types = [
            // تصاویر
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            
            // اسناد
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            
            // متن
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            
            // آرشیو
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-7z-compressed' => '7z',
        ];
        
        // ایجاد دایرکتوری آپلود
        $this->setup_upload_dir();
    }
    
    /**
     * راه‌اندازی دایرکتوری آپلود
     */
    private function setup_upload_dir(): void {
        $upload = wp_upload_dir();
        $this->upload_dir = $upload['basedir'] . '/wp-live-chat-files/';
        
        // ایجاد دایرکتوری اگر وجود ندارد
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
        
        // ایجاد فایل htaccess برای امنیت
        $htaccess = $this->upload_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            $content = "Order deny,allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif|pdf|doc|docx|xls|xlsx|ppt|pptx|txt)$\">\nAllow from all\n</Files>";
            @file_put_contents($htaccess, $content);
        }
        
        // ایجاد فایل index.php خالی
        $index = $this->upload_dir . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, '<?php // Silence is golden');
        }
    }
    
    /**
     * آپلود فایل
     */
    public function upload_file(array $file, string $session_id, string $sender_type, ?int $sender_id = null): array {
        // بررسی خطاهای آپلود
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('خطا در آپلود فایل: ' . $this->get_upload_error($file['error']));
        }
        
        // بررسی حجم فایل
        if ($file['size'] > $this->max_file_size) {
            throw new \Exception('حجم فایل نمی‌تواند بیشتر از ۱۰ مگابایت باشد.');
        }
        
        // بررسی نوع فایل
        $mime_type = mime_content_type($file['tmp_name']);
        if (!isset($this->allowed_mime_types[$mime_type])) {
            throw new \Exception('نوع فایل مجاز نیست. فایل‌های مجاز: ' . implode(', ', array_values($this->allowed_mime_types)));
        }
        
        // بررسی امنیت فایل
        if (!$this->check_file_security($file['tmp_name'], $mime_type)) {
            throw new \Exception('فایل ناامن تشخیص داده شد.');
        }
        
        // ایجاد نام امن برای فایل
        $file_ext = $this->allowed_mime_types[$mime_type];
        $safe_name = sanitize_file_name($file['name']);
        $unique_name = uniqid('chat_', true) . '_' . $safe_name;
        $unique_name = preg_replace('/[^a-zA-Z0-9._-]/', '', $unique_name);
        
        // مسیر کامل فایل
        $file_path = $this->upload_dir . $unique_name;
        
        // انتقال فایل
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            throw new \Exception('خطا در ذخیره فایل.');
        }
        
        // URL فایل
        $upload = wp_upload_dir();
        $file_url = $upload['baseurl'] . '/wp-live-chat-files/' . $unique_name;
        
        // ذخیره در دیتابیس
        $file_data = [
            'session_id' => $session_id,
            'file_name' => $safe_name,
            'file_path' => $file_path,
            'file_url' => $file_url, // این باید URL کامل باشد
            'file_type' => $file_ext,
            'file_size' => $file['size'],
            'mime_type' => $mime_type,
            'sender_type' => $sender_type,
            'sender_id' => $sender_id,
        ];
        
        $file_id = $this->db_manager->save_file($file_data);
        
        if (!$file_id) {
            // پاک کردن فایل اگر ذخیره در دیتابیس ناموفق بود
            @unlink($file_path);
            throw new \Exception('خطا در ذخیره اطلاعات فایل.');
        }
        
        return [
            'success' => true,
            'file_id' => $file_id,
            'file_name' => $safe_name,
            'file_url' => $file_url, // این باید برگردانده شود
            'file_type' => $file_ext,
            'file_size' => $file['size'],
            'formatted_size' => $this->format_file_size($file['size']),
            'mime_type' => $mime_type,
        ];
    }
    
    /**
     * بررسی امنیت فایل
     */
    private function check_file_security(string $file_path, string $mime_type): bool {
        // بررسی تصاویر
        if (strpos($mime_type, 'image/') === 0) {
            $image_info = @getimagesize($file_path);
            if (!$image_info) {
                return false;
            }
            
            // بررسی تزریق PHP در تصاویر
            $file_content = file_get_contents($file_path);
            if (preg_match('/<\?php|eval\(|base64_decode/', $file_content)) {
                return false;
            }
        }
        
        // بررسی فایل‌های اجرایی
        $executable_extensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar'];
        $file_ext = pathinfo($file_path, PATHINFO_EXTENSION);
        if (in_array(strtolower($file_ext), $executable_extensions)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * دریافت خطاهای آپلود
     */
    private function get_upload_error(int $error_code): string {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'حجم فایل بیشتر از حد مجاز است.',
            UPLOAD_ERR_FORM_SIZE => 'حجم فایل بیشتر از حد مجاز فرم است.',
            UPLOAD_ERR_PARTIAL => 'فایل فقط بخشی از آن آپلود شده است.',
            UPLOAD_ERR_NO_FILE => 'هیچ فایلی انتخاب نشده است.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت وجود ندارد.',
            UPLOAD_ERR_CANT_WRITE => 'خطا در نوشتن فایل روی دیسک.',
            UPLOAD_ERR_EXTENSION => 'آپلود فایل توسط افزونه متوقف شد.',
        ];
        
        return $errors[$error_code] ?? 'خطای ناشناخته در آپلود فایل.';
    }
    
    /**
     * فرمت کردن حجم فایل
     */
    public function format_file_size(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * حذف فایل
     */
    public function delete_file(int $file_id): bool {
        $file = $this->db_manager->get_file_by_id($file_id);
        
        if (!$file) {
            return false;
        }
        
        // حذف فایل از دیسک
        if (file_exists($file['file_path'])) {
            @unlink($file['file_path']);
        }
        
        // حذف از دیتابیس
        global $wpdb;
        $table = $wpdb->prefix . 'wplc_files';
        return $wpdb->delete($table, ['id' => $file_id]) !== false;
    }
    
    /**
     * دریافت اطلاعات دایرکتوری آپلود
     */
    public function get_upload_info(): array {
        $upload = wp_upload_dir();
        $chat_dir = $upload['basedir'] . '/wp-live-chat-files/';
        
        $total_size = 0;
        $file_count = 0;
        
        if (file_exists($chat_dir)) {
            $files = scandir($chat_dir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $file_path = $chat_dir . $file;
                    if (is_file($file_path)) {
                        $total_size += filesize($file_path);
                        $file_count++;
                    }
                }
            }
        }
        
        return [
            'total_size' => $this->format_file_size($total_size),
            'file_count' => $file_count,
            'max_size' => $this->format_file_size($this->max_file_size),
        ];
    }
    
    /**
     * دریافت آیکون بر اساس نوع فایل
     */
    public function get_file_icon(string $file_type): string {
        $icons = [
            // تصاویر
            'jpg' => '📷',
            'jpeg' => '📷',
            'png' => '🖼️',
            'gif' => '🎞️',
            'webp' => '🖼️',
            
            // اسناد
            'pdf' => '📄',
            'doc' => '📝',
            'docx' => '📝',
            'xls' => '📊',
            'xlsx' => '📊',
            'ppt' => '📽️',
            'pptx' => '📽️',
            
            // متن
            'txt' => '📃',
            'csv' => '📋',
            
            // آرشیو
            'zip' => '📦',
            'rar' => '📦',
            '7z' => '📦',
            
            // پیش‌فرض
            'default' => '📎'
        ];
        
        return $icons[strtolower($file_type)] ?? $icons['default'];
    }
}