<?php
namespace WP_Live_Chat;

/**
 * مدیریت تعاملات دیتابیس
 */
class DB_Manager {
    
    private $table_prefix;

    public function __construct() {
        global $wpdb;
        $this->table_prefix = $wpdb->prefix . 'wplc_';
    }

    /**
     * ایجاد جداول لازم
     */
    public function create_tables(): void {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // 1. جدول Sessions
        $sessions_table = $this->table_prefix . 'sessions';
        $sql_sessions = "CREATE TABLE {$sessions_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(50) NOT NULL,
            user_name varchar(255) DEFAULT NULL,
            phone_number varchar(20) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'new',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_id_unique (session_id),
            KEY status_index (status),
            KEY updated_at_index (updated_at)
        ) {$wpdb->get_charset_collate()};";
        
        $result1 = dbDelta($sql_sessions);
        error_log('WP Live Chat: Sessions table result: ' . json_encode($result1));

        // 2. جدول Messages
        $messages_table = $this->table_prefix . 'messages';
        $sql_messages = "CREATE TABLE {$messages_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(50) NOT NULL,
            sender_type varchar(10) NOT NULL,
            sender_id bigint(20) UNSIGNED DEFAULT NULL,
            message_content text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY session_id_index (session_id),
            KEY sender_type_index (sender_type),
            KEY created_at_index (created_at)
        ) {$wpdb->get_charset_collate()};";
        
        $result2 = dbDelta($sql_messages);
        error_log('WP Live Chat: Messages table result: ' . json_encode($result2));
        
        // بررسی نهایی
        $this->verify_tables();
        $this->create_files_table(); // اضافه کردن این خط

    }

        /**
     * ایجاد جدول فایل‌ها
     */
    public function create_files_table(): void {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $files_table = $this->table_prefix . 'files';
        $sql = "CREATE TABLE {$files_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(50) NOT NULL,
            file_name varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            file_url varchar(500) NOT NULL,
            file_type varchar(100) NOT NULL,
            file_size bigint(20) UNSIGNED NOT NULL,
            mime_type varchar(100) NOT NULL,
            sender_type varchar(10) NOT NULL,
            sender_id bigint(20) UNSIGNED DEFAULT NULL,
            message_id bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY session_id_index (session_id),
            KEY sender_type_index (sender_type),
            KEY message_id_index (message_id),
            KEY created_at_index (created_at)
        ) {$wpdb->get_charset_collate()};";
        
        dbDelta($sql);
        error_log('WP Live Chat: Files table created');
    }

    /**
     * ذخیره اطلاعات فایل
     */
    public function save_file(array $file_data): ?int {
        global $wpdb;
        $table = $this->table_prefix . 'files';
        
        if (!$this->table_exists('files')) {
            $this->create_files_table();
        }
        
        $result = $wpdb->insert($table, [
            'session_id' => $file_data['session_id'],
            'file_name' => $file_data['file_name'],
            'file_path' => $file_data['file_path'],
            'file_url' => $file_data['file_url'],
            'file_type' => $file_data['file_type'],
            'file_size' => $file_data['file_size'],
            'mime_type' => $file_data['mime_type'],
            'sender_type' => $file_data['sender_type'],
            'sender_id' => $file_data['sender_id'] ?? null,
            'message_id' => $file_data['message_id'] ?? null,
            'created_at' => current_time('mysql', 1),
        ]);
        
        return $result ? $wpdb->insert_id : null;
    }

    /**
     * دریافت فایل‌های یک session
     */
    public function get_session_files(string $session_id, int $limit = 50): array {
        global $wpdb;
        $table = $this->table_prefix . 'files';
        
        if (!$this->table_exists('files')) {
            return [];
        }
        
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %s ORDER BY created_at DESC LIMIT %d",
            $session_id,
            $limit
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        return is_array($results) ? $results : [];
    }

        /**
     * دریافت اطلاعات فایل بر اساس ID
     */
    public function get_file_by_id(int $file_id): ?array {
        global $wpdb;
        $table = $this->table_prefix . 'files';
        
        if (!$this->table_exists('files')) {
            return null;
        }
        
        $query = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $file_id);
        return $wpdb->get_row($query, ARRAY_A);
    }
        
    /**
     * تأیید ایجاد جداول
     */
    private function verify_tables(): void {
        global $wpdb;
        
        $sessions_table = $this->table_prefix . 'sessions';
        $messages_table = $this->table_prefix . 'messages';
        
        $sessions_exists = $wpdb->get_var("SHOW TABLES LIKE '{$sessions_table}'") === $sessions_table;
        $messages_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") === $messages_table;
        
        if (!$sessions_exists) {
            error_log('WP Live Chat: CRITICAL - Sessions table does not exist!');
            // تلاش برای ایجاد مستقیم
            $this->create_table_directly($sessions_table, 'sessions');
        }
        
        if (!$messages_exists) {
            error_log('WP Live Chat: CRITICAL - Messages table does not exist!');
            // تلاش برای ایجاد مستقیم
            $this->create_table_directly($messages_table, 'messages');
        }
        
        error_log('WP Live Chat: Tables verified - Sessions: ' . ($sessions_exists ? 'Yes' : 'No') . 
                  ', Messages: ' . ($messages_exists ? 'Yes' : 'No'));
    }
    
    /**
     * ایجاد مستقیم جدول (برای مواقع اضطراری)
     */
    private function create_table_directly(string $table_name, string $type): void {
        global $wpdb;
        
        if ($type === 'sessions') {
            $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id varchar(50) NOT NULL,
                user_name varchar(255) DEFAULT NULL,
                phone_number varchar(20) DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'new',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY session_id_unique (session_id),
                KEY status_index (status),
                KEY updated_at_index (updated_at)
            ) {$wpdb->get_charset_collate()}";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id varchar(50) NOT NULL,
                sender_type varchar(10) NOT NULL,
                sender_id bigint(20) UNSIGNED DEFAULT NULL,
                message_content text NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY session_id_index (session_id),
                KEY sender_type_index (sender_type),
                KEY created_at_index (created_at)
            ) {$wpdb->get_charset_collate()}";
        }
        
        $result = $wpdb->query($sql);
        error_log('WP Live Chat: Direct table creation for ' . $type . ': ' . ($result ? 'Success' : 'Failed'));
    }
    
    // ---------------------------------------------------
    // متدهای مربوط به Session
    // ---------------------------------------------------

    /**
     * ایجاد یک جلسه جدید
     */
    public function create_session(string $session_id): bool {
        global $wpdb;
        $table = $this->table_prefix . 'sessions';
        
        // بررسی وجود جدول
        if (!$this->table_exists('sessions')) {
            error_log('WP Live Chat: Sessions table not found when creating session');
            return false;
        }
        
        return $wpdb->insert($table, [
            'session_id' => $session_id,
            'created_at' => current_time('mysql', 1),
            'updated_at' => current_time('mysql', 1),
        ], ['%s', '%s', '%s']) !== false;
    }

    /**
     * بررسی وجود جدول
     */
    public function table_exists(string $table_type): bool {
        global $wpdb;
        $table_name = $this->table_prefix . $table_type;
        return $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    }

    /**
     * به‌روزرسانی اطلاعات کاربر
     */
    public function update_session_user_info(string $session_id, string $name, string $phone): bool {
        global $wpdb;
        $table = $this->table_prefix . 'sessions';
        
        if (!$this->table_exists('sessions')) {
            return false;
        }
        
        return $wpdb->update($table, [
            'user_name' => sanitize_text_field($name),
            'phone_number' => sanitize_text_field($phone),
            'status' => 'open',
            'updated_at' => current_time('mysql', 1),
        ], [
            'session_id' => $session_id
        ], ['%s', '%s', '%s', '%s'], ['%s']) !== false;
    }

    // ---------------------------------------------------
    // متدهای مربوط به Messages
    // ---------------------------------------------------

    /**
     * ذخیره یک پیام جدید
     */
    public function save_message(string $session_id, string $sender_type, string $content, ?int $sender_id = null): bool {
        global $wpdb;
        $table = $this->table_prefix . 'messages';
        
        if (!$this->table_exists('messages')) {
            error_log('WP Live Chat: Messages table not found when saving message');
            return false;
        }
        
        return $wpdb->insert($table, [
            'session_id' => $session_id,
            'sender_type' => sanitize_key($sender_type),
            'sender_id' => $sender_id,
            'message_content' => sanitize_textarea_field($content),
            'created_at' => current_time('mysql', 1),
        ], ['%s', '%s', '%d', '%s', '%s']) !== false;
    }

    /**
     * دریافت تاریخچه پیام‌های یک Session (کاملاً بهینه‌سازی شده)
     */
    public function get_session_history(string $session_id, string $last_message_id = null): array {
        global $wpdb;
        $messages_table = $this->table_prefix . 'messages';
        
        if (!$this->table_exists('messages')) {
            return [];
        }
        
        // استفاده از prepared statement با پارامترهای پویا
        $query = "SELECT 
                    id,
                    session_id,
                    sender_type,
                    sender_id,
                    message_content as content,
                    created_at
                FROM {$messages_table} 
                WHERE session_id = %s";
        
        $params = [$session_id];
        
        
        // اگر last_message_id داریم، فقط پیام‌های جدیدتر را بگیر
        if ($last_message_id) {
            // تلاش برای پیدا کردن بر اساس id (اگر عددی است)
            if (is_numeric($last_message_id)) {
                $query .= " AND id > %d";
                $params[] = (int)$last_message_id;
            } else {
                // یا بر اساس timestamp
                $query .= " AND created_at > %s";
                $params[] = $last_message_id;
            }
        }
        
        $query .= " ORDER BY created_at ASC LIMIT 100";
        
        $prepared_query = $wpdb->prepare($query, $params);
        $results = $wpdb->get_results($prepared_query, ARRAY_A);
        
        return is_array($results) ? $results : [];
    }

    /**
     * دریافت لیست Sessions بر اساس وضعیت و محدودیت
     * این متد جایگزین get_active_sessions_list می‌شود.
     * * @param string|array $status وضعیت‌های مورد نظر (مثلاً 'pending', 'open', 'closed').
     * @param int $limit تعداد حداکثر نتایج.
     * @return array لیست جلسات یافت شده.
     */
    public function get_sessions_list($status = ['new', 'open'], int $limit = 50): array {
        global $wpdb;
        $sessions_table = $this->table_prefix . 'sessions';
        
        if (!$this->table_exists('sessions')) {
            return [];
        }

        // 1. آماده‌سازی وضعیت‌ها
        if (is_string($status)) {
            $status = [$status]; // اگر وضعیت یک رشته بود، آن را به آرایه تبدیل کن
        }
        
        // فیلتر کردن و ایمن‌سازی وضعیت‌ها برای استفاده در SQL (IN clause)
        $clean_statuses = array_map('sanitize_key', $status);
        
        // ساخت لیست جایگزین‌های %s
        $placeholders = implode(', ', array_fill(0, count($clean_statuses), '%s'));

        // 2. ساخت کوئری
        // توجه: $limit به صورت مستقیم استفاده می‌شود چون قبلاً به int تبدیل شده است.
        $query = $wpdb->prepare(
            "SELECT id, session_id, user_name, status, updated_at 
            FROM {$sessions_table} 
            WHERE status IN ({$placeholders}) 
            ORDER BY updated_at DESC
            LIMIT %d", 
            // ترکیب وضعیت‌های ایمن شده با پارامتر محدودیت
            array_merge($clean_statuses, [$limit]) 
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // اضافه کردن آخرین پیام برای نمایش پیش‌نمایش در لیست
        if (!empty($results)) {
            foreach ($results as &$session) {
                $session['last_message_preview'] = $this->get_last_message_preview($session['session_id']);
            }
            unset($session); // مهم: شکستن رفرنس
        }
        
        return is_array($results) ? $results : [];
    }

    /**
     * شمارش تعداد پیام‌های یک Session
     */
    public function count_session_messages(string $session_id): int {
        global $wpdb;
        $table = $this->table_prefix . 'messages';
        
        if (!$this->table_exists('messages')) {
            return 0;
        }
        
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE session_id = %s",
            $session_id
        );
        
        return (int) $wpdb->get_var($query);
    }

    /**
     * دریافت آخرین پیام هر Session (برای لیست ادمین)
     */
    /**
     * دریافت Sessions با آخرین پیام
     */
    public function get_sessions_with_last_message($status = ['new', 'open'], int $limit = 50): array {
        global $wpdb;
        $sessions_table = $this->table_prefix . 'sessions';
        $messages_table = $this->table_prefix . 'messages';
        
        if (!$this->table_exists('sessions')) {
            return [];
        }

        // آماده‌سازی وضعیت‌ها
        if (is_string($status)) {
            $status = [$status];
        }
        
        $clean_statuses = array_map('sanitize_key', $status);
        
        if (empty($clean_statuses)) {
            $clean_statuses = ['new', 'open'];
        }
        
        $placeholders = implode(', ', array_fill(0, count($clean_statuses), '%s'));

        // کوئری بهینه‌سازی شده
        $query = $wpdb->prepare(
            "SELECT 
                s.id,
                s.session_id,
                s.user_name,
                s.phone_number,
                s.status,
                s.created_at,
                s.updated_at,
                m.message_content as last_message_preview,
                m.created_at as last_message_time
            FROM {$sessions_table} s
            LEFT JOIN (
                SELECT 
                    session_id,
                    message_content,
                    created_at,
                    ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at DESC) as rn
                FROM {$messages_table}
            ) m ON s.session_id = m.session_id AND m.rn = 1
            WHERE s.status IN ({$placeholders})
            ORDER BY s.updated_at DESC
            LIMIT %d",
            array_merge($clean_statuses, [$limit])
        );
        
        error_log('WP Live Chat DEBUG: SQL Query: ' . $query);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if ($wpdb->last_error) {
            error_log('WP Live Chat DEBUG: Database error: ' . $wpdb->last_error);
        }
        
        return is_array($results) ? $results : [];
    }

    // ---------------------------------------------------
    // متدهای اضافی
    // ---------------------------------------------------

    /**
     * دریافت لیست Sessions فعال
     */
    public function get_active_sessions_list(): array {
        global $wpdb;
        $sessions_table = $this->table_prefix . 'sessions';
        
        if (!$this->table_exists('sessions')) {
            return [];
        }
        
        $query = "SELECT id, session_id, user_name, status, updated_at 
                  FROM {$sessions_table} 
                  WHERE status IN ('new', 'open') 
                  ORDER BY updated_at DESC";
        
        $results = $wpdb->get_results($query, ARRAY_A);
        return is_array($results) ? $results : [];
    }
    
    /**
     * دریافت جزئیات یک Session
     */
    public function get_session_details(string $session_id): ?array {
        global $wpdb;
        $sessions_table = $this->table_prefix . 'sessions';
        
        if (!$this->table_exists('sessions')) {
            return null;
        }
        
        $query = $wpdb->prepare(
            "SELECT session_id, user_name, phone_number, status, created_at
             FROM {$sessions_table} 
             WHERE session_id = %s", 
             $session_id
        );
        
        return $wpdb->get_row($query, ARRAY_A);
    }

    /**
     * به‌روزرسانی وضعیت Session
     */
    public function update_session_status(string $session_id, string $status): bool {
        global $wpdb;
        $table = $this->table_prefix . 'sessions';
        
        if (!$this->table_exists('sessions')) {
            return false;
        }
        
        return $wpdb->update($table, [
            'status' => sanitize_key($status),
            'updated_at' => current_time('mysql', 1),
        ], [
            'session_id' => $session_id
        ], ['%s', '%s'], ['%s']) !== false;
    }

    /**
     * بررسی وجود Session
     */
    public function session_exists(string $session_id): bool {
        global $wpdb;
        $table = $this->table_prefix . 'sessions';
        
        if (!$this->table_exists('sessions')) {
            return false;
        }
        
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE session_id = %s",
            $session_id
        );
        
        return (int) $wpdb->get_var($query) > 0;
    }
    
    /**
     * دریافت آخرین پیام برای پیش‌نمایش
     */
    public function get_last_message_preview(string $session_id): string {
        global $wpdb;
        $table = $this->table_prefix . 'messages';
        
        if (!$this->table_exists('messages')) {
            return 'گفتگوی جدید';
        }
        
        $query = $wpdb->prepare(
            "SELECT message_content 
             FROM {$table} 
             WHERE session_id = %s 
             ORDER BY created_at DESC 
             LIMIT 1",
            $session_id
        );
        
        $message = $wpdb->get_var($query);
        
        if (!$message) {
            return 'گفتگوی جدید';
        }
        
        $message = strip_tags($message);
        if (mb_strlen($message) > 50) {
            $message = mb_substr($message, 0, 47) . '...';
        }
        
        return $message;
    }
}