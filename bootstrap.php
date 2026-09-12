<?php
// Central entry point for the reorganized roles/ tree. Defines BASE_PATH so
// includes no longer depend on how deep the including file sits in roles/.
// See plan: provide-formal-plan-for-rippling-garden.

define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/shared/config/config.php';
require_once BASE_PATH . '/shared/helpers/notify.php';
