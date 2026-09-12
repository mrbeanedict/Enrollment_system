<?php
// Transitional shim — real file now lives at shared/helpers/notify.php
// (see plan: provide-formal-plan-for-rippling-garden, Step 2 Phase A).
// Kept here so not-yet-migrated callers' '../notify.php' includes keep
// working unchanged; remove once Step 4 repoints every caller and Step 6
// deletes the old folders.
require_once __DIR__ . '/shared/helpers/notify.php';
