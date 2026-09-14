<?php
// Centralizes the session/role guard boilerplate that used to be
// hand-copied at the top of every roles/*.php page. Three real patterns
// exist across the app (see plan: provide-formal-plan-for-rippling-garden,
// Step 5) and this helper preserves all three rather than collapsing them
// into one behavior:
//
//   1. Early redirect (admin, most staff/department pages): not logged in
//      -> redirect; wrong role -> redirect. Both immediate, before output.
//   2. Two-stage (staff dashboard, teacher pages): wrong role (but SOME
//      role logged in) -> redirect immediately, since that page has
//      already started printing <head> by the time the "not logged in at
//      all" case is discovered further down (in an included sidebar, or
//      an inline template check) — a header() call after output has
//      started fails silently.
//   3. Inline conditional render (student portal, LMS pages): never
//      redirects at all — the page shows its own "You are not logged in"
//      message inline instead of a real redirect.
//
// $role: a single role string, an array of acceptable roles, or null to
// only check "some" logged-in session exists without checking the role.
//
// $onWrongRole: what to do when a session IS logged in but not as an
// allowed role — 'redirect' (default) sends to $redirectTo now; 'ignore'
// leaves that case for the caller to handle itself (used by the two-stage
// pages, which only want the wrong-role case redirected here and the
// not-logged-in case handled elsewhere).
//
// $onNotLoggedIn: what to do when no session is logged in at all —
// 'redirect' (default) sends to $redirectTo now; 'bool' redirects nothing
// and just returns false, so the caller can render its own inline message
// (the student/LMS pattern).
//
// Returns true if the session is logged in as an allowed role, false
// otherwise (only reachable when a 'bool' mode was requested for the
// case that applies).
function require_login(
    $role = null,
    ?string $redirectTo = null,
    string $onWrongRole = 'redirect',
    string $onNotLoggedIn = 'redirect'
): bool {
    $target = $redirectTo ?? (APP_URL . '/login');

    $loggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

    if (!$loggedIn) {
        if ($onNotLoggedIn === 'redirect') {
            header("Location: $target");
            exit();
        }
        return false;
    }

    if ($role !== null) {
        $roles = is_array($role) ? $role : [$role];
        if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
            if ($onWrongRole === 'redirect') {
                header("Location: $target");
                exit();
            }
            return false;
        }
    }

    return true;
}
