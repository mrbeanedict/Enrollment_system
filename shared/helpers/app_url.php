<?php
// Site-root URL prefix — lets shared includes and cross-folder redirects
// (e.g. shared/includes/*_sidebar.php, login/logout links) use an
// absolute path that works regardless of how deep the requesting page
// sits under roles/, instead of a relative '../../' chain that breaks
// every time a page moves during the directory reorganization.
//
// Derived from where the project actually sits under the web server's
// document root rather than hardcoded, so asset/image URLs keep working
// when the folder is renamed, served from a domain root, or opened from
// another device. An APP_URL entry in .env overrides the detection (use
// it behind a reverse proxy / tunnel that rewrites paths). Kept free of
// the DB connection so index.php and logout.php can load it cheaply.
require_once __DIR__ . '/env.php';
load_env();

if (!defined('APP_URL')) {
    $appUrl = $_ENV['APP_URL'] ?? '';

    if ($appUrl === '') {
        $appUrl  = '/Enrollment_system'; // fallback (CLI, unusual server setups)
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
        $appRoot = realpath(dirname(__DIR__, 2)) ?: '';
        if ($docRoot !== '' && $appRoot !== '' && stripos($appRoot, $docRoot) === 0) {
            $appUrl = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        }
    }

    define('APP_URL', rtrim($appUrl, '/'));
}
