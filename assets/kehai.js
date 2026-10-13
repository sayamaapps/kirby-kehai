/*!
 * Kehai — honest social proof for Kirby CMS. https://github.com/simsimmal/kirby-kehai
 *
 * Reads the [data-kehai] container printed by the kehai/bubble snippet,
 * waits data-kehai-delay seconds, loads the entries from this site's
 * /kehai/events.json and shows them one after the other: each for
 * data-kehai-duration seconds, data-kehai-gap seconds apart, at most
 * data-kehai-max, every entry once. Nothing loops, nothing is invented:
 * the entries and their times come from the server, the script only says
 * how long ago each one was.
 *
 * Closing ends the bubble for this page view. No cookie, nothing kept in
 * the browser: the next page view starts afresh. No inline style is set;
 * the look and the motion are in kehai.css (which respects
 * prefers-reduced-motion).
 */
(function () {
  'use strict';

  var box = document.querySelector('[data-kehai]');
  if (!box || typeof window.fetch !== 'function') {
    return;
  }
  var line = box.querySelector('.kehai-text');
  var close = box.querySelector('.kehai-close');
  var src = own(box.getAttribute('data-kehai-src'));
  if (!line || !close || !src) {
    return;
  }
  var delay = setting('delay', 8, 0, 600);
  var max = setting('max', 3, 1, 20);
  var duration = setting('duration', 6, 2, 60);
  var gap = setting('gap', 10, 0, 600);

  var closed = false;
  var timer = null;
  var texts = {};
  var clock = null; // function returning "now" in milliseconds

  /**
   * Only this site's own route: same host, http(s), a path ending in
   * /kehai/events.json. Injected markup with a data-kehai container must
   * not make the script fetch from elsewhere.
   */
  function own(value) {
    var url;
    try {
      url = new URL(value || '', window.location.href);
    } catch (e) {
      return null;
    }
    if ((url.protocol !== 'https:' && url.protocol !== 'http:') ||
        url.host !== window.location.host ||
        !/\/kehai\/events\.json$/.test(url.pathname)) {
      return null;
    }
    return url.href;
  }

  function setting(name, fallback, min, maxValue) {
    var value = parseInt(box.getAttribute('data-kehai-' + name), 10);
    return isNaN(value) ? fallback : Math.min(maxValue, Math.max(min, value));
  }

  /**
   * The visitor's clock, unless it is clearly wrong: then the server's
   * time (from the answer, at most a minute old because of caching) plus
   * the time since the answer arrived.
   */
  function makeClock(serverNow) {
    var server = Date.parse(serverNow);
    var received = Date.now();
    if (isNaN(server) || Math.abs(received - server) <= 3 * 60 * 1000) {
      return function () { return Date.now(); };
    }
    return function () { return server + (Date.now() - received); };
  }

  function text(key, count) {
    var pattern = typeof texts[key] === 'string' ? texts[key] : '';
    return pattern.replace('{count}', String(count));
  }

  /** "2 hours ago" from the real time of the entry. */
  function ago(at) {
    var seconds = Math.max(0, (clock() - Date.parse(at)) / 1000);
    if (seconds < 60) {
      return text('now');
    }
    if (seconds < 3600) {
      var minutes = Math.floor(seconds / 60);
      return minutes === 1 ? text('minute') : text('minutes', minutes);
    }
    if (seconds < 86400) {
      var hours = Math.round(seconds / 3600);
      return hours === 1 ? text('hour') : text('hours', hours);
    }
    var days = Math.round(seconds / 86400);
    return days === 1 ? text('day') : text('days', days);
  }

  /** Entries with a sentence and a valid time that is not in the future. */
  function entries(data) {
    var list = [];
    var events = data && Array.isArray(data.events) ? data.events : [];
    for (var i = 0; i < events.length && list.length < max; i++) {
      var event = events[i];
      var time = event ? Date.parse(event.at) : NaN;
      if (!event || typeof event.text !== 'string' || event.text === '' || isNaN(time) || time > clock() + 5 * 60 * 1000) {
        continue;
      }
      list.push({ text: event.text, at: event.at });
    }
    return list;
  }

  function hide() {
    box.classList.remove('kehai-open');
    close.hidden = true;
    line.textContent = '';
  }

  function show(list, index) {
    if (closed || index >= list.length) {
      hide();
      return;
    }
    line.textContent = list[index].text + ' · ' + ago(list[index].at);
    close.hidden = false;
    box.classList.add('kehai-open');
    timer = window.setTimeout(function () {
      hide();
      if (index + 1 < list.length) {
        timer = window.setTimeout(function () { show(list, index + 1); }, gap * 1000);
      }
    }, duration * 1000);
  }

  close.addEventListener('click', function () {
    closed = true;
    window.clearTimeout(timer);
    hide();
  });

  timer = window.setTimeout(function () {
    if (closed) {
      return;
    }
    window.fetch(src, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        texts = data && data.texts && typeof data.texts === 'object' ? data.texts : {};
        clock = makeClock(data && data.now);
        var list = entries(data);
        if (!closed && list.length) {
          show(list, 0);
        }
      })
      .catch(function () {
        // Nothing to show: the page stays as it is.
      });
  }, delay * 1000);
})();
