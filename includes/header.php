<?php
// Page layout (top part). Set $pageTitle and $active before including this file.
$user = current_user();
$pageTitle = isset($pageTitle) ? $pageTitle : APP_NAME;
$active = isset($active) ? $active : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-name">TNTS</div>
            <div class="brand-sub">Inventory System</div>
        </div>
        <nav class="nav">
            <?php foreach (nav_items($user['role']) as $item): ?>
                <a href="<?= e(url($item[0])) ?>" class="nav-link<?= $active === $item[0] ? ' active' : '' ?>">
                    <?= icon($item[2]) ?><span><?= e($item[1]) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <div class="who">
                <strong><?= e($user['name']) ?></strong>
                <span><?= e(role_label($user['role'])) ?></span>
            </div>
            <a class="nav-link" href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>

    <main class="main">
        <button type="button" class="menu-btn" id="menuBtn" aria-label="Open menu"><?= icon('menu', 22) ?></button>
        <?php foreach (get_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>
