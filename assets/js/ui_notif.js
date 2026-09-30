/*
 * ui_notif.js — Today / This week / Earlier / All tabs for the notification
 * dropdowns. Markup comes from notif_render_list() in shared/helpers/notify.php;
 * each row carries data-group="today|week|earlier" (computed in SQL) and the
 * tab strip carries data-notif-tab. Styles: assets/css/ui_modal.css.
 *
 * Rows are all rendered server-side and only shown/hidden here, so with JS off
 * the dropdown simply lists everything.
 */
(function () {
  'use strict';

  function select(root, key) {
    var tabs = root.querySelectorAll('[data-notif-tab]');
    var label = '';
    for (var i = 0; i < tabs.length; i++) {
      var on = tabs[i].getAttribute('data-notif-tab') === key;
      tabs[i].classList.toggle('active', on);
      tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) label = tabs[i].getAttribute('data-empty') || '';
    }

    var rows = root.querySelectorAll('[data-group]');
    var shown = 0;
    for (var j = 0; j < rows.length; j++) {
      var show = key === 'all' || rows[j].getAttribute('data-group') === key;
      rows[j].hidden = !show;
      if (show) shown++;
    }

    var empty = root.querySelector('[data-notif-empty]');
    if (empty) {
      empty.textContent = label;
      empty.hidden = shown > 0;
    }
  }

  function init() {
    var roots = document.querySelectorAll('[data-notif-root]');
    for (var i = 0; i < roots.length; i++) {
      select(roots[i], roots[i].getAttribute('data-default-tab') || 'today');
    }
  }

  document.addEventListener('click', function (e) {
    var tab = e.target.closest ? e.target.closest('[data-notif-tab]') : null;
    if (!tab) return;
    var root = tab.closest('[data-notif-root]');
    if (root) select(root, tab.getAttribute('data-notif-tab'));
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
