/**
 * Query Loop Load More — frontend.
 *
 * Fetches the next page via AJAX, extracts posts from the matching query
 * region, and appends them to the current post-template container.
 * Supports both click-to-load and infinite-scroll (IntersectionObserver).
 */
(function () {
  'use strict';

  var loadStartEvent = new Event('wpiLoadMoreStart');
  var loadEndEvent   = new Event('wpiLoadMoreEnd');

  function buildUrl(param, value) {
    var url = new URL(window.location.href);
    if (param) {
      url.searchParams.set(param, value);
    }
    return url;
  }

  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        fetchPosts(entry.target);
      }
    });
  }, { threshold: 0.5, rootMargin: '0px' });

  function fetchPosts(target) {
    var button = target.closest('.wp-load-more__button');
    if (!button) return;

    var container = button.closest('.wp-block-query');
    if (
      button.classList.contains('loading') ||
      !container ||
      !button.dataset.queryUrl ||
      !button.dataset.queryNextPage
    ) {
      return;
    }

    var fetchUrl = buildUrl(button.dataset.queryUrl, button.dataset.queryNextPage);

    button.classList.add('loading');
    document.dispatchEvent(loadStartEvent);

    fetch(fetchUrl, {
      method: 'GET',
      headers: { 'Content-Type': 'text/html' }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('Network response was not ok.');
        return response.text();
      })
      .then(function (data) {
        var temp = document.createElement('div');
        temp.innerHTML = data;

        var region = container.dataset.wpiQueryRegion || '';
        var posts  = temp.querySelector(
          '.wp-block-query[data-wpi-query-region="' + region + '"] .wp-block-post-template'
        );

        var targetTpl = container.querySelector('.wp-block-post-template');
        if (targetTpl && posts) {
          targetTpl.insertAdjacentHTML('beforeend', posts.innerHTML);
        }

        var wrapper = button.closest('.wp-block-button');
        if (wrapper) {
          wrapper.classList.remove('loading');
        }

        var nextPage = +button.dataset.queryNextPage;
        var maxPage  = +button.dataset.queryMaxPage;

        if (button.dataset.updateUrl === 'true') {
          window.history.pushState({}, '', buildUrl(button.dataset.queryUrl, nextPage));
        }

        if (nextPage >= maxPage) {
          if (button.classList.contains('wp-load-more__infinite-scroll')) {
            observer.unobserve(button);
          }
          var btnWrap = button.closest('.wp-block-buttons');
          if (btnWrap) btnWrap.remove();
          return;
        }

        if (nextPage < maxPage) {
          button.dataset.queryNextPage = nextPage + 1;
          button.href = buildUrl(button.dataset.queryUrl, button.dataset.queryNextPage).toString();
        }
      })
      .catch(function (error) {
        console.error('WPI Load More fetch error:', error);
      })
      .finally(function () {
        button.classList.remove('loading');
        document.dispatchEvent(loadEndEvent);

        if (button.classList.contains('wp-load-more__infinite-scroll')) {
          var bcr = button.getBoundingClientRect();
          if (bcr.bottom > 0 && bcr.top < window.innerHeight) {
            observer.unobserve(button);
            observer.observe(button);
          }
        }
      });
  }

  wp.domReady(function () {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.wp-load-more__button:not(.wp-load-more__infinite-scroll)');
      if (!btn) return;
      e.preventDefault();
      fetchPosts(btn);
    });

    document.querySelectorAll('.wp-load-more__infinite-scroll').forEach(function (el) {
      observer.observe(el);
    });
  });
})();
