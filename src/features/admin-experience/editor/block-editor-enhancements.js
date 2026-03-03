(function () {
  'use strict';

  var config = window.aiComposerEditorEnhancements || {};

  wp.domReady(function () {
    if (config.forceFullscreen) {
      initFullscreen();
    }

    if (config.autoOpenListView) {
      initListView();
    }

    if (config.listViewLabel) {
      relabelListView(config.listViewLabel);
    }

    if (config.classAutocomplete) {
      initClassAutocomplete(config.classAutocompleteSource || '');
    }
  });

  // ------------------------------------------------------------------
  // Fullscreen mode
  // ------------------------------------------------------------------

  function initFullscreen() {
    var store = wp.data.select('core/edit-post');
    if (store && !store.isFeatureActive('fullscreenMode')) {
      wp.data.dispatch('core/edit-post').toggleFeature('fullscreenMode');
    }
  }

  // ------------------------------------------------------------------
  // List view toggle
  // ------------------------------------------------------------------

  function initListView() {
    function attempt() {
      var btn = document.querySelector(
        '.editor-document-tools__document-overview-toggle'
      );
      if (btn && !btn.classList.contains('is-pressed')) {
        btn.click();
      } else if (!btn) {
        setTimeout(attempt, 500);
      }
    }
    setTimeout(attempt, 500);
  }

  function relabelListView(label) {
    function attempt() {
      var el = document.getElementById('tabs-1-list-view');
      if (el) {
        el.textContent = label;
      } else {
        setTimeout(function () { relabelListView(label); }, 500);
      }
    }
    attempt();
  }

  // ------------------------------------------------------------------
  // CSS class autocomplete
  // ------------------------------------------------------------------

  var cachedClasses = null;

  function initClassAutocomplete(source) {
    if (typeof source === 'string' && source !== '') {
      fetch(source)
        .then(function (r) { return r.json(); })
        .then(function (list) {
          if (Array.isArray(list)) {
            cachedClasses = list;
            observeClassInputs();
          }
        })
        .catch(function (e) {
          console.warn('[WP Intelligence] Class autocomplete source failed:', e);
        });
    } else if (Array.isArray(source)) {
      cachedClasses = source;
      observeClassInputs();
    }
  }

  function observeClassInputs() {
    var observer = new MutationObserver(function () {
      attachToVisibleInputs();
    });

    observer.observe(document.body, { childList: true, subtree: true });
    attachToVisibleInputs();
  }

  function attachToVisibleInputs() {
    if (!cachedClasses || cachedClasses.length === 0) {
      return;
    }

    var panel = document.querySelector('.block-editor-block-inspector__advanced');
    if (!panel) {
      return;
    }

    var inputs = panel.querySelectorAll('input[type="text"]');
    inputs.forEach(function (input) {
      if (input.getAttribute('data-wpi-autocomplete')) {
        return;
      }

      var label = input.closest('.components-base-control');
      if (!label) {
        return;
      }

      var labelText = label.querySelector('.components-base-control__label');
      if (!labelText) {
        return;
      }

      var text = (labelText.textContent || '').trim().toLowerCase();
      if (text.indexOf('css') === -1 && text.indexOf('class') === -1) {
        return;
      }

      input.setAttribute('data-wpi-autocomplete', '1');

      var datalistId = 'wpi-class-datalist-' + Math.random().toString(36).substr(2, 6);
      var datalist = document.createElement('datalist');
      datalist.id = datalistId;
      buildDatalist(datalist, cachedClasses, '');
      input.parentNode.appendChild(datalist);
      input.setAttribute('list', datalistId);

      input.addEventListener('input', function () {
        var terms = this.value.split(/\s+/);
        var current = terms.pop() || '';
        buildDatalist(datalist, cachedClasses, current, terms);
      });
    });
  }

  function buildDatalist(datalist, classes, current, prefix) {
    datalist.innerHTML = '';
    var prefixStr = (prefix && prefix.length > 0) ? prefix.join(' ') + ' ' : '';

    var filtered = current === ''
      ? classes.slice(0, 50)
      : classes.filter(function (cls) { return cls.indexOf(current) === 0; });

    filtered.slice(0, 50).forEach(function (cls) {
      var opt = document.createElement('option');
      opt.value = prefixStr + cls;
      datalist.appendChild(opt);
    });
  }
})();
