<?php
/**
 * @var callable $e
 * @var string $title
 * @var string $action
 * @var string $csrf
 * @var string|null $error
 */
include __DIR__ . '/head.php';
?>
<body class="gr-login-body" data-page="login">
<main class="gr-login">
    <div class="gr-login-logo">
        <i class="fa-solid fa-basket-shopping"></i>
        <span>Grocery<strong>Restable</strong></span>
    </div>
    <h1 class="gr-login-title">Welcome to <?= $e($title) ?></h1>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger py-2 small" role="alert"><i class="fa-solid fa-circle-exclamation me-1"></i><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $e($action) ?>" class="gr-panel" autocomplete="on">
        <div class="gr-panel-title"><i class="fa-solid fa-right-to-bracket me-1"></i> Log in</div>
        <div class="gr-panel-body">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <div class="row mb-2 align-items-center">
                <label for="username" class="col-4 col-form-label col-form-label-sm text-end">Username:</label>
                <div class="col-8"><input type="text" class="form-control form-control-sm" id="username" name="username" required autofocus autocomplete="username"></div>
            </div>
            <div class="row mb-1 align-items-center">
                <label for="password" class="col-4 col-form-label col-form-label-sm text-end">Password:</label>
                <div class="col-8"><input type="password" class="form-control form-control-sm" id="password" name="password" required autocomplete="current-password"></div>
            </div>
        </div>
        <div class="gr-panel-footer text-end">
            <button type="submit" class="btn btn-sm btn-primary px-4">Log in</button>
        </div>
    </form>
    <p class="gr-credit">Inspired by <a href="https://github.com/scoumbourdis/grocery-crud" target="_blank" rel="noopener noreferrer">Grocery CRUD</a>. Not affiliated with or endorsed by Grocery CRUD / John Skoumbourdis.</p>
</main>
<script src="<?= $e($assetUrl) ?>/loader.js?v=<?= $e($assetVersion) ?>" data-app="0"></script>
</body>
</html>
