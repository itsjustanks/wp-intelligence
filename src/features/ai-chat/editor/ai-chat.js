(function () {
  'use strict';

  var config = window.wpiAiChatConfig || {};
  if (!config.hasProvider) return;

  var apiFetch = wp.apiFetch;
  var __ = wp.i18n.__;

  var state = {
    open: false,
    conversationId: '',
    messages: [],
    conversations: [],
    loading: false,
    view: 'chat',
  };

  var drawerEl = null;
  var fullscreenEl = null;

  var SUGGESTIONS = [
    { icon: 'edit', text: 'Help me write a blog post' },
    { icon: 'search', text: 'Suggest SEO improvements' },
    { icon: 'lightbulb', text: 'Give me content ideas' },
    { icon: 'admin-tools', text: 'Help with WordPress tasks' },
  ];

  function getPageContext() {
    var ctx = { admin_page: window.location.href };
    try {
      if (wp && wp.data && wp.data.select('core/editor')) {
        var editor = wp.data.select('core/editor');
        var postId = editor.getCurrentPostId();
        if (postId) {
          ctx.post_id = postId;
          ctx.post_type = editor.getCurrentPostType() || '';
          ctx.post_title = editor.getEditedPostAttribute('title') || '';
        }
      }
    } catch (e) {}
    return ctx;
  }

  function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function formatTime(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr + 'Z');
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr + 'Z');
    var now = new Date();
    var diff = now - d;
    if (diff < 86400000) return __('Today', 'wp-intelligence');
    if (diff < 172800000) return __('Yesterday', 'wp-intelligence');
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

  function formatContent(text) {
    text = escHtml(text);

    // Code blocks (triple backtick)
    text = text.replace(/```(\w*)\n?([\s\S]*?)```/g, function (m, lang, code) {
      return '<pre class="wpi-chat-code-block"><code>' + code.trim() + '</code></pre>';
    });

    // Inline code
    text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Bold
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

    // Italic (single * not adjacent to another *)
    text = text.replace(/(?:^|[^*])\*([^*]+)\*(?:[^*]|$)/g, function (m, p1) {
      return m.replace('*' + p1 + '*', '<em>' + p1 + '</em>');
    });

    // Links
    text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

    // Unordered lists
    text = text.replace(/(^|\n)- (.+)/g, '$1<li>$2</li>');
    text = text.replace(/(<li>[\s\S]*?<\/li>)/g, '<ul>$1</ul>');
    text = text.replace(/<\/ul>\s*<ul>/g, '');

    // Ordered lists
    text = text.replace(/(^|\n)\d+\. (.+)/g, '$1<li>$2</li>');

    // Headers
    text = text.replace(/(^|\n)### (.+)/g, '$1<h4>$2</h4>');
    text = text.replace(/(^|\n)## (.+)/g, '$1<h3>$2</h3>');

    // Line breaks
    text = text.replace(/\n/g, '<br>');

    return text;
  }

  function buildSuggestionsHtml() {
    var html = '<div class="wpi-chat-suggestions">';
    SUGGESTIONS.forEach(function (s) {
      html +=
        '<button class="wpi-chat-suggestion" data-prompt="' + escHtml(s.text) + '">' +
          '<span class="dashicons dashicons-' + s.icon + '"></span>' +
          '<span>' + escHtml(s.text) + '</span>' +
        '</button>';
    });
    html += '</div>';
    return html;
  }

  function buildEmptyState() {
    return (
      '<div class="wpi-chat-empty">' +
        '<div class="wpi-chat-empty__icon">' +
          '<span class="dashicons dashicons-format-chat"></span>' +
        '</div>' +
        '<h3 class="wpi-chat-empty__title">' + __('How can I help you?', 'wp-intelligence') + '</h3>' +
        '<p class="wpi-chat-empty__desc">' + __('Ask me anything about your site, content, or WordPress.', 'wp-intelligence') + '</p>' +
        buildSuggestionsHtml() +
      '</div>'
    );
  }

  function buildTypingIndicator() {
    return (
      '<div class="wpi-chat-msg wpi-chat-msg--assistant wpi-chat-msg--typing">' +
        '<div class="wpi-chat-msg__avatar wpi-chat-msg__avatar--ai">' +
          '<span class="dashicons dashicons-lightbulb"></span>' +
        '</div>' +
        '<div class="wpi-chat-msg__content">' +
          '<div class="wpi-chat-msg__bubble">' +
            '<div class="wpi-chat-typing">' +
              '<span></span><span></span><span></span>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
  }

  function buildMessageHtml(msg) {
    if (msg.role === 'system') return '';
    var isUser = msg.role === 'user';
    var cls = isUser ? 'wpi-chat-msg--user' : 'wpi-chat-msg--assistant';

    var avatar = isUser
      ? '<div class="wpi-chat-msg__avatar wpi-chat-msg__avatar--user"><span class="dashicons dashicons-admin-users"></span></div>'
      : '<div class="wpi-chat-msg__avatar wpi-chat-msg__avatar--ai"><span class="dashicons dashicons-lightbulb"></span></div>';

    return (
      '<div class="wpi-chat-msg ' + cls + '">' +
        avatar +
        '<div class="wpi-chat-msg__content">' +
          '<div class="wpi-chat-msg__bubble">' + (isUser ? escHtml(msg.content) : formatContent(msg.content)) + '</div>' +
          (msg.created_at ? '<div class="wpi-chat-msg__time">' + formatTime(msg.created_at) + '</div>' : '') +
        '</div>' +
      '</div>'
    );
  }

  /* ──────────────────────────────────────────────
   *  Drawer mode (slide-out panel for all pages)
   * ────────────────────────────────────────────── */

  function renderDrawer() {
    if (drawerEl) return drawerEl;

    drawerEl = document.createElement('div');
    drawerEl.id = 'wpi-ai-chat-drawer';
    drawerEl.className = 'wpi-chat-drawer';
    drawerEl.innerHTML =
      '<div class="wpi-chat-drawer__overlay"></div>' +
      '<div class="wpi-chat-drawer__panel">' +
        '<div class="wpi-chat-drawer__header">' +
          '<div class="wpi-chat-drawer__header-left">' +
            '<button class="wpi-chat-drawer__history-btn" title="' + __('Conversations', 'wp-intelligence') + '">' +
              '<span class="dashicons dashicons-list-view"></span>' +
            '</button>' +
            '<span class="wpi-chat-drawer__title">' + __('Ask AI', 'wp-intelligence') + '</span>' +
          '</div>' +
          '<div class="wpi-chat-drawer__header-right">' +
            '<a class="wpi-chat-drawer__expand-btn" href="' + escHtml(config.pageUrl || '#') + '" title="' + __('Open full page', 'wp-intelligence') + '">' +
              '<span class="dashicons dashicons-editor-expand"></span>' +
            '</a>' +
            '<button class="wpi-chat-drawer__new-btn" title="' + __('New conversation', 'wp-intelligence') + '">' +
              '<span class="dashicons dashicons-plus-alt2"></span>' +
            '</button>' +
            '<button class="wpi-chat-drawer__close-btn" title="' + __('Close', 'wp-intelligence') + '">' +
              '<span class="dashicons dashicons-no-alt"></span>' +
            '</button>' +
          '</div>' +
        '</div>' +
        '<div class="wpi-chat-drawer__body">' +
          '<div class="wpi-chat-drawer__messages"></div>' +
          '<div class="wpi-chat-drawer__history-list" style="display:none;"></div>' +
        '</div>' +
        '<div class="wpi-chat-drawer__footer">' +
          '<div class="wpi-chat-drawer__input-wrap">' +
            '<textarea class="wpi-chat-drawer__input" rows="1" placeholder="' + __('Message Ask AI...', 'wp-intelligence') + '"></textarea>' +
            '<button class="wpi-chat-drawer__send-btn" disabled title="' + __('Send', 'wp-intelligence') + '">' +
              '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 14l12-6L2 2v4.67L10.5 8 2 9.33V14z" fill="currentColor"/></svg>' +
            '</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    document.body.appendChild(drawerEl);
    bindDrawerEvents(drawerEl);
    return drawerEl;
  }

  function bindDrawerEvents(el) {
    var overlay = el.querySelector('.wpi-chat-drawer__overlay');
    var closeBtn = el.querySelector('.wpi-chat-drawer__close-btn');
    var newBtn = el.querySelector('.wpi-chat-drawer__new-btn');
    var histBtn = el.querySelector('.wpi-chat-drawer__history-btn');
    var sendBtn = el.querySelector('.wpi-chat-drawer__send-btn');
    var input = el.querySelector('.wpi-chat-drawer__input');

    overlay.addEventListener('click', toggleDrawer);
    closeBtn.addEventListener('click', toggleDrawer);

    newBtn.addEventListener('click', function () {
      startNewConversation();
    });

    histBtn.addEventListener('click', function () {
      if (state.view === 'history') {
        showChatView();
      } else {
        showHistoryView();
      }
    });

    setupInputHandlers(input, sendBtn);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && state.open && !config.isFullscreen) {
        toggleDrawer();
      }
    });
  }

  function setupInputHandlers(input, sendBtn) {
    input.addEventListener('input', function () {
      sendBtn.disabled = !input.value.trim() || state.loading;
      autoResizeInput(input);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (input.value.trim() && !state.loading) sendMessage();
      }
    });

    sendBtn.addEventListener('click', function () {
      if (input.value.trim() && !state.loading) sendMessage();
    });
  }

  function autoResizeInput(input) {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 150) + 'px';
  }

  function toggleDrawer() {
    state.open = !state.open;
    var drawer = renderDrawer();
    drawer.classList.toggle('is-open', state.open);
    document.body.classList.toggle('wpi-chat-drawer-open', state.open);

    if (state.open) {
      if (state.messages.length === 0 && state.conversationId === '') {
        renderMessages();
      }
      setTimeout(function () {
        var input = drawer.querySelector('.wpi-chat-drawer__input');
        if (input) input.focus();
      }, 300);
    }
  }

  function startNewConversation() {
    state.conversationId = '';
    state.messages = [];
    state.view = 'chat';
    renderMessages();
    showChatView();
    var container = getActiveContainer();
    if (container) {
      var input = container.querySelector('.wpi-chat-drawer__input, .wpi-chat-page__input');
      if (input) input.focus();
    }
  }

  function showChatView() {
    state.view = 'chat';
    var container = getActiveContainer();
    if (!container) return;
    var msgs = container.querySelector('.wpi-chat-drawer__messages, .wpi-chat-page__messages');
    var hist = container.querySelector('.wpi-chat-drawer__history-list, .wpi-chat-page__history-list');
    var footer = container.querySelector('.wpi-chat-drawer__footer, .wpi-chat-page__footer');
    var title = container.querySelector('.wpi-chat-drawer__title, .wpi-chat-page__title');
    if (msgs) msgs.style.display = '';
    if (hist) hist.style.display = 'none';
    if (footer) footer.style.display = '';
    if (title) title.textContent = __('Ask AI', 'wp-intelligence');
  }

  function showHistoryView() {
    state.view = 'history';
    var container = getActiveContainer();
    if (!container) return;
    var msgs = container.querySelector('.wpi-chat-drawer__messages, .wpi-chat-page__messages');
    var hist = container.querySelector('.wpi-chat-drawer__history-list, .wpi-chat-page__history-list');
    var footer = container.querySelector('.wpi-chat-drawer__footer, .wpi-chat-page__footer');
    var title = container.querySelector('.wpi-chat-drawer__title, .wpi-chat-page__title');
    if (msgs) msgs.style.display = 'none';
    if (hist) hist.style.display = '';
    if (footer) footer.style.display = 'none';
    if (title) title.textContent = __('Conversations', 'wp-intelligence');
    loadHistory();
  }

  function getActiveContainer() {
    if (config.isFullscreen && fullscreenEl) return fullscreenEl;
    return drawerEl;
  }

  function getMessagesContainer() {
    var container = getActiveContainer();
    if (!container) return null;
    return container.querySelector('.wpi-chat-drawer__messages, .wpi-chat-page__messages');
  }

  function renderMessages() {
    var container = getMessagesContainer();
    if (!container) return;

    if (state.messages.length === 0) {
      container.innerHTML = buildEmptyState();
      bindSuggestionEvents(container);
      return;
    }

    var html = '';
    state.messages.forEach(function (msg) {
      if (msg._pending) {
        html += buildTypingIndicator();
      } else {
        html += buildMessageHtml(msg);
      }
    });

    container.innerHTML = html;
    container.scrollTop = container.scrollHeight;
  }

  function bindSuggestionEvents(container) {
    container.querySelectorAll('.wpi-chat-suggestion').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var prompt = btn.getAttribute('data-prompt');
        var active = getActiveContainer();
        var input = active.querySelector('.wpi-chat-drawer__input, .wpi-chat-page__input');
        if (input && prompt) {
          input.value = prompt;
          input.dispatchEvent(new Event('input'));
          sendMessage();
        }
      });
    });
  }

  function sendMessage() {
    var container = getActiveContainer();
    if (!container) return;
    var input = container.querySelector('.wpi-chat-drawer__input, .wpi-chat-page__input');
    var sendBtn = container.querySelector('.wpi-chat-drawer__send-btn, .wpi-chat-page__send-btn');
    var msg = input.value.trim();
    if (!msg) return;

    state.messages.push({ role: 'user', content: msg, created_at: new Date().toISOString().replace('Z', '') });
    renderMessages();

    input.value = '';
    input.style.height = 'auto';
    sendBtn.disabled = true;
    state.loading = true;

    state.messages.push({ role: 'assistant', content: '', _pending: true });
    renderMessages();

    apiFetch({
      path: '/ai-composer/v1/chat',
      method: 'POST',
      data: {
        message: msg,
        conversation_id: state.conversationId,
        context: JSON.stringify(getPageContext()),
      },
    })
      .then(function (res) {
        state.loading = false;
        state.conversationId = res.conversation_id;
        state.messages = state.messages.filter(function (m) { return !m._pending; });
        state.messages.push({ role: 'assistant', content: res.message, created_at: new Date().toISOString().replace('Z', '') });
        renderMessages();
        sendBtn.disabled = false;
      })
      .catch(function (err) {
        state.loading = false;
        state.messages = state.messages.filter(function (m) { return !m._pending; });
        state.messages.push({ role: 'assistant', content: __('Sorry, something went wrong: ', 'wp-intelligence') + ((err && err.message) || __('Unknown error.', 'wp-intelligence')) });
        renderMessages();
        sendBtn.disabled = false;
      });
  }

  function loadHistory() {
    var container = getActiveContainer();
    if (!container) return;
    var histList = container.querySelector('.wpi-chat-drawer__history-list, .wpi-chat-page__history-list');
    if (!histList) return;
    histList.innerHTML = '<div class="wpi-chat-loading"><div class="wpi-chat-loading__spinner"></div></div>';

    apiFetch({ path: '/ai-composer/v1/chat/history' })
      .then(function (list) {
        state.conversations = list;
        if (!list.length) {
          histList.innerHTML =
            '<div class="wpi-chat-empty wpi-chat-empty--compact">' +
              '<span class="dashicons dashicons-format-chat"></span>' +
              '<p>' + __('No conversations yet. Start one!', 'wp-intelligence') + '</p>' +
            '</div>';
          return;
        }

        var html = '<div class="wpi-chat-history">';
        list.forEach(function (c) {
          var title = c.title || __('Untitled', 'wp-intelligence');
          html +=
            '<div class="wpi-chat-history-item" data-cid="' + c.conversation_id + '">' +
              '<div class="wpi-chat-history-item__main">' +
                '<div class="wpi-chat-history-item__title">' + escHtml(title) + '</div>' +
                '<div class="wpi-chat-history-item__meta">' +
                  '<span>' + c.message_count + ' ' + __('messages', 'wp-intelligence') + '</span>' +
                  '<span class="wpi-chat-history-item__dot"></span>' +
                  '<span>' + formatDate(c.last_message_at) + '</span>' +
                '</div>' +
              '</div>' +
              '<button class="wpi-chat-history-item__delete" title="' + __('Delete', 'wp-intelligence') + '">' +
                '<span class="dashicons dashicons-trash"></span>' +
              '</button>' +
            '</div>';
        });
        html += '</div>';
        histList.innerHTML = html;

        histList.querySelectorAll('.wpi-chat-history-item__main').forEach(function (el) {
          el.addEventListener('click', function () {
            var cid = el.parentElement.getAttribute('data-cid');
            loadConversation(cid);
          });
        });

        histList.querySelectorAll('.wpi-chat-history-item__delete').forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var cid = btn.parentElement.getAttribute('data-cid');
            deleteConversation(cid);
          });
        });
      })
      .catch(function () {
        histList.innerHTML =
          '<div class="wpi-chat-empty wpi-chat-empty--compact">' +
            '<p>' + __('Could not load conversations.', 'wp-intelligence') + '</p>' +
          '</div>';
      });
  }

  function loadConversation(cid) {
    state.conversationId = cid;
    state.view = 'chat';
    showChatView();

    var msgContainer = getMessagesContainer();
    if (msgContainer) {
      msgContainer.innerHTML = '<div class="wpi-chat-loading"><div class="wpi-chat-loading__spinner"></div></div>';
    }

    apiFetch({ path: '/ai-composer/v1/chat/' + cid })
      .then(function (res) {
        state.messages = (res.messages || []).map(function (m) {
          return { role: m.role, content: m.content, created_at: m.created_at };
        });
        renderMessages();
      })
      .catch(function () {
        state.messages = [];
        renderMessages();
      });
  }

  function deleteConversation(cid) {
    apiFetch({ path: '/ai-composer/v1/chat/' + cid, method: 'DELETE' })
      .then(function () {
        if (state.conversationId === cid) {
          state.conversationId = '';
          state.messages = [];
        }
        loadHistory();
      });
  }

  /* ──────────────────────────────────────────────
   *  Fullscreen page mode
   * ────────────────────────────────────────────── */

  function initFullscreenPage() {
    var mount = document.getElementById('wpi-ask-ai-page');
    if (!mount) return;

    fullscreenEl = document.createElement('div');
    fullscreenEl.className = 'wpi-chat-page';
    fullscreenEl.innerHTML =
      '<div class="wpi-chat-page__sidebar">' +
        '<div class="wpi-chat-page__sidebar-header">' +
          '<button class="wpi-chat-page__new-btn">' +
            '<span class="dashicons dashicons-plus-alt2"></span>' +
            '<span>' + __('New chat', 'wp-intelligence') + '</span>' +
          '</button>' +
        '</div>' +
        '<div class="wpi-chat-page__history-list"></div>' +
      '</div>' +
      '<div class="wpi-chat-page__main">' +
        '<div class="wpi-chat-page__header">' +
          '<button class="wpi-chat-page__sidebar-toggle" title="' + __('Toggle sidebar', 'wp-intelligence') + '">' +
            '<span class="dashicons dashicons-menu"></span>' +
          '</button>' +
          '<span class="wpi-chat-page__title">' + __('Ask AI', 'wp-intelligence') + '</span>' +
        '</div>' +
        '<div class="wpi-chat-page__messages"></div>' +
        '<div class="wpi-chat-page__footer">' +
          '<div class="wpi-chat-page__input-wrap">' +
            '<textarea class="wpi-chat-page__input" rows="1" placeholder="' + __('Message Ask AI...', 'wp-intelligence') + '"></textarea>' +
            '<button class="wpi-chat-page__send-btn" disabled title="' + __('Send', 'wp-intelligence') + '">' +
              '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 14l12-6L2 2v4.67L10.5 8 2 9.33V14z" fill="currentColor"/></svg>' +
            '</button>' +
          '</div>' +
          '<p class="wpi-chat-page__disclaimer">' + __('AI can make mistakes. Verify important information.', 'wp-intelligence') + '</p>' +
        '</div>' +
      '</div>';

    mount.appendChild(fullscreenEl);

    var input = fullscreenEl.querySelector('.wpi-chat-page__input');
    var sendBtn = fullscreenEl.querySelector('.wpi-chat-page__send-btn');
    var newBtn = fullscreenEl.querySelector('.wpi-chat-page__new-btn');
    var sidebarToggle = fullscreenEl.querySelector('.wpi-chat-page__sidebar-toggle');

    setupInputHandlers(input, sendBtn);

    newBtn.addEventListener('click', function () {
      startNewConversation();
    });

    sidebarToggle.addEventListener('click', function () {
      fullscreenEl.classList.toggle('wpi-chat-page--sidebar-collapsed');
    });

    renderMessages();
    loadHistory();
    input.focus();
  }

  /* ──────────────────────────────────────────────
   *  Initialization
   * ────────────────────────────────────────────── */

  window.wpiAiChatToggle = toggleDrawer;

  document.addEventListener('DOMContentLoaded', function () {
    if (config.isFullscreen) {
      initFullscreenPage();
    }

    var trigger = document.querySelector('#wp-admin-bar-wpi-ai-chat-toggle a');
    if (trigger) {
      trigger.addEventListener('click', function (e) {
        if (config.isFullscreen) return;
        e.preventDefault();
        toggleDrawer();
      });
    }
  });
})();
