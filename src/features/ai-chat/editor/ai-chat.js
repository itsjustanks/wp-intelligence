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
          '<textarea class="wpi-chat-drawer__input" rows="1" placeholder="' + __('Type a message...', 'wp-intelligence') + '"></textarea>' +
          '<button class="wpi-chat-drawer__send-btn" disabled>' +
            '<span class="dashicons dashicons-arrow-right-alt2"></span>' +
          '</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(drawerEl);

    var overlay = drawerEl.querySelector('.wpi-chat-drawer__overlay');
    var closeBtn = drawerEl.querySelector('.wpi-chat-drawer__close-btn');
    var newBtn = drawerEl.querySelector('.wpi-chat-drawer__new-btn');
    var histBtn = drawerEl.querySelector('.wpi-chat-drawer__history-btn');
    var sendBtn = drawerEl.querySelector('.wpi-chat-drawer__send-btn');
    var input = drawerEl.querySelector('.wpi-chat-drawer__input');

    overlay.addEventListener('click', toggleDrawer);
    closeBtn.addEventListener('click', toggleDrawer);

    newBtn.addEventListener('click', function () {
      state.conversationId = '';
      state.messages = [];
      state.view = 'chat';
      renderMessages();
      showChatView();
    });

    histBtn.addEventListener('click', function () {
      if (state.view === 'history') {
        showChatView();
      } else {
        showHistoryView();
      }
    });

    input.addEventListener('input', function () {
      sendBtn.disabled = !input.value.trim() || state.loading;
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 120) + 'px';
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

    return drawerEl;
  }

  function toggleDrawer() {
    state.open = !state.open;
    var drawer = renderDrawer();
    drawer.classList.toggle('is-open', state.open);
    document.body.classList.toggle('wpi-chat-drawer-open', state.open);

    if (state.open && state.messages.length === 0 && state.conversationId === '') {
      renderMessages();
    }
  }

  function showChatView() {
    state.view = 'chat';
    var msgs = drawerEl.querySelector('.wpi-chat-drawer__messages');
    var hist = drawerEl.querySelector('.wpi-chat-drawer__history-list');
    var footer = drawerEl.querySelector('.wpi-chat-drawer__footer');
    msgs.style.display = '';
    hist.style.display = 'none';
    footer.style.display = '';
    drawerEl.querySelector('.wpi-chat-drawer__title').textContent = __('Ask AI', 'wp-intelligence');
  }

  function showHistoryView() {
    state.view = 'history';
    var msgs = drawerEl.querySelector('.wpi-chat-drawer__messages');
    var hist = drawerEl.querySelector('.wpi-chat-drawer__history-list');
    var footer = drawerEl.querySelector('.wpi-chat-drawer__footer');
    msgs.style.display = 'none';
    hist.style.display = '';
    footer.style.display = 'none';
    drawerEl.querySelector('.wpi-chat-drawer__title').textContent = __('Conversations', 'wp-intelligence');
    loadHistory();
  }

  function renderMessages() {
    var container = drawerEl.querySelector('.wpi-chat-drawer__messages');
    if (state.messages.length === 0) {
      container.innerHTML =
        '<div class="wpi-chat-drawer__empty">' +
          '<span class="dashicons dashicons-format-chat"></span>' +
          '<p>' + __('Start a conversation. Ask anything about your site, content strategy, or get help with your current page.', 'wp-intelligence') + '</p>' +
        '</div>';
      return;
    }

    var html = '';
    state.messages.forEach(function (msg) {
      if (msg.role === 'system') return;
      var cls = msg.role === 'user' ? 'wpi-chat-msg--user' : 'wpi-chat-msg--assistant';
      html += '<div class="wpi-chat-msg ' + cls + '">' +
        '<div class="wpi-chat-msg__bubble">' + formatContent(msg.content) + '</div>' +
        (msg.created_at ? '<div class="wpi-chat-msg__time">' + formatTime(msg.created_at) + '</div>' : '') +
      '</div>';
    });

    container.innerHTML = html;
    container.scrollTop = container.scrollHeight;
  }

  function formatContent(text) {
    text = escHtml(text);
    text = text.replace(/\n/g, '<br>');
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/`(.+?)`/g, '<code>$1</code>');
    return text;
  }

  function sendMessage() {
    var input = drawerEl.querySelector('.wpi-chat-drawer__input');
    var sendBtn = drawerEl.querySelector('.wpi-chat-drawer__send-btn');
    var msg = input.value.trim();
    if (!msg) return;

    state.messages.push({ role: 'user', content: msg, created_at: new Date().toISOString().replace('Z', '') });
    renderMessages();

    input.value = '';
    input.style.height = 'auto';
    sendBtn.disabled = true;
    state.loading = true;

    state.messages.push({ role: 'assistant', content: __('Thinking...', 'wp-intelligence'), _pending: true });
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
        state.messages.push({ role: 'assistant', content: __('Error: ', 'wp-intelligence') + ((err && err.message) || __('Something went wrong.', 'wp-intelligence')) });
        renderMessages();
        sendBtn.disabled = false;
      });
  }

  function loadHistory() {
    var container = drawerEl.querySelector('.wpi-chat-drawer__history-list');
    container.innerHTML = '<div class="wpi-chat-drawer__loading"><span class="spinner is-active"></span></div>';

    apiFetch({ path: '/ai-composer/v1/chat/history' })
      .then(function (list) {
        state.conversations = list;
        if (!list.length) {
          container.innerHTML = '<div class="wpi-chat-drawer__empty"><p>' + __('No conversations yet.', 'wp-intelligence') + '</p></div>';
          return;
        }

        var html = '';
        list.forEach(function (c) {
          var title = c.title || __('Untitled', 'wp-intelligence');
          var date = c.last_message_at ? new Date(c.last_message_at + 'Z').toLocaleDateString() : '';
          html +=
            '<div class="wpi-chat-history-item" data-cid="' + c.conversation_id + '">' +
              '<div class="wpi-chat-history-item__main">' +
                '<div class="wpi-chat-history-item__title">' + escHtml(title) + '</div>' +
                '<div class="wpi-chat-history-item__meta">' + c.message_count + ' messages \u00B7 ' + date + '</div>' +
              '</div>' +
              '<button class="wpi-chat-history-item__delete" title="' + __('Delete', 'wp-intelligence') + '">' +
                '<span class="dashicons dashicons-trash"></span>' +
              '</button>' +
            '</div>';
        });
        container.innerHTML = html;

        container.querySelectorAll('.wpi-chat-history-item__main').forEach(function (el) {
          el.addEventListener('click', function () {
            var cid = el.parentElement.getAttribute('data-cid');
            loadConversation(cid);
          });
        });

        container.querySelectorAll('.wpi-chat-history-item__delete').forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var cid = btn.parentElement.getAttribute('data-cid');
            deleteConversation(cid);
          });
        });
      })
      .catch(function () {
        container.innerHTML = '<div class="wpi-chat-drawer__empty"><p>' + __('Could not load conversations.', 'wp-intelligence') + '</p></div>';
      });
  }

  function loadConversation(cid) {
    state.conversationId = cid;
    state.view = 'chat';
    showChatView();

    var container = drawerEl.querySelector('.wpi-chat-drawer__messages');
    container.innerHTML = '<div class="wpi-chat-drawer__loading"><span class="spinner is-active"></span></div>';

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

  window.wpiAiChatToggle = toggleDrawer;

  document.addEventListener('DOMContentLoaded', function () {
    var trigger = document.querySelector('#wp-admin-bar-wpi-ai-chat-toggle a');
    if (trigger) {
      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        toggleDrawer();
      });
    }
  });
})();
