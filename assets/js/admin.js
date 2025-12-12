/**
 * WP Live Chat - Admin Console JavaScript (Refactored)
 * Version: 1.0.1
 */

(function ($) {
    'use strict';

    // Check if admin data is loaded
    if (typeof wplc_admin_data === 'undefined') {
        console.error('WP Live Chat Admin: Data not loaded');
        return;
    }

    // ------------------------------------------------------------
    // Utility Functions
    // ------------------------------------------------------------
    class AdminUtils {
        static async postData(action, data) {
            try {
                const formData = new FormData();
                formData.append('action', action);
                formData.append('security', wplc_admin_data.nonce);
                
                for (const key in data) {
                    if (data[key] !== undefined && data[key] !== null) {
                        formData.append(key, data[key]);
                    }
                }

                const response = await fetch(wplc_admin_data.ajax_url, {
                    method: 'POST',
                    body: formData,
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const json = await response.json();
                
                if (!json.success) {
                    throw new Error(json.data?.message || 'Operation failed');
                }

                return json.data;
            } catch (error) {
                console.error('Admin AJAX Error:', error);
                throw error;
            }
        }

        static formatTimeAgo(dateString) {
            if (!dateString) return 'همین الآن';
            
            const now = new Date();
            const date = new Date(dateString);
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            
            if (diffMins < 1) return 'همین الآن';
            if (diffMins < 60) return `${diffMins} دقیقه پیش`;
            if (diffMins < 1440) return `${Math.floor(diffMins / 60)} ساعت پیش`;
            return `${Math.floor(diffMins / 1440)} روز پیش`;
        }

        static escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // ------------------------------------------------------------
    // AdminUI Class
    // ------------------------------------------------------------
    class AdminUI {
        constructor() {
            this.initializeElements();
            this.activeSessionId = null;
            this.sessionElements = {};
        }

        initializeElements() {
            this.elements = {
                container: document.querySelector('.chat-admin-container'),
                sessionListContent: document.getElementById('session-list-content'),
                sessionCount: document.querySelector('.session-list-count'),
                chatWindowArea: document.getElementById('chat-window-area'),
                refreshBtn: document.getElementById('refresh-sessions')
            };
            
            console.log('AdminUI elements:', this.elements);
        }

        bindEvents() {
            // Refresh button
            if (this.elements.refreshBtn) {
                this.elements.refreshBtn.addEventListener('click', () => {
                    if (window.WPLCAdminApp) {
                        window.WPLCAdminApp.loadSessions();
                    }
                });
            }

            // Filter buttons
            const filterBtns = document.querySelectorAll('.filter-btn');
            filterBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    filterBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    
                    if (window.WPLCAdminApp) {
                        window.WPLCAdminApp.loadSessions(btn.dataset.status);
                    }
                });
            });
        }

        showLoading() {
            if (this.elements.sessionListContent) {
                this.elements.sessionListContent.innerHTML = `
                    <div class="empty-sessions">
                        <div class="loading-spinner"></div>
                        <p>در حال بارگذاری گفتگوها...</p>
                    </div>
                `;
            }
        }

        showEmptyMessage() {
            if (this.elements.sessionListContent) {
                this.elements.sessionListContent.innerHTML = `
                    <div class="empty-sessions">
                        <div class="empty-sessions-icon">💬</div>
                        <p>هیچ گفتگوی فعالی وجود ندارد</p>
                    </div>
                `;
            }
        }

        showErrorMessage(message) {
            if (this.elements.sessionListContent) {
                this.elements.sessionListContent.innerHTML = `
                    <div class="error-message">
                        <p>${AdminUtils.escapeHtml(message)}</p>
                        <button class="button button-small" id="retry-loading">تلاش مجدد</button>
                    </div>
                `;
                
                const retryBtn = document.getElementById('retry-loading');
                if (retryBtn) {
                    retryBtn.addEventListener('click', () => {
                        if (window.WPLCAdminApp) {
                            window.WPLCAdminApp.loadSessions();
                        }
                    });
                }
            }
        }

        renderSessionList(sessions) {
            if (!this.elements.sessionListContent) return;
            
            this.sessionElements = {};
            this.elements.sessionListContent.innerHTML = '';
            
            if (!sessions || sessions.length === 0) {
                this.showEmptyMessage();
                return;
            }
            
            sessions.forEach(session => {
                const item = this.createSessionItem(session);
                this.elements.sessionListContent.appendChild(item);
                this.sessionElements[session.session_id] = item;
            });
            
            // Update count
            if (this.elements.sessionCount) {
                this.elements.sessionCount.textContent = sessions.length;
            }
        }

        createSessionItem(session) {
            const div = document.createElement('div');
            div.className = `session-list-item ${session.status} ${this.activeSessionId === session.session_id ? 'active' : ''}`;
            div.dataset.sessionId = session.session_id;
            
            const timeAgo = AdminUtils.formatTimeAgo(session.updated_at || session.created_at);
            const preview = session.last_message_preview || session.message_preview || 'گفتگوی جدید';
            
            div.innerHTML = `
                <div class="session-header">
                    <span class="user-name">${AdminUtils.escapeHtml(session.user_name || 'کاربر جدید')}</span>
                    <span class="session-time">${timeAgo}</span>
                </div>
                <div class="message-preview">${AdminUtils.escapeHtml(preview)}</div>
                <div class="session-meta">
                    <span class="status-badge ${session.status}">${session.status === 'open' ? 'باز' : 'جدید'}</span>
                </div>
            `;
            
            div.addEventListener('click', () => {
                if (window.WPLCAdminApp) {
                    window.WPLCAdminApp.openSession(session.session_id);
                }
            });
            
            return div;
        }

        renderChatWindow(session, history) {
            if (!this.elements.chatWindowArea) return;
            
            const userName = AdminUtils.escapeHtml(session.user_name || 'کاربر جدید');
            const phone = AdminUtils.escapeHtml(session.phone_number || 'نامشخص');
            const status = session.status === 'open' ? 'باز' : 'جدید';
            
            this.elements.chatWindowArea.innerHTML = `
                <div class="chat-header">
                    <div class="chat-header-info">
                        <h3>گفتگو با ${userName}</h3>
                        <p class="chat-meta">
                            شماره تماس: ${phone} | 
                            وضعیت: <span class="session-status ${session.status}">${status}</span>
                        </p>
                    </div>
                    <div class="chat-actions">
                        <button id="refresh-chat" class="button button-small" title="بروزرسانی گفتگو">
                            🔄
                        </button>
                        <button id="close-session" class="button button-small button-danger" title="بستن گفتگو">
                            ❌
                        </button>
                    </div>
                </div>
                <div class="chat-messages" id="admin-chat-messages">
                    ${this.renderHistory(history)}
                </div>
                <div class="chat-input-area">
                    <div class="input-group">
                        <textarea id="admin-message-input" placeholder="پیام خود را بنویسید..."></textarea>
                        <button class="btn-send" id="admin-send-btn">ارسال</button>
                    </div>
                </div>
            `;
            
            // Bind chat window events
            this.bindChatWindowEvents(session.session_id);
            
            // Scroll to bottom
            this.scrollToBottom();
        }

        renderHistory(history) {
            if (!history || history.length === 0) {
                return '<div class="no-messages"><p>هیچ پیامی وجود ندارد</p></div>';
            }
            
            let html = '';
            history.forEach(msg => {
                const isAdmin = msg.sender_type === 'admin';
                const sender = isAdmin ? (AdminUtils.escapeHtml(wplc_admin_data.user_name || 'ادمین')) : (AdminUtils.escapeHtml(msg.user_name || 'کاربر'));
                const time = new Date(msg.created_at).toLocaleTimeString('fa-IR', { 
                    hour: '2-digit', 
                    minute: '2-digit' 
                });
                
                html += `
                    <div class="message ${isAdmin ? 'admin' : 'user'}">
                        <div class="message-header">
                            <span class="sender">${sender}</span>
                            <span class="time">${time}</span>
                        </div>
                        <div class="message-content">${AdminUtils.escapeHtml(msg.content || msg.message_content)}</div>
                    </div>
                `;
            });
            
            return html;
        }

        bindChatWindowEvents(sessionId) {
            const sendBtn = document.getElementById('admin-send-btn');
            const messageInput = document.getElementById('admin-message-input');
            const refreshBtn = document.getElementById('refresh-chat');
            const closeBtn = document.getElementById('close-session');
            
            // Send message
            if (sendBtn && messageInput) {
                const sendHandler = async () => {
                    const message = messageInput.value.trim();
                    if (!message) return;
                    
                    try {
                        if (window.WPLCAdminApp) {
                            await window.WPLCAdminApp.sendMessage(sessionId, message);
                            messageInput.value = '';
                            messageInput.focus();
                        }
                    } catch (error) {
                        console.error('Error sending message:', error);
                        alert('خطا در ارسال پیام: ' + error.message);
                    }
                };
                
                sendBtn.addEventListener('click', sendHandler);
                
                messageInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendHandler();
                    }
                });
            }
            
            // Refresh chat
            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => {
                    if (window.WPLCAdminApp) {
                        window.WPLCAdminApp.loadChatHistory(sessionId);
                    }
                });
            }
            
            // Close session
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    if (confirm('آیا از بستن این گفتگو مطمئن هستید؟')) {
                        if (window.WPLCAdminApp) {
                            window.WPLCAdminApp.closeSession(sessionId);
                        }
                    }
                });
            }
        }

        appendMessage(messageData) {
            const messagesContainer = document.getElementById('admin-chat-messages');
            if (!messagesContainer) return;
            
            const isAdmin = messageData.sender_type === 'admin';
            const sender = isAdmin ? (AdminUtils.escapeHtml(wplc_admin_data.user_name || 'ادمین')) : (AdminUtils.escapeHtml(messageData.user_name || 'کاربر'));
            const time = messageData.created_at ? 
                new Date(messageData.created_at).toLocaleTimeString('fa-IR', { 
                    hour: '2-digit', 
                    minute: '2-digit' 
                }) : 
                new Date().toLocaleTimeString('fa-IR', { 
                    hour: '2-digit', 
                    minute: '2-digit' 
                });
            
            const html = `
                <div class="message ${isAdmin ? 'admin' : 'user'}">
                    <div class="message-header">
                        <span class="sender">${sender}</span>
                        <span class="time">${time}</span>
                    </div>
                    <div class="message-content">${AdminUtils.escapeHtml(messageData.content)}</div>
                </div>
            `;
            
            messagesContainer.insertAdjacentHTML('beforeend', html);
            this.scrollToBottom();
        }

        scrollToBottom() {
            const messagesContainer = document.getElementById('admin-chat-messages');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }

        clearChatWindow() {
            if (this.elements.chatWindowArea) {
                this.elements.chatWindowArea.innerHTML = '<div class="no-session-selected">یک گفتگو را از لیست انتخاب کنید.</div>';
            }
        }

        setActiveSession(sessionId) {
            // Remove active class from all sessions
            Object.values(this.sessionElements).forEach(element => {
                element.classList.remove('active');
            });
            
            // Add active class to selected session
            if (this.sessionElements[sessionId]) {
                this.sessionElements[sessionId].classList.add('active');
            }
            
            this.activeSessionId = sessionId;
        }
    }

    // ------------------------------------------------------------
    // AdminApp Class
    // ------------------------------------------------------------
    class AdminApp {
        constructor() {
            this.ui = new AdminUI();
            this.sessions = {};
            this.pusher = null;
            this.receivedMessageKeys = new Set();
            
            this.init();
        }

        init() {
            console.log('AdminApp initializing...');
            
            this.ui.bindEvents();
            this.loadSessions();
            
            if (wplc_admin_data.pusher_enabled && wplc_admin_data.pusher_key) {
                this.initPusher();
            }
            
            window.WPLCAdminApp = this;
            console.log('AdminApp initialized successfully');
        }

        initPusher() {
            if (typeof Pusher === 'undefined') {
                console.warn('Pusher library not loaded');
                return;
            }

            try {
                this.pusher = new Pusher(wplc_admin_data.pusher_key, {
                    cluster: wplc_admin_data.pusher_cluster,
                    authEndpoint: wplc_admin_data.ajax_url,
                    auth: {
                        params: { 
                            action: 'wplc_pusher_auth',
                            security: wplc_admin_data.authNonce || wplc_admin_data.nonce
                        }
                    },
                    forceTLS: true,
                    enabledTransports: ['ws', 'wss']
                });

                this.pusher.connection.bind('state_change', (states) => {
                    console.log('Pusher connection state:', states.current);
                });

                this.pusher.connection.bind('error', (err) => {
                    console.error('Pusher connection error:', err);
                });

                // کانال عمومی برای دریافت پیام‌های جدید از کاربران
                this.adminChannel = this.pusher.subscribe('private-admin-new-sessions');
                
                this.adminChannel.bind('new-user-message', (data) => {
                    console.log('New user message received:', data);
                    this.handleNewUserMessage(data);
                });
                
                console.log('Pusher connected successfully for admin');
                
            } catch (error) {
                console.error('Pusher initialization error:', error);
            }
        }

        updateOnlineCount(count) {
            const element = document.querySelector('.admin-online-count');
            if (element) {
                element.textContent = count;
            }
        }

        async loadSessions(status = 'new,open') {
            try {
                this.ui.showLoading();
                
                const data = await AdminUtils.postData('wplc_admin_get_sessions', {
                    status: status,
                    limit: 50
                });
                
                this.sessions = {};
                data.sessions.forEach(session => {
                    this.sessions[session.session_id] = session;
                });
                
                this.ui.renderSessionList(data.sessions);
                
            } catch (error) {
                console.error('Error loading sessions:', error);
                this.ui.showErrorMessage('خطا در بارگذاری گفتگوها: ' + error.message);
            }
        }

        async openSession(sessionId) {
            if (this.ui.activeSessionId === sessionId) return;
            
            try {
                this.ui.setActiveSession(sessionId);
                
                // Get session details and history
                const [sessionData, historyData] = await Promise.all([
                    AdminUtils.postData('wplc_admin_get_session_details', {
                        session_id: sessionId
                    }),
                    AdminUtils.postData('wplc_admin_get_chat_history', {
                        session_id: sessionId
                    })
                ]);
                
                this.ui.renderChatWindow(sessionData.session, historyData.history || []);
                
                // همچنین به کانال خصوصی session هم subscribe شویم
                if (this.pusher) {
                    this.sessionChannel = this.pusher.subscribe(`private-session-${sessionId}`);
                    
                    this.sessionChannel.bind('new-message', (data) => {
                        // فقط پیام‌های غیرادمین را نمایش بده
                        if (data.sender_type !== 'admin') {
                            this.ui.appendMessage(data);
                        }
                    });
                }
                
            } catch (error) {
                console.error('Error opening session:', error);
                alert('خطا در باز کردن گفتگو: ' + error.message);
            }
        }

        async sendMessage(sessionId, message) {
            if (!sessionId || !message.trim()) return;
            
            // Show temporary message
            const tempId = 'admin_temp_' + Date.now();
            this.ui.appendMessage({
                sender_type: 'admin',
                content: message,
                created_at: new Date().toISOString(),
                temp_id: tempId
            });
            
            try {
                const data = await AdminUtils.postData('wplc_admin_send_message', {
                    session_id: sessionId,
                    message: message
                });
                
                // بررسی کنید که data شامل message_id باشد
                console.log('Admin message sent response:', data);
                
                // Mark as sent
                this.markAdminMessageAsSent(tempId, data.message_id);
                
                return data;
                
            } catch (error) {
                console.error('Error sending message:', error);
                this.markAdminMessageAsFailed(tempId, message);
                throw error;
            }
        }

        markAdminMessageAsSent(tempId, messageId) {
            const messages = document.querySelectorAll('#admin-chat-messages .message');
            messages.forEach(msg => {
                if (msg.dataset.tempId === tempId) {
                    msg.classList.remove('temp-message');
                    msg.dataset.messageId = messageId;
                }
            });
        }

        markAdminMessageAsFailed(tempId, message) {
            const messages = document.querySelectorAll('#admin-chat-messages .message');
            messages.forEach(msg => {
                if (msg.dataset.tempId === tempId) {
                    msg.classList.add('failed-message');
                    const content = msg.querySelector('.message-content');
                    if (content) {
                        content.innerHTML = `❌ <span style="opacity: 0.7;">${AdminUtils.escapeHtml(message)}</span>`;
                    }
                }
            });
        }

        async closeSession(sessionId) {
            try {
                await AdminUtils.postData('wplc_admin_close_session', {
                    session_id: sessionId
                });
                
                // Refresh sessions list
                this.loadSessions();
                
                // Clear chat window
                this.ui.clearChatWindow();
                this.ui.activeSessionId = null;
                
                alert('گفتگو با موفقیت بسته شد.');
                
            } catch (error) {
                console.error('Error closing session:', error);
                throw error;
            }
        }

        async loadChatHistory(sessionId) {
            try {
                const data = await AdminUtils.postData('wplc_admin_get_chat_history', {
                    session_id: sessionId
                });
                
                // Re-render chat window with updated history
                if (this.sessions[sessionId]) {
                    this.ui.renderChatWindow(this.sessions[sessionId], data.history || []);
                }
                
            } catch (error) {
                console.error('Error loading chat history:', error);
            }
        }

        handleNewUserMessage(data) {
            console.log('New user message received:', data);

            // ایجاد کلید یکتا برای پیام
            const messageKey = `${data.session_id}_${data.sender_type}_${data.created_at}_${data.content}`;
            
            // بررسی تکراری بودن
            if (this.receivedMessageKeys.has(messageKey)) {
                console.log('Duplicate message ignored:', messageKey);
                return;
            }
            
            this.receivedMessageKeys.add(messageKey);
            
            // فقط 100 کلید آخر را نگه دار
            if (this.receivedMessageKeys.size > 100) {
                const keys = Array.from(this.receivedMessageKeys);
                this.receivedMessageKeys = new Set(keys.slice(-100));
            }
            
            // Update session in list
            if (this.sessions[data.session_id]) {
                this.sessions[data.session_id].last_message_preview = 
                    (data.content || '').substring(0, 50) + '...';
                this.sessions[data.session_id].updated_at = data.created_at;
                
                // Update UI if session is in list
                if (this.ui.sessionElements[data.session_id]) {
                    const item = this.ui.sessionElements[data.session_id];
                    const preview = item.querySelector('.message-preview');
                    const time = item.querySelector('.session-time');
                    
                    if (preview) {
                        preview.textContent = (data.content || '').substring(0, 50) + '...';
                    }
                    if (time) {
                        time.textContent = AdminUtils.formatTimeAgo(data.created_at);
                    }
                }
            } else {
                // If it's a new session, reload the list
                console.log('New session detected, reloading sessions list');
                this.loadSessions();
            }
            
            // If this session is currently open, show the message
            if (this.ui.activeSessionId === data.session_id) {
                console.log('Appending message to chat window');
                this.ui.appendMessage(data);
            } else {
                console.log('Session not active, not appending to chat window');
            }
        }
    }

    // ------------------------------------------------------------
    // Initialize
    // ------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function() {
        console.log('WP Live Chat Admin: DOM ready, initializing...');
        
        if (document.querySelector('.chat-admin-container')) {
            try {
                new AdminApp();
                console.log('WP Live Chat Admin: Initialized successfully');
            } catch (error) {
                console.error('WP Live Chat Admin: Initialization error:', error);
            }
        }
    });

})(jQuery);