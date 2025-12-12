/**
 * WP Live Chat - Frontend JavaScript (Debug Version)
 * Version: 1.0.2
 */

(function ($, wplc_data) {
    'use strict';

    console.log('WP Live Chat: Initializing frontend...');
    console.log('Session ID:', wplc_data.session_id);
    console.log('Pusher Key:', wplc_data.pusher_key ? 'Set' : 'Not set');

    // ------------------------------------------------------------
    // Utility Functions
    // ------------------------------------------------------------
    class Utils {
        static async postData(action, data) {
            try {
                console.log(`AJAX Request: ${action}`, data);
                
                const formData = new FormData();
                formData.append('action', action);
                formData.append('security', wplc_data.ajaxNonce);
                
                for (const key in data) {
                    if (data[key] !== undefined && data[key] !== null) {
                        formData.append(key, data[key]);
                    }
                }

                const response = await fetch(wplc_data.ajax_url, {
                    method: 'POST',
                    body: formData,
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const json = await response.json();
                console.log(`AJAX Response: ${action}`, json);
                
                if (!json.success) {
                    throw new Error(json.data?.message || 'Operation failed');
                }

                return json.data;
            } catch (error) {
                console.error('AJAX Error:', error);
                throw error;
            }
        }

        static formatTime(timestamp) {
            if (!timestamp) return new Date().toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
            return new Date(timestamp).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
        }

        static escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        static isImageFile(fileType) {
            if (!fileType) return false;
            const imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            return imageTypes.includes(fileType.toLowerCase());
        }

        static getFileIcon(fileType) {
            const icons = {
                'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🎞️', 'webp': '🖼️',
                'pdf': '📄', 'doc': '📝', 'docx': '📝', 'xls': '📊', 'xlsx': '📊',
                'txt': '📃', 'zip': '📦', 'rar': '📦'
            };
            return icons[fileType?.toLowerCase()] || '📎';
        }

        static formatFileSize(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        static truncateFileName(fileName, maxLength = 30) {
            if (!fileName || typeof fileName !== 'string') {
                return 'file';
            }
            
            if (fileName.length <= maxLength) return fileName;
            
            const lastDotIndex = fileName.lastIndexOf('.');
            if (lastDotIndex === -1) {
                return fileName.substring(0, maxLength - 3) + '...';
            }
            
            const extension = fileName.substring(lastDotIndex + 1);
            const nameWithoutExt = fileName.substring(0, lastDotIndex);
            
            if (nameWithoutExt.length <= maxLength - extension.length - 3) {
                return fileName;
            }
            
            const truncatedName = nameWithoutExt.substring(0, maxLength - extension.length - 3);
            return truncatedName + '...' + extension;
        }
    }

    // ------------------------------------------------------------
    // ChatUI Class
    // ------------------------------------------------------------
    class ChatUI {
        constructor() {
            this.initializeElements();
            this.setupEventListeners();
            this.createFileInput();
            console.log('ChatUI initialized with elements:', this.elements);
        }

        initializeElements() {
            this.elements = {
                chatIcon: document.getElementById('wplc-chat-icon'),
                chatBox: document.getElementById('wplc-chat-box'),
                messagesContainer: document.getElementById('wplc-messages-container'),
                messageInput: document.getElementById('wplc-message-input'),
                sendBtn: document.getElementById('wplc-send-btn'),
                closeBtn: document.querySelector('.wplc-close-btn'),
                adminStatus: document.getElementById('wplc-admin-status-indicator'),
                statusText: document.getElementById('wplc-status-text')
            };
        }

        setupEventListeners() {
            // Setup auto-resize for textarea
            if (this.elements.messageInput) {
                this.elements.messageInput.addEventListener('input', this.autoResizeTextarea.bind(this));
            }

            // Force enable inputs
            setTimeout(() => {
                if (this.elements.messageInput) {
                    this.elements.messageInput.disabled = false;
                    this.elements.messageInput.style.height = '52px';
                    this.elements.messageInput.placeholder = 'پیام خود را بنویسید...';
                }
                
                if (this.elements.sendBtn) {
                    this.elements.sendBtn.disabled = false;
                }
            }, 100);
        }

        autoResizeTextarea() {
            const textarea = this.elements.messageInput;
            if (!textarea) return;
            
            textarea.style.height = 'auto';
            const newHeight = Math.min(textarea.scrollHeight, 120);
            textarea.style.height = newHeight + 'px';
            
            if (textarea.scrollHeight > 120) {
                textarea.style.overflowY = 'auto';
            } else {
                textarea.style.overflowY = 'hidden';
            }
        }

        createFileInput() {
            this.fileInput = document.createElement('input');
            this.fileInput.type = 'file';
            this.fileInput.id = 'wplc-hidden-file-input';
            this.fileInput.className = 'wplc-hidden-file-input';
            this.fileInput.accept = 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar';
            this.fileInput.style.display = 'none';
            
            document.body.appendChild(this.fileInput);
            
            this.createUploadButton();
        }

        createUploadButton() {
            if (!this.elements.messageInput || !this.elements.messageInput.parentNode) return;
            
            const container = this.elements.messageInput.parentNode;
            container.style.position = 'relative';
            
            // ایجاد wrapper برای دکمه و input
            const uploadWrapper = document.createElement('div');
            uploadWrapper.className = 'wplc-upload-wrapper';
            uploadWrapper.style.cssText = `
                position: absolute;
                left: 10px;
                top: 50%;
                transform: translateY(-50%);
                z-index: 10;
                width: 30px;
                height: 30px;
            `;
            
            const uploadBtn = document.createElement('button');
            uploadBtn.type = 'button';
            uploadBtn.className = 'wplc-file-upload-btn';
            uploadBtn.innerHTML = '📎';
            uploadBtn.title = 'ارسال فایل';
            uploadBtn.style.cssText = `
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: transparent;
                border: none;
                font-size: 20px;
                cursor: pointer;
                padding: 5px;
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1;
            `;
            
            // تنظیم مجدد file input
            this.fileInput.style.cssText = `
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                opacity: 0;
                cursor: pointer;
                z-index: 2;
            `;
            
            uploadBtn.addEventListener('click', (e) => {
                console.log('File upload button clicked');
                e.stopPropagation();
                // اینجا fileInput را کلیک می‌کنیم
                this.fileInput.click();
            });
            
            this.fileInput.addEventListener('change', (e) => {
                console.log('File selected:', e.target.files[0]);
                console.log('WPLCChatApp exists:', !!window.WPLCChatApp);
                
                if (e.target.files[0] && window.WPLCChatApp) {
                    window.WPLCChatApp.handleFileUpload(e.target.files[0]);
                } else {
                    console.error('WPLCChatApp not available or no file selected');
                }
                e.target.value = ''; // ریست کردن input
            });
            
            // اضافه کردن عناصر به wrapper
            uploadWrapper.appendChild(uploadBtn);
            uploadWrapper.appendChild(this.fileInput);
            container.appendChild(uploadWrapper);
            
            this.elements.messageInput.style.paddingLeft = '45px';
        }

        // همچنین bindEvents را اصلاح کنید
        bindEvents(onOpen, onClose, onMessageSend) {
            console.log('Binding UI events...');
            
            if (this.elements.chatIcon) {
                console.log('Binding click event to chat icon');
                this.elements.chatIcon.addEventListener('click', (e) => {
                    console.log('Chat icon clicked!');
                    e.stopPropagation();
                    onOpen();
                });
            } else {
                console.error('Chat icon element not found!');
            }

            if (this.elements.closeBtn) {
                this.elements.closeBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    onClose();
                });
            }

            if (this.elements.sendBtn) {
                this.elements.sendBtn.addEventListener('click', () => {
                    const message = this.elements.messageInput.value.trim();
                    if (message) {
                        onMessageSend(message);
                    }
                });
            }

            if (this.elements.messageInput) {
                this.elements.messageInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        const message = this.elements.messageInput.value.trim();
                        if (message) {
                            onMessageSend(message);
                        }
                    }
                });
            }

            // رویداد کلیک برای بستن چت - بهینه‌سازی شده
            document.addEventListener('click', (e) => {
                const chatBox = this.elements.chatBox;
                const chatIcon = this.elements.chatIcon;
                
                if (!chatBox || chatBox.style.display !== 'flex') return;
                
                // بررسی اینکه آیا کلیک روی عناصر مربوط به چت است
                const isClickInsideChat = chatBox.contains(e.target);
                const isClickOnChatIcon = chatIcon && chatIcon.contains(e.target);
                const isClickOnUpload = e.target.closest('.wplc-upload-wrapper') || 
                                    e.target.classList.contains('wplc-file-upload-btn') ||
                                    e.target.id === 'wplc-hidden-file-input';
                
                // اگر کلیک خارج از چت باشد و روی آپلود یا آیکون چت نباشد، چت را ببند
                if (!isClickInsideChat && !isClickOnChatIcon && !isClickOnUpload) {
                    console.log('Closing chat - click outside');
                    onClose();
                }
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.elements.chatBox && this.elements.chatBox.style.display === 'flex') {
                    onClose();
                }
            });
        }


        toggleChatBox(show) {
            if (!this.elements.chatBox) {
                console.error('Chat box element not found!');
                return;
            }
            
            console.log('Toggling chat box:', show);
            
            if (show) {
                document.body.classList.add('wplc-chat-open');
                
                this.elements.chatBox.style.display = 'flex';
                this.elements.chatBox.style.visibility = 'visible';
                this.elements.chatBox.style.opacity = '1';
                
                setTimeout(() => {
                    if (this.elements.messageInput) {
                        this.elements.messageInput.focus();
                    }
                    this.scrollToBottom();
                }, 50);
            } else {
                document.body.classList.remove('wplc-chat-open');
                
                this.elements.chatBox.style.opacity = '0';
                setTimeout(() => {
                    this.elements.chatBox.style.display = 'none';
                }, 300);
            }
        }

        clearInput() {
            if (this.elements.messageInput) {
                this.elements.messageInput.value = '';
                this.elements.messageInput.style.height = '52px';
                this.elements.messageInput.style.overflowY = 'hidden';
            }
        }

        appendMessage(content, senderType, timestamp = null, userName = null, options = {}) {
            if (!this.elements.messagesContainer) {
                console.error('Messages container not found!');
                return;
            }
            
            const typeClass = senderType === 'user' ? 'user' : 
                            senderType === 'system' ? 'system' : 'admin';
            
            const senderLabel = senderType === 'user' ? 'شما' : 
                            senderType === 'system' ? 'سیستم' : 
                            userName || 'پشتیبان';
            
            const timeString = timestamp ? 
                new Date(timestamp).toLocaleTimeString('fa-IR', {
                    hour: '2-digit',
                    minute: '2-digit'
                }) : 
                new Date().toLocaleTimeString('fa-IR', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            
            let messageContent = '';
            
            if (options.is_file && options.file_data) {
                const fileData = options.file_data;
                if (Utils.isImageFile(fileData.file_type)) {
                    messageContent = this.createImageMessage(fileData);
                } else {
                    messageContent = this.createFileMessage(fileData);
                }
            } else {
                messageContent = `<p class="wplc-content-text">${Utils.escapeHtml(content)}</p>`;
            }
            
            const tempClass = options.is_temp ? 'temp-message' : '';
            const messageHTML = `
                <div class="wplc-message-row wplc-${typeClass} ${tempClass}">
                    <div class="wplc-message-bubble">
                        <div class="wplc-message-header">
                            <span class="wplc-sender-label">${senderLabel}</span>
                            <span class="wplc-timestamp">${timeString}</span>
                        </div>
                        ${messageContent}
                    </div>
                </div>
            `;
            
            this.elements.messagesContainer.insertAdjacentHTML('beforeend', messageHTML);
            this.scrollToBottom();
        }

        createImageMessage(fileData) {
            // اضافه کردن دیباگ
            console.log('Creating image message with data:', fileData);
            console.log('Image URL:', fileData.file_url);
            
            if (!fileData || !fileData.file_url) {
                console.error('Invalid file data for image:', fileData);
                return '<div class="image-message">❌ داده تصویر نامعتبر است</div>';
            }
            
            return `
                <div class="image-message">
                    <div class="image-preview-container">
                        <img src="${fileData.file_url}" 
                            alt="${fileData.file_name || 'تصویر'}" 
                            class="chat-image-preview"
                            loading="lazy"
                            style="max-width: 200px; max-height: 200px; border-radius: 8px; cursor: pointer;"
                            onclick="this.classList.toggle('zoomed')">
                        <div class="image-info">
                            <span class="image-name">${Utils.truncateFileName(fileData.file_name || 'تصویر')}</span>
                            <a href="${fileData.file_url}" 
                                download="${fileData.file_name || 'image'}"
                                class="download-link">
                                دانلود
                            </a>
                        </div>
                    </div>
                </div>
            `;
        }

        createFileMessage(fileData) {
            if (!fileData) {
                return '<div class="file-message">❌ داده فایل نامعتبر است</div>';
            }
            
            const fileIcon = Utils.getFileIcon(fileData.file_type || '');
            const fileName = fileData.file_name || 'نامشخص';
            const fileUrl = fileData.file_url || '#';
            const fileType = fileData.file_type || '';
            const fileSize = fileData.file_size ? Utils.formatFileSize(fileData.file_size) : 'نامشخص';
            
            return `
                <div class="file-message">
                    <span class="file-icon">${fileIcon}</span>
                    <div class="file-info">
                        <a href="${fileUrl}" 
                            target="_blank" 
                            class="file-name">
                            ${Utils.truncateFileName(fileName)}
                        </a>
                        <div class="file-meta">
                            <span class="file-type">${fileType.toUpperCase()}</span>
                            <span class="file-size">${fileSize}</span>
                        </div>
                    </div>
                    <a href="${fileUrl}" 
                        download="${fileName}"
                        class="download-btn" title="دانلود">
                        📥
                    </a>
                </div>
            `;
        }

        updateAdminStatus(isOnline, onlineCount = 1) {
            if (!this.elements.chatIcon || !this.elements.adminStatus || !this.elements.statusText) return;
            
            if (isOnline) {
                this.elements.chatIcon.classList.remove('is-offline');
                this.elements.chatIcon.classList.add('is-online');
                this.elements.adminStatus.classList.remove('is-offline');
                this.elements.adminStatus.classList.add('is-online');
                
                if (onlineCount > 1) {
                    this.elements.statusText.textContent = `${onlineCount} اپراتور آنلاین`;
                } else {
                    this.elements.statusText.textContent = 'آنلاین';
                }
            } else {
                this.elements.chatIcon.classList.remove('is-online');
                this.elements.chatIcon.classList.add('is-offline');
                this.elements.adminStatus.classList.remove('is-online');
                this.elements.adminStatus.classList.add('is-offline');
                this.elements.statusText.textContent = 'آفلاین';
            }
        }

        showLoading() {
            if (!this.elements.messagesContainer) return;
            
            this.elements.messagesContainer.innerHTML = `
                <div class="wplc-loading">
                    <div class="wplc-loading-spinner"></div>
                    <span>در حال بارگذاری...</span>
                </div>
            `;
        }

        clearMessages() {
            if (this.elements.messagesContainer) {
                this.elements.messagesContainer.innerHTML = '';
            }
        }

        scrollToBottom() {
            if (this.elements.messagesContainer) {
                this.elements.messagesContainer.scrollTop = this.elements.messagesContainer.scrollHeight;
            }
        }

        showErrorMessage(message) {
            if (!this.elements.messagesContainer) return;
            
            const errorHTML = `
                <div class="wplc-error">
                    ${message}
                </div>
            `;
            
            this.elements.messagesContainer.insertAdjacentHTML('beforeend', errorHTML);
            this.scrollToBottom();
        }
        
        addWelcomeMessage() {
            if (!this.elements.messagesContainer) return;
            
            if (this.elements.messagesContainer.children.length === 0) {
                this.appendMessage(
                    '👋 سلام! برای شروع گفتگو، لطفاً پیام خود را ارسال کنید.',
                    'system'
                );
            }
        }
    }

    // ------------------------------------------------------------
    // ChatApp Class - اصلاح شده
    // ------------------------------------------------------------
    class ChatApp {
        constructor() {
            console.log('ChatApp constructor called');
            this.ui = new ChatUI();
            this.pusher = null;
            this.channel = null;
            this.isLoading = false;
            this.isOpen = false;
            this.receivedMessageIds = new Set();
                
            this.init();
        }


        init() {
            console.log('ChatApp initializing...');
            
            this.ui.bindEvents(
                () => this.handleChatOpen(),
                () => this.handleChatClose(),
                (msg) => this.handleUserMessage(msg)
            );
            
            setTimeout(() => {
                const messagesContainer = this.ui.elements.messagesContainer;
                if (messagesContainer && messagesContainer.children.length === 0) {
                    this.ui.addWelcomeMessage();
                }
            }, 100);
            
            if (wplc_data.pusher_key && wplc_data.pusher_cluster) {
                this.initPusher();
            }
            
            this.checkAdminStatus();
            this.forceEnableInput();
            
            window.WPLCChatApp = this;
            window.testImageDisplay = () => this.testImageDisplay();

            console.log('ChatApp initialized successfully');
        }

        async checkAdminStatus() {
            try {
                const formData = new FormData();
                formData.append('action', 'wplc_check_admin_online');
                formData.append('security', wplc_data.check_admin_nonce);
                
                const response = await fetch(wplc_data.ajax_url, {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success) {
                        this.ui.updateAdminStatus(data.data.is_online, data.data.online_count || 1);
                    }
                }
            } catch (error) {
                console.error('Error checking admin status:', error);
            }
        }

        initPusher() {
            if (typeof Pusher === 'undefined') {
                console.warn('Pusher library not loaded');
                return;
            }

            try {
                this.pusher = new Pusher(wplc_data.pusher_key, {
                    cluster: wplc_data.pusher_cluster,
                    authEndpoint: wplc_data.ajax_url,
                    auth: {
                        params: { 
                            action: 'wplc_pusher_auth',
                            security: wplc_data.authNonce 
                        }
                    },
                    forceTLS: true,
                    enabledTransports: ['ws', 'wss']
                });

                this.pusher.connection.bind('state_change', (states) => {
                    console.log('Pusher connection state:', states.current);
                });

                this.channel = this.pusher.subscribe(`private-session-${wplc_data.session_id}`);
                
                this.channel.bind('new-message', (data) => {
                    console.log('Pusher new-message received:', data);
                    
                    const messageId = data.message_id || `${data.session_id}_${data.sender_type}_${data.created_at}_${data.content}`;
                    
                    if (this.receivedMessageIds.has(messageId)) {
                        console.log('Duplicate message ignored:', messageId);
                        return;
                    }
                    
                    this.receivedMessageIds.add(messageId);
                    
                    if (this.receivedMessageIds.size > 100) {
                        const ids = Array.from(this.receivedMessageIds);
                        this.receivedMessageIds = new Set(ids.slice(-100));
                    }
                    
                    this.handleIncomingMessage(data);
                });
                
                this.channel.bind('chat-closed', (data) => {
                    this.ui.appendMessage('گفتگو توسط پشتیبان بسته شد.', 'system');
                });

                console.log('Pusher connected successfully');
                
            } catch (error) {
                console.error('Pusher initialization error:', error);
            }
        }

        forceEnableInput() {
            if (this.ui.elements.messageInput) {
                this.ui.elements.messageInput.disabled = false;
                this.ui.elements.messageInput.placeholder = 'پیام خود را بنویسید...';
            }
            
            if (this.ui.elements.sendBtn) {
                this.ui.elements.sendBtn.disabled = false;
            }
        }

        handleChatOpen() {
            console.log('Opening chat... Current isOpen:', this.isOpen);
            
            if (this.isOpen) {
                console.log('Chat is already open, closing instead');
                this.handleChatClose();
                return;
            }
            
            this.isOpen = true;
            console.log('Setting isOpen to true');
            
            this.ui.toggleChatBox(true);
            this.loadChatHistory();
        }

        handleChatClose() {
            console.log('Closing chat... Current isOpen:', this.isOpen);
            this.isOpen = false;
            this.ui.toggleChatBox(false);
        }

        async loadChatHistory() {
            console.log('Loading history... Current isLoading:', this.isLoading);
            
            if (this.isLoading) {
                console.log('Already loading history, skipping');
                return;
            }
            
            this.isLoading = true;
            
            try {
                console.log('Showing loading indicator...');
                this.ui.showLoading();
                
                const data = await Utils.postData('wplc_get_history', {
                    session_id: wplc_data.session_id
                });
                
                this.ui.clearMessages();
                
                if (data.history && data.history.length > 0) {
                    console.log('Received history:', data.history.length, 'messages');
                    data.history.forEach(msg => {
                        this.displayMessage(msg);
                    });
                } else {
                    console.log('No history found');
                }
                
                this.ui.scrollToBottom();
                
            } catch (error) {
                console.error('Error loading history:', error);
                this.ui.showErrorMessage('خطا در بارگذاری تاریخچه چت');
            } finally {
                console.log('History loading complete, setting isLoading to false');
                this.isLoading = false;
            }
        }

        displayMessage(messageData) {
            if (messageData.file_data) {
                this.ui.appendMessage('', messageData.sender_type, messageData.created_at, 
                    messageData.sender_name || 'کاربر', {
                    is_file: true,
                    file_data: messageData.file_data
                });
            } else {
                this.ui.appendMessage(
                    messageData.content || messageData.message_content,
                    messageData.sender_type,
                    messageData.created_at,
                    messageData.sender_name
                );
            }
        }

        async handleUserMessage(message) {
            if (!message.trim()) return;
            
            const tempId = 'temp_' + Date.now();
            this.ui.appendMessage(message, 'user', null, 'شما', { 
                is_temp: true,
                temp_id: tempId 
            });
            
            try {
                const data = await Utils.postData('wplc_send_message', {
                    session_id: wplc_data.session_id,
                    message: message
                });
                
                this.ui.clearInput();
                this.markMessageAsSent(tempId);
                
                if (data.system_response) {
                    this.ui.appendMessage(
                        data.system_response.content,
                        'system',
                        data.system_response.created_at
                    );
                }
                
            } catch (error) {
                console.error('Error sending message:', error);
                this.markMessageAsFailed(tempId, message);
                this.ui.showErrorMessage('خطا در ارسال پیام');
            }
        }

        markMessageAsSent(tempId) {
            const messages = this.ui.elements.messagesContainer.querySelectorAll('.wplc-message-row');
            messages.forEach(msg => {
                if (msg.dataset.tempId === tempId) {
                    msg.classList.remove('temp-message');
                    msg.classList.add('sent-message');
                }
            });
        }
        
        markMessageAsFailed(tempId, message) {
            const messages = this.ui.elements.messagesContainer.querySelectorAll('.wplc-message-row');
            messages.forEach(msg => {
                if (msg.dataset.tempId === tempId) {
                    msg.classList.remove('temp-message');
                    msg.classList.add('failed-message');
                    const content = msg.querySelector('.wplc-content-text');
                    if (content) {
                        content.innerHTML = `❌ <span style="opacity: 0.7;">${Utils.escapeHtml(message)}</span>`;
                    }
                }
            });
        }

        async handleFileUpload(file) {
            if (!file) {
                console.error('No file provided');
                return;
            }
            
            console.log('Starting file upload:', file.name, file.size, file.type);
            
            const maxSize = 10 * 1024 * 1024;
            if (file.size > maxSize) {
                alert('حجم فایل نباید بیشتر از 10 مگابایت باشد.');
                return;
            }
            
            const tempId = 'file_' + Date.now();
            this.ui.appendMessage(`📤 در حال آپلود "${file.name}"...`, 'user', null, 'شما', { 
                is_temp: true,
                temp_id: tempId 
            });
            
            try {
                const formData = new FormData();
                formData.append('action', 'wplc_upload_file_user');
                formData.append('security', wplc_data.ajaxNonce);
                formData.append('session_id', wplc_data.session_id);
                formData.append('file', file);
                
                console.log('Sending file upload request...');
                
                const response = await fetch(wplc_data.ajax_url, {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
                console.log('Upload response:', result);
                
                if (result.success) {
                    this.markFileUploadSuccess(tempId, result.data || result.file_data);
                    
                    if (result.data || result.file_data) {
                        const fileData = result.data || result.file_data;
                        this.ui.appendMessage('', 'user', new Date().toISOString(), 'شما', {
                            is_file: true,
                            file_data: fileData
                        });
                    }
                } else {
                    throw new Error(result.message || result.data?.message || 'Upload failed');
                }
                
            } catch (error) {
                console.error('File upload error:', error);
                this.markFileUploadFailed(tempId, file.name, error.message);
            }
        }

        markFileUploadSuccess(tempId, fileData) {
            const messages = this.ui.elements.messagesContainer.querySelectorAll('.wplc-message-row');
            messages.forEach(msg => {
                if (msg.dataset.tempId === tempId) {
                    msg.classList.remove('temp-message');
                    const content = msg.querySelector('.wplc-content-text');
                    if (content && fileData) {
                        const fileName = fileData.file_name || 'فایل';
                        content.innerHTML = `✅ فایل "${Utils.truncateFileName(fileName)}" آپلود شد`;
                    }
                }
            });
        }

        markFileUploadFailed(tempId, fileName, errorMessage) {
            const messages = this.ui.elements.messagesContainer.querySelectorAll('.wplc-message-row');
            messages.forEach(msg => {
                if (msg.dataset.tempId === tempId) {
                    msg.classList.remove('temp-message');
                    msg.classList.add('failed-message');
                    const content = msg.querySelector('.wplc-content-text');
                    if (content) {
                        const safeFileName = fileName || 'فایل';
                        content.innerHTML = `❌ خطا در آپلود "${Utils.truncateFileName(safeFileName)}": ${errorMessage}`;
                    }
                }
            });
        }

        handleIncomingMessage(data) {
            console.log('Processing incoming message:', data);
            
            // بررسی پیام‌های تکراری
            const messageId = data.message_id || `${data.session_id}_${data.sender_type}_${data.created_at}_${data.content}`;
            
            // if (!this.receivedMessageIds) {
            //     this.receivedMessageIds = new Set();
            // }
            
            // اگر پیام تکراری است، رد کن
            // if (this.receivedMessageIds.has(messageId)) {
            //     console.log('Duplicate message ignored:', messageId);
            //     return;
            // }
            
            // اضافه کردن به لیست پیام‌های دریافتی
            this.receivedMessageIds.add(messageId);
            
            // فقط ۱۰۰ پیام آخر را نگه دار
            if (this.receivedMessageIds.size > 100) {
                const ids = Array.from(this.receivedMessageIds);
                this.receivedMessageIds = new Set(ids.slice(-100));
            }
            
            // فقط پیام‌های ادمین را نمایش بده (پیام‌های کاربر توسط خود کاربر ارسال شده)
            if (data.sender_type === 'user' && data.sender_id === wplc_data.session_id) {
                console.log('User own message, not displaying');
                return;
            }
            
            console.log('Displaying incoming message from:', data.sender_type, data);
            
            // بررسی برای فایل‌ها
            if ((data.message_type === 'file' || data.file_data) && data.sender_type === 'admin') {
                console.log('Displaying file from admin:', data.file_data);
                
                if (data.file_data && data.file_data.file_url) {
                    this.ui.appendMessage('', data.sender_type, data.created_at, 
                        data.sender_name || 'پشتیبان', {
                        is_file: true,
                        file_data: data.file_data
                    });
                } else {
                    console.error('Invalid file data from admin:', data);
                    this.ui.appendMessage(
                        'فایل ارسال شده قابل نمایش نیست.',
                        data.sender_type,
                        data.created_at,
                        data.sender_name || 'پشتیبان'
                    );
                }
            } else if (data.sender_type === 'admin') {
                // پیام متنی از ادمین
                this.ui.appendMessage(
                    data.content,
                    data.sender_type,
                    data.created_at,
                    data.sender_name || 'پشتیبان'
                );
            }
        }

        // اضافه کردن این تابع به کلاس ChatApp
        testImageDisplay() {
            console.log('=== Testing Image Display ===');
            
            // تست URL پایه
            console.log('Plugin URL from wplc_data:', wplc_data?.ajax_url);
            
            // تست یک تصویر نمونه
            const testImageData = {
                file_name: 'test-image.jpg',
                file_url: 'https://via.placeholder.com/200x150/007cba/ffffff?text=Test+Image',
                file_type: 'jpg',
                file_size: 1024,
                formatted_size: '1 KB'
            };
            
            console.log('Test image data:', testImageData);
            
            // نمایش تصویر تست
            this.ui.appendMessage('', 'admin', new Date().toISOString(), 'تست سیستم', {
                is_file: true,
                file_data: testImageData
            });
            
            console.log('Test image should be displayed above');
        }

    }

    // ------------------------------------------------------------
    // Initialize
    // ------------------------------------------------------------
    $(document).ready(function() {
        console.log('WP Live Chat: DOM ready, initializing...');
        
        if (typeof wplc_data === 'undefined') {
            console.error('WP Live Chat: Data not loaded');
            return;
        }
        
        const chatIcon = document.getElementById('wplc-chat-icon');
        const chatBox = document.getElementById('wplc-chat-box');
        
        console.log('Chat icon found:', !!chatIcon);
        console.log('Chat box found:', !!chatBox);
        
        setTimeout(function() {
            try {
                new ChatApp();
                console.log('WP Live Chat: Initialized successfully');
            } catch (error) {
                console.error('WP Live Chat: Initialization error:', error);
            }
        }, 1000);
    });

})(jQuery, wplc_data);