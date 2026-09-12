/* ============================================================
   tab_guard.js — require a fresh login in any tab/browser that
   didn't establish this session itself
   ------------------------------------------------------------
   At login, the login page generates a random token, stores it in
   this tab's sessionStorage, and submits it to the server, which
   remembers it for the session. Every authenticated page then embeds
   that same server-side token and compares it against this tab's
   sessionStorage copy.

   sessionStorage is per-tab and survives any number of reloads within
   the same tab, so a refresh always matches — no timing, no network
   beacon, nothing that can race. A brand-new tab (or a new browser
   window/process) starts with empty sessionStorage, so it never
   matches a real token and is sent to log in again.
   ============================================================ */
(function () {
  var script = document.currentScript;
  var serverToken = (script && script.getAttribute('data-token')) || '';
  var logoutUrl = script && script.getAttribute('data-logout-url');
  if (!logoutUrl) return;

  // Defaults to the original shared key for every existing caller
  // (admin/staff/teacher/login.php). Portals that can be logged into
  // together in the same tab (Student Portal + Student LMS) pass their
  // own distinct key so logging into one never clobbers the other's
  // token in sessionStorage.
  var storageKey = script.getAttribute('data-storage-key') || 'sg_tab_token';

  var localToken = sessionStorage.getItem(storageKey) || '';
  if (localToken !== serverToken) {
    window.location.href = logoutUrl;
  }
})();
