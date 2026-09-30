/*
 * ui_toast.js — toast & snackbar helper. Styles live in assets/css/ui_modal.css
 * (.ui-toast*), loaded together with this file by shared/includes/ui_modal_css.php.
 *
 *   uiToast({ type: 'success', title: 'Saved', message: 'Record updated.' })
 *   uiToast.error('Could not reach the server.')
 *   uiToast({ type: 'info', message: 'Section archived.',
 *             action: { label: 'Undo', onClick: function () { ... } } })   // snackbar
 *
 * Server-rendered messages opt in by adding data-ui-toast to the banner:
 *   <div class="notice notice-success" data-ui-toast>Saved.</div>
 * On load the banner is removed and shown as a toast instead. If this script
 * never runs, the banner simply stays visible inline.
 */
(function () {
  'use strict';

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // duration 0 = sticky until dismissed (errors/warnings must not vanish unread)
  var TYPES = {
    success: { title: 'Success',              duration: 5000 },
    info:    { title: 'Heads up',             duration: 6000 },
    error:   { title: 'Something went wrong', duration: 0 },
    warning: { title: 'Please note',          duration: 0 }
  };
  var MAX_VISIBLE = 4;

  var stack = null;

  function getStack() {
    if (stack && document.body.contains(stack)) return stack;
    stack = document.createElement('div');
    stack.className = 'ui-toast-stack';
    stack.setAttribute('role', 'region');
    stack.setAttribute('aria-label', 'Notifications');
    document.body.appendChild(stack);
    return stack;
  }

  function node(tag, className, text) {
    var n = document.createElement(tag);
    if (className) n.className = className;
    if (text != null) n.textContent = text;   // textContent: message text is never parsed as HTML
    return n;
  }

  function dismiss(toast) {
    if (toast._gone) return;
    toast._gone = true;
    clearTimeout(toast._timer);
    toast.classList.add('is-leaving');
    setTimeout(function () {
      toast.remove();
      if (stack && !stack.children.length) { stack.remove(); stack = null; }
    }, reduced ? 0 : 250);
  }

  function armTimer(toast, ms) {
    toast._remaining = ms;
    toast._started = Date.now();
    toast._timer = setTimeout(function () { dismiss(toast); }, ms);
  }

  function show(opts) {
    opts = opts || {};
    var type = TYPES[opts.type] ? opts.type : 'info';
    var def = TYPES[type];
    var duration = opts.duration != null ? opts.duration : def.duration;

    var toast = node('div', 'ui-toast ui-toast--' + type);
    toast.setAttribute('role', type === 'error' || type === 'warning' ? 'alert' : 'status');

    toast.appendChild(node('div', 'ui-toast-icon'));

    var body = node('div', 'ui-toast-body');
    body.appendChild(node('p', 'ui-toast-title', opts.title || def.title));
    if (opts.message) body.appendChild(node('p', 'ui-toast-sub', opts.message));

    if (opts.action && opts.action.label) {
      var actions = node('div', 'ui-toast-actions');
      var action;
      if (opts.action.href) {
        action = node('a', 'ui-toast-action', opts.action.label);
        action.href = opts.action.href;
      } else {
        action = node('button', 'ui-toast-action', opts.action.label);
        action.type = 'button';
        action.addEventListener('click', function () {
          if (typeof opts.action.onClick === 'function') opts.action.onClick();
          dismiss(toast);
        });
      }
      actions.appendChild(action);
      body.appendChild(actions);
    }
    toast.appendChild(body);

    var close = node('button', 'ui-toast-close');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
      '<path d="M5 5 19 19M19 5 5 19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    close.addEventListener('click', function () { dismiss(toast); });
    toast.appendChild(close);

    var host = getStack();
    // Cap the stack: dismiss the oldest live toasts so a burst of messages
    // can't fill the screen. Leaving toasts stay in the DOM until their exit
    // animation ends, so count only the ones not already on their way out.
    var live = Array.prototype.filter.call(host.children, function (t) { return !t._gone; });
    for (var i = 0; live.length - i >= MAX_VISIBLE; i++) dismiss(live[i]);
    host.appendChild(toast);

    if (duration > 0) {
      var bar = node('div', 'ui-toast-progress');
      bar.style.setProperty('--ui-toast-ms', duration + 'ms');
      toast.appendChild(bar);
      armTimer(toast, duration);

      // Pause the countdown (bar pauses via CSS) while hovered or focused
      var pause = function () {
        if (toast._gone || toast._paused) return;
        toast._paused = true;
        clearTimeout(toast._timer);
        toast._remaining -= Date.now() - toast._started;
      };
      var resume = function () {
        if (toast._gone || !toast._paused) return;
        toast._paused = false;
        armTimer(toast, Math.max(toast._remaining, 800));
      };
      toast.addEventListener('mouseenter', pause);
      toast.addEventListener('mouseleave', resume);
      toast.addEventListener('focusin', pause);
      toast.addEventListener('focusout', resume);
    }

    return toast;
  }

  function typeOf(el) {
    var m = /(?:notice|alert|flash)-(success|error|info|warning)/.exec(el.className);
    return m ? m[1] : 'info';
  }

  function upgradeBanners() {
    var banners = document.querySelectorAll('[data-ui-toast]');
    for (var i = 0; i < banners.length; i++) {
      var el = banners[i];
      var message = (el.textContent || '').replace(/\s+/g, ' ').trim();
      var type = typeOf(el);
      el.parentNode.removeChild(el);
      if (message) show({ type: type, message: message });
    }
  }

  window.uiToast = show;
  Object.keys(TYPES).forEach(function (t) {
    show[t] = function (message, opts) {
      opts = opts || {};
      opts.type = t;
      opts.message = message;
      return show(opts);
    };
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', upgradeBanners);
  } else {
    upgradeBanners();
  }
})();
