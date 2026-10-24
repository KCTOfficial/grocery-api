<?php
/**
 * @var callable $e
 * @var array<string, array{url: string, sri: string}> $cdn
 * @var string $assetUrl
 */
?>
<script src="<?= $e($cdn['jquery']['url']) ?>" integrity="<?= $e($cdn['jquery']['sri']) ?>" crossorigin="anonymous"></script>
<script src="<?= $e($cdn['bootstrap_js']['url']) ?>" integrity="<?= $e($cdn['bootstrap_js']['sri']) ?>" crossorigin="anonymous"></script>
<script src="<?= $e($cdn['highlightjs']['url']) ?>" integrity="<?= $e($cdn['highlightjs']['sri']) ?>" crossorigin="anonymous"></script>
<?php /* Falls back to assets/vendor when the CDN is unreachable, then starts admin.js. */ ?>
<script src="<?= $e($assetUrl) ?>/loader.js?v=<?= $e($assetVersion) ?>" data-app="1"></script>
</body>
</html>
