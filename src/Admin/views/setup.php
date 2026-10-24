<?php
/**
 * @var callable $e
 * @var string $title
 * @var array<string, mixed> $bootstrap
 */
include __DIR__ . '/head.php';
?>
<body class="gr-login-body" data-page="setup" data-gr="<?= $e(json_encode($bootstrap, JSON_UNESCAPED_SLASHES)) ?>">
<main class="gr-setup">
    <div class="gr-login-logo">
        <i class="fa-solid fa-basket-shopping"></i>
        <span>Grocery<strong>Restable</strong></span>
        <small class="text-muted ms-2">v<?= $e($bootstrap['version']) ?></small>
    </div>
    <h1 class="gr-login-title">Setup wizard</h1>

    <ol class="gr-steps" id="gr-steps">
        <li data-step="1" class="active"><span>1</span> Framework</li>
        <li data-step="2"><span>2</span> Database</li>
        <li data-step="3"><span>3</span> Admin account</li>
        <li data-step="4"><span>4</span> API options</li>
        <li data-step="5"><span>5</span> Done</li>
    </ol>

    <div class="gr-panel">
        <div class="gr-panel-body" id="gr-setup-body">
            <div class="text-center text-muted py-5"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>
        <div class="gr-panel-footer d-flex justify-content-between">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="gr-setup-back" disabled><i class="fa-solid fa-chevron-left me-1"></i> Back</button>
            <button type="button" class="btn btn-sm btn-primary" id="gr-setup-next">Next <i class="fa-solid fa-chevron-right ms-1"></i></button>
        </div>
    </div>
    <p class="gr-credit">Inspired by <a href="https://github.com/scoumbourdis/grocery-crud" target="_blank" rel="noopener noreferrer">Grocery CRUD</a>. Not affiliated with or endorsed by Grocery CRUD / John Skoumbourdis.</p>
</main>
<?php include __DIR__ . '/foot.php'; ?>
