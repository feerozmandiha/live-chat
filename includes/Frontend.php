<?php
namespace WP_Live_Chat;
use WP_Live_Chat;

/**
 * مدیریت بخش فرانت‌اند
 */
class Frontend {
    
    private WP_Live_Chat $container;

    public function __construct(WP_Live_Chat $container) {
        $this->container = $container;
    }

    public function hooks(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_chat_ui']);
        
        $chat_frontend = new Chat_Frontend($this->container);
        $chat_frontend->hooks();
        
        // اضافه کردن endpoint جدید برای بررسی وضعیت آنلاین
        add_action('wp_ajax_wplc_check_admin_online', [$this, 'check_admin_online']);
        add_action('wp_ajax_nopriv_wplc_check_admin_online', [$this, 'check_admin_online']);    }

    public function enqueue_assets(): void {
        // استایل‌ها
        wp_enqueue_style(
            'wplc-frontend-style', 
            WP_LIVE_CHAT_PLUGIN_URL . 'assets/css/frontend.css', 
            [], 
            WP_LIVE_CHAT_VERSION
        );
        
        // Pusher JS
        wp_enqueue_script(
            'pusher-js',
            'https://js.pusher.com/8.4.0/pusher.min.js', 
            [],
            '8.4.0', 
            true
        );

        // اسکریپت اصلی
        wp_enqueue_script(
            'wplc-frontend-script',
            WP_LIVE_CHAT_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery', 'pusher-js'],
            WP_LIVE_CHAT_VERSION,
            true
        );
        
        // داده‌های JS
        $session_id = $this->get_or_create_session_id();
        $settings = $this->container->get_service('settings');
        
        wp_localize_script('wplc-frontend-script', 'wplc_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'pusher_key' => $settings ? ($settings->get('pusher_key') ?: '') : '',
            'pusher_cluster' => $settings ? ($settings->get('pusher_cluster') ?: 'eu') : 'eu',
            'session_id' => $session_id,
            'rtl' => is_rtl(),
            'ajaxNonce' => wp_create_nonce('wplc_ajax_nonce'), 
            'authNonce' => wp_create_nonce('wplc_pusher_auth'),
            'check_admin_nonce' => wp_create_nonce('wplc_check_admin'),
        ]);
    }
    
    private function get_or_create_session_id(): string {
        $cookie_name = 'wplc_session_id';
        
        if (isset($_COOKIE[$cookie_name])) {
            $session_id = sanitize_text_field($_COOKIE[$cookie_name]);
            if (strlen($session_id) > 10) {
                return $session_id;
            }
        }
        
        $session_id = 'wplc_' . uniqid();
        setcookie($cookie_name, $session_id, time() + (86400 * 30), 
                 COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        
        return $session_id;
    }

    public function render_chat_ui(): void {
        $settings = $this->container->get_service('settings');
        
        // حالت اولیه: فعال (حتی اگر آفلاین باشد)
        $is_admin_online = true; // همیشه فعال باشه تا کاربر بتونه پیام بده
        
        // اطلاعات تماس
        $whatsapp_number = $settings ? ($settings->get('whatsapp_number') ?: '') : '';
        $phone_number = $settings ? ($settings->get('phone_number') ?: '') : '';
        $chat_title = $settings ? ($settings->get('chat_title') ?: 'پشتیبانی آنلاین') : 'پشتیبانی آنلاین';
        $offline_message = $settings ? ($settings->get('offline_message') ?: 'پیام شما ذخیره می‌شود و در اولین فرصت پاسخ داده می‌شود.') : 'پیام شما ذخیره می‌شود و در اولین فرصت پاسخ داده می‌شود.';
        ?>
        
        <!-- WP Live Chat -->
        <div id="wplc-chat-icon" class="wplc-icon is-online">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28">
                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z" fill="currentColor"/>
            </svg>
        </div>

        <div id="wplc-chat-box" class="wplc-chat-box">
            <div class="wplc-header">
                <span class="wplc-header-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z" fill="currentColor"/>
                    </svg>
                    <?php echo esc_html($chat_title); ?>
                </span>
                <span class="wplc-admin-status is-online" id="wplc-admin-status-indicator">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="8" height="8">
                        <circle cx="12" cy="12" r="10" fill="currentColor"/>
                    </svg>
                    <span id="wplc-status-text">آنلاین</span>
                </span>
                <button class="wplc-close-btn" aria-label="بستن پنجره چت">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22">
                        <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/>
                    </svg>
                </button>
            </div>

            <div class="wplc-messages-container" id="wplc-messages-container">
                <!-- پیام‌ها از طریق JavaScript لود می‌شوند -->
            </div>

            <div class="wplc-input-area">
                <textarea 
                    id="wplc-message-input" 
                    placeholder="پیام خود را بنویسید..." 
                    rows="1"
                ></textarea>
                <button id="wplc-send-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/>
                    </svg>
                    ارسال
                </button>
            </div>

            <?php if ($whatsapp_number || $phone_number): ?>
            <div class="wplc-contact-links">
                <span>راه‌های دیگر ارتباط:</span>
                <?php if ($whatsapp_number): ?>
                <a href="https://wa.me/<?php echo esc_attr($whatsapp_number); ?>" target="_blank" rel="noopener noreferrer">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20">
                        <path d="M16.75 13.96c.25.13.41.2.46.3.06.11.04.61-.21 1.18-.2.56-1.24 1.1-1.7 1.12-.46.02-.47.36-2.96-.73-2.49-1.09-3.99-3.75-4.11-3.92-.12-.17-.96-1.38-.92-2.61.05-1.22.69-1.8.95-2.04.24-.26.51-.29.68-.26h.47c.15 0 .36-.06.55.45l.69 1.87c.06.13.1.28.01.44l-.27.41-.39.42c-.12.12-.26.25-.12.5.12.26.62 1.09 1.32 1.78.91.88 1.71 1.17 1.95 1.3.24.14.39.12.54-.04l.81-.94c.19-.25.35-.19.58-.11l1.67.88M12 2a10 10 0 0 1 10 10 10 10 0 0 1-10 10c-1.97 0-3.8-.57-5.35-1.55L2 22l1.55-4.65A9.969 9.969 0 0 1 2 12 10 10 0 0 1 12 2m0 2a8 8 0 0 0-8 8c0 1.72.54 3.31 1.46 4.61L4.5 19.5l2.89-.96A7.95 7.95 0 0 0 12 20a8 8 0 0 0 8-8 8 8 0 0 0-8-8z" fill="currentColor"/>
                    </svg>
                    واتس‌آپ
                </a>
                <?php endif; ?>
                <?php if ($phone_number): ?>
                <a href="tel:<?php echo esc_attr($phone_number); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20">
                        <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z" fill="currentColor"/>
                    </svg>
                    تماس
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
        // کد اولیه برای فعال کردن چت
        document.addEventListener('DOMContentLoaded', function() {
            // فعال کردن input و دکمه ارسال
            const messageInput = document.getElementById('wplc-message-input');
            const sendBtn = document.getElementById('wplc-send-btn');
            
            if (messageInput) {
                messageInput.disabled = false;
                messageInput.setAttribute('placeholder', 'پیام خود را بنویسید...');
            }
            
            if (sendBtn) {
                sendBtn.disabled = false;
            }
            
            // اضافه کردن پیام خوش‌آمد
            const messagesContainer = document.getElementById('wplc-messages-container');
            if (messagesContainer && messagesContainer.children.length === 0) {
                const welcomeMsg = `<?php echo esc_js($offline_message); ?>`;
                if (welcomeMsg) {
                    const messageHTML = `
                        <div class="wplc-message-row wplc-system">
                            <div class="wplc-message-bubble">
                                <p class="wplc-content">${welcomeMsg}</p>
                            </div>
                        </div>
                    `;
                    messagesContainer.innerHTML = messageHTML;
                }
            }
        });
        </script>
        <?php
    }
    
}