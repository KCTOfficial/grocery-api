<?php
/**
 * @var callable $e
 * @var string $title
 * @var array<string, mixed> $bootstrap
 */
include __DIR__ . '/head.php';
?>
<body class="gr-body" data-page="app" data-gr="<?= $e(json_encode($bootstrap, JSON_UNESCAPED_SLASHES)) ?>">
<div class="gr-layout">
    <?php /* Section rail: Data (browse tables) · API (endpoint configuration) · Storage · Settings (advanced). */ ?>
    <nav class="gr-rail" aria-label="Sections">
        <a class="gr-rail-logo" href="#/home" title="<?= $e($title) ?>"><i class="fa-solid fa-basket-shopping"></i></a>
        <a href="#/home" data-section="home" title="Overview and getting started"><i class="fa-solid fa-gauge"></i><span>Overview</span></a>
        <a href="#/data" data-section="data" title="Data: browse and edit tables"><i class="fa-solid fa-table"></i><span>Data</span></a>
        <a href="#/api" data-section="api" title="API: endpoints, permissions, keys, docs and console"><i class="fa-solid fa-plug"></i><span>API</span></a>
        <a href="#/storage" data-section="storage" title="Storage buckets and files"><i class="fa-solid fa-box-archive"></i><span>Storage</span></a>
        <a href="#/logs" data-section="logs" title="Logs: API requests and admin actions"><i class="fa-solid fa-list-ul"></i><span>Logs</span></a>
        <a href="#/settings" data-section="settings" title="Settings: authentication, rate limiting, CORS, uploads, logging, connections, admins"><i class="fa-solid fa-gear"></i><span>Settings</span></a>
        <div class="gr-rail-bottom">
            <a href="#/settings/account" title="My account (<?= $e($bootstrap['user']) ?>): username and password"><i class="fa-solid fa-circle-user"></i><span>Account</span></a>
            <a href="#" data-action="logout" title="Log out (<?= $e($bootstrap['user']) ?>)"><i class="fa-solid fa-right-from-bracket"></i><span>Log out</span></a>
        </div>
    </nav>

    <aside class="gr-nav" id="gr-nav">
        <div class="gr-nav-head" id="gr-nav-head"></div>
        <div class="gr-nav-body" id="gr-nav-body"></div>
        <div class="gr-nav-foot" id="gr-nav-foot"></div>
    </aside>

    <main class="gr-main">
        <nav class="gr-breadcrumb" id="gr-breadcrumb" aria-label="breadcrumb"></nav>
        <ul class="gr-tabs" id="gr-tabs" role="tablist"></ul>
        <div id="gr-status"></div>
        <div class="gr-content" id="gr-content">
            <div class="text-center text-muted py-5"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>
    </main>
</div>

<div class="modal fade" id="gr-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fs-6"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body gr-overlay-body"></div>
            <div class="modal-footer py-2"></div>
        </div>
    </div>
</div>
<div class="offcanvas offcanvas-end gr-drawer" tabindex="-1" id="gr-drawer" aria-labelledby="gr-drawer-title">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title fs-6" id="gr-drawer-title"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body gr-overlay-body"></div>
    <div class="gr-drawer-footer"></div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="gr-toasts"></div>
<form method="post" action="<?= $e($bootstrap['adminUrl']) ?>/logout" id="gr-logout-form" class="d-none">
    <input type="hidden" name="_csrf" value="<?= $e($bootstrap['csrf']) ?>">
</form>
<?php include __DIR__ . '/foot.php'; ?>
