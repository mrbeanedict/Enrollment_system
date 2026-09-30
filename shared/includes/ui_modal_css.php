<?php
// Shared feedback skin: modals, toasts, alert banners, notification dropdowns.
//   assets/css/ui_modal.css  the skin (design source: shared/modal.php)
//   assets/js/ui_toast.js    window.uiToast + turns [data-ui-toast] banners into toasts
//   assets/js/ui_notif.js    Today / This week / Earlier / All tabs in the bell dropdowns
// Include this from any layout that loads SweetAlert2, renders a modal or shows
// post-action messages. It must come after the page's own stylesheets so the
// skin overrides them; the guard keeps it to one tag pair per page even when
// several shared includes ask for it. (File name predates the toast script.)
if (!defined('UI_MODAL_CSS_LOADED')) {
    define('UI_MODAL_CSS_LOADED', true);
    $uiModalCss = __DIR__ . '/../../assets/css/ui_modal.css';
    $uiToastJs  = __DIR__ . '/../../assets/js/ui_toast.js';
    $uiNotifJs  = __DIR__ . '/../../assets/js/ui_notif.js';
    ?>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/ui_modal.css?v=<?= is_file($uiModalCss) ? filemtime($uiModalCss) : 0 ?>">
<script src="<?= APP_URL ?>/assets/js/ui_toast.js?v=<?= is_file($uiToastJs) ? filemtime($uiToastJs) : 0 ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/ui_notif.js?v=<?= is_file($uiNotifJs) ? filemtime($uiNotifJs) : 0 ?>" defer></script>
<?php
}
