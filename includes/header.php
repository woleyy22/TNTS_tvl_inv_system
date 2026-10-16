<?php
// Page layout (top part). Set $pageTitle and $active before including this file.
$user = current_user();
require_once __DIR__ . '/profile.php';
$headerProfile = ['full_name' => $user['name'], 'suffix' => '', 'photo_version' => null];
try {
    $headerProfile = profile_read((int)$user['id']);
    $_SESSION['user']['name'] = $headerProfile['full_name'];
    $user['name'] = $headerProfile['full_name'];
} catch (PDOException $ex) {
    // The existing workspace remains usable while the profile table is being installed.
}
$pageTitle = isset($pageTitle) ? $pageTitle : APP_NAME;
$active = isset($active) ? $active : '';
$currentPage = basename($_SERVER['SCRIPT_NAME']);
// Keep the parent module highlighted while viewing delivery/inspection details.
$parentPages = ['delivery_view.php' => 'receiving.php', 'inspection.php' => 'receiving.php'];
if (isset($parentPages[$currentPage])) { $currentPage = $parentPages[$currentPage]; }
$visibleMenu = nav_items($user['role']);
$menuGroups = [
    'stock' => ['Inventory & Stock', 'box', ['receiving.php', 'inventory.php', 'ics_issue.php']],
    'operations' => ['Operations & Tracking', 'repeat', ['item_maintenance.php', 'borrow.php', 'transfers.php', 'condemnation.php']],
    'records' => ['Reports & Records', 'file', ['reports.php', 'history.php']],
    'administration' => ['Administration', 'users', ['courses.php', 'accounts.php']],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script defer src="<?= e(url('assets/js/ui.js')) ?>"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="app-page desktop-workspace">
<a class="skip-link" href="#pageContent">Skip to content</a>
<div class="app">
    <aside class="sidebar" id="sidebar" aria-label="Workspace menu" data-menu-role="<?= e($user['role']) ?>">

        <a class="brand" href="<?= e(url('dashboard.php')) ?>">
            <?= school_logo() ?>
            <div><div class="brand-name">TNTS</div><div class="brand-sub">TVL / TechPro Inventory</div></div>
        </a>
        <div class="nav-caption">Workspace</div>
        <nav class="nav" aria-label="Main navigation">
            <a href="<?= e(url('dashboard.php')) ?>" title="Dashboard" class="nav-link<?= $currentPage === 'dashboard.php' ? ' active' : '' ?>" <?= $currentPage === 'dashboard.php' ? 'aria-current="page"' : '' ?>>
                <?= icon('home') ?><span>Dashboard</span>
            </a>
            <?php foreach ($menuGroups as $groupKey => $group): ?>
                <?php
                $groupItems = array_values(array_filter($visibleMenu, function ($item) use ($group) {
                    return in_array($item[0], $group[2], true);
                }));
                if (!$groupItems) { continue; }
                $groupActive = in_array($currentPage, $group[2], true);
                ?>
                <?php if ($user['role'] === 'teacher' && in_array($groupKey, ['stock', 'records'], true)): ?>
                    <?php foreach ($groupItems as $item): $itemActive = $currentPage === $item[0] || $active === $item[0]; ?>
                        <a href="<?= e(url($item[0])) ?>" title="<?= e($item[1]) ?>" class="nav-link<?= $itemActive ? ' active' : '' ?>" <?= $itemActive ? 'aria-current="page"' : '' ?>><?= icon($item[2]) ?><span><?= e($item[1]) ?></span></a>
                    <?php endforeach; ?>
                    <?php continue; ?>
                <?php endif; ?>
                <details class="nav-group<?= $groupActive ? ' has-active' : '' ?>" data-nav-group="<?= e($groupKey) ?>" <?= $groupActive || $groupKey === 'stock' ? 'open' : '' ?>>
                    <summary class="nav-group-toggle" title="<?= e($group[0]) ?>">
                        <?= icon($group[1]) ?><span class="nav-group-label"><?= e($group[0]) ?></span><span class="nav-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="nav-submenu">
                        <?php foreach ($groupItems as $item): ?>
                            <?php $itemActive = $currentPage === $item[0] || $active === $item[0]; ?>
                            <a href="<?= e(url($item[0])) ?>" title="<?= e($item[1]) ?>" class="nav-link<?= $itemActive ? ' active' : '' ?>" <?= $itemActive ? 'aria-current="page"' : '' ?>>
                                <?= icon($item[2]) ?><span><?= e($item[1]) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <a class="nav-link<?= $currentPage === 'account.php' ? ' active' : '' ?>" href="<?= e(url('account.php')) ?>" title="My Account" <?= $currentPage === 'account.php' ? 'aria-current="page"' : '' ?>><?= icon('users') ?><span>My Account</span></a>
            <div class="who">
                <strong><?= e(profile_display_name($headerProfile)) ?></strong>
                <span><?= e(role_label($user['role'])) ?></span>
            </div>
            <a class="nav-link" href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>

    
    <main class="main" id="pageContent" tabindex="-1">
        <header class="workspace-header">
            <div class="workspace-heading"><button type="button" class="menu-btn workspace-toggle" id="menuBtn" aria-label="Collapse sidebar" aria-controls="sidebar" aria-expanded="true" title="Collapse sidebar"><?= icon('menu', 22) ?></button><div class="school-heading"><span class="eyebrow">Tanza National Trade School</span><span>Inventory Management System</span></div></div>
            <a class="workspace-user account-shortcut" href="<?= e(url('account.php')) ?>" title="Open My Account"><?= profile_avatar($headerProfile) ?><div><strong><?= e(profile_display_name($headerProfile)) ?></strong><small><?= e(role_label($user['role'])) ?></small></div></a>
        </header>
        <?php foreach (get_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>
