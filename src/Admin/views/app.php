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
    <aside class="gr-nav" id="gr-nav">
        <a class="gr-logo" href="#/home" title="Home">
            <i class="fa-solid fa-basket-shopping"></i>
            <span>Grocery<strong>Restable</strong></span>
        </a>
        <div class="gr-nav-icons">
            <a href="#/home" title="Home"><i class="fa-solid fa-house"></i></a>
            <a href="#" data-action="reload-tree" title="Refresh schema (detect new tables and columns)"><i class="fa-solid fa-rotate"></i></a>
            <a href="#/docs" title="API documentation"><i class="fa-solid fa-book"></i></a>
            <a href="#/console" title="API console"><i class="fa-solid fa-terminal"></i></a>
            <a href="#/settings" title="Settings"><i class="fa-solid fa-gear"></i></a>
            <a href="#" data-action="logout" title="Log out"><i class="fa-solid fa-right-from-bracket"></i></a>
        </div>
        <div class="gr-nav-filter">
            <i class="fa-solid fa-filter"></i>
            <input type="search" id="gr-tree-filter" class="form-control form-control-sm" placeholder="Filter tables" aria-label="Filter tables">
        </div>
        <div class="gr-tree" id="gr-tree"></div>
        <div class="gr-nav-legend small">
            <span class="gr-dot gr-dot-code"></span> code
            <span class="gr-dot gr-dot-gui"></span> GUI
            <span class="gr-dot gr-dot-auto"></span> auto
            <span class="gr-dot gr-dot-none"></span> not exposed
        </div>
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
            <div class="modal-body"></div>
            <div class="modal-footer py-2"></div>
        </div>
    </div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="gr-toasts"></div>
<form method="post" action="<?= $e($bootstrap['adminUrl']) ?>/logout" id="gr-logout-form" class="d-none">
    <input type="hidden" name="_csrf" value="<?= $e($bootstrap['csrf']) ?>">
</form>
<?php include __DIR__ . '/foot.php'; ?>
