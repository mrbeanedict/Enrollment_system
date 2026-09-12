<?php
// Central entry point for the reorganized roles/ tree. Defines BASE_PATH so
// includes no longer depend on how deep the including file sits in roles/.
// During the migration (see plan: provide-formal-plan-for-rippling-garden),
// this still requires the not-yet-moved config.php/notify.php at their
// current root locations; each gets swapped to shared/... as it moves.

define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/notify.php';
