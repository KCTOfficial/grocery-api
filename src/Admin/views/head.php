<?php
/**
 * @var callable $e
 * @var array<string, array{url: string, sri: string}> $cdn
 * @var string $title
 * @var string $assetUrl
 */
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $e($assetUrl) ?>/favicon.svg">
    <link rel="stylesheet" href="<?= $e($cdn['bootstrap_css']['url']) ?>" integrity="<?= $e($cdn['bootstrap_css']['sri']) ?>" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $e($cdn['fontawesome']['url']) ?>" integrity="<?= $e($cdn['fontawesome']['sri']) ?>" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $e($cdn['highlightjs_css']['url']) ?>" integrity="<?= $e($cdn['highlightjs_css']['sri']) ?>" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $e($assetUrl) ?>/admin.css?v=<?= $e($assetVersion) ?>">
</head>
