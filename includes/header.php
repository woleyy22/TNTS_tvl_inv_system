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
if ($user['role'] === 'tvl_head') { $visibleMenu[] = ['accounts.php','Accounts (View Only)','users',['tvl_head']]; }
if ($user['role'] === 'teacher') {
    $visibleMenu[] = ['teacher_expiry.php','My Batches & Expiry','box',['teacher']];
}
if (in_array($user['role'], ['supply_officer','tvl_head'], true)) {
    $visibleMenu[] = ['positions_offices.php','Positions & Offices','users',['supply_officer','tvl_head']];
}
// Phase 4.4: approval queue is available to either authorized reviewer.
if (in_array($user['role'], ['supply_officer','tvl_head'], true)) {
    $visibleMenu[] = ['approvals.php', 'Registration Approvals', 'users', ['supply_officer','tvl_head']];
    $visibleMenu[] = ['personnel_reviews.php', 'Personnel Changes', 'users', ['supply_officer','tvl_head']];
}

$menuGroups = [
    'stock' => ['Inventory & Stock', 'box', ['receiving.php', 'inventory.php', 'ics_issue.php', 'ics_returns.php', 'teacher_expiry.php']],
    'operations' => ['Operations & Tracking', 'repeat', ['item_maintenance.php', 'borrow.php', 'transfers.php', 'transfer_history.php', 'condemnation.php']],
    'records' => ['Reports & Records', 'file', ['reports.php', 'history.php', 'transfer_audit.php']],
    'administration' => ['Administration', 'users', ['courses.php', 'accounts.php', 'approvals.php', 'personnel_reviews.php','positions_offices.php']],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script defer src="<?= e(url('assets/js/ui.js')) ?>"></script>
    <script defer src="<?= e(url('assets/js/notifications.js')) ?>"></script>
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
                <?php if ($user['role'] === 'teacher' && $groupKey === 'stock'): ?>
                    <details class="nav-group p6ui-inventory-group<?= in_array($currentPage,['inventory.php','teacher_expiry.php'],true) ? ' has-active' : '' ?>" data-nav-group="stock" open>
                        <summary class="nav-group-toggle"><?= icon('box') ?><span class="nav-group-label">Inventory</span><span class="nav-chevron" aria-hidden="true">⌄</span></summary>
                        <div class="nav-submenu p6ui-inventory-menu">
                            <a class="nav-link p6ui-inventory-primary<?= $currentPage === 'inventory.php' && empty($_GET['category']) ? ' active':'' ?>" href="<?= e(url('inventory.php?view=issued')) ?>"><?= icon('box') ?><span>All My Inventory</span></a>
                            <?php $p6uiCategoryActive = $currentPage==='inventory.php' && !empty($_GET['category']); ?>
                            <details class="p6ui-category-group" <?= $p6uiCategoryActive?'open':'' ?>>
                                <summary class="p6ui-category-toggle"><span>Browse by category</span><span class="p6ui-chevron" aria-hidden="true">⌄</span></summary>
                                <div class="p6ui-category-links">
                                    <?php foreach ([['Equipment','equipment'],['Non-Consumable','non_consumable'],['Consumable','consumable'],['Expiring Material','expiring_material']] as $p6uiLink): ?>
                                        <a class="nav-link<?= $p6uiCategoryActive && ($_GET['category']??'')===$p6uiLink[1]?' active':'' ?>" href="<?= e(url('inventory.php?view=issued&category='.$p6uiLink[1])) ?>"><span><?= e($p6uiLink[0]) ?></span></a>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <a class="nav-link<?= $currentPage==='teacher_expiry.php'?' active':'' ?>" href="<?= e(url('teacher_expiry.php')) ?>"><?= icon('box') ?><span>Batches &amp; Expiry</span></a>
                        </div>
                    </details>
                    <?php continue; ?>
                <?php endif; ?>
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
            <div class="tn-notify-shell" id="tn-notify-shell" data-endpoint="<?= e(url('notification_api.php')) ?>" data-history="<?= e(url('notifications.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-user-id="<?= (int)$user['id'] ?>">
                <button type="button" id="tn-bell" class="tn-bell" aria-haspopup="dialog" aria-expanded="false" aria-controls="tn-notify-popover" aria-label="Notifications" title="Notifications">
                    <svg class="icon" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                    <span class="tn-bell-count" id="tn-bell-count" hidden>0</span>
                </button>
                <div class="tn-notify-popover" id="tn-notify-popover" hidden role="dialog" aria-label="Recent notifications">
                    <div class="tn-notify-popover-head"><div class="tn-notify-popover-title"><span class="tn-notify-brand-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg></span><strong>Notifications</strong></div><a href="<?= e(url('notifications.php')) ?>">View all</a></div>
                    <div id="tn-notify-preview" class="tn-notify-preview"><p class="muted">Loading…</p></div>
                    <div class="tn-notify-popover-foot"><button type="button" id="tn-sound-toggle" class="tn-sound-icon-button" aria-label="Mute notification sounds" aria-pressed="true" title="Sound on">
                            <svg class="tn-sound-icon-on" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="M15 9a4 4 0 0 1 0 6"/><path d="M18 6a8 8 0 0 1 0 12"/></svg>
                            <svg class="tn-sound-icon-off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="m17 9 5 6m0-6-5 6"/></svg>
                        </button><span id="tn-audio-status" class="tn-audio-status" role="status" aria-live="polite" hidden></span><button type="button" id="tn-popover-clear-read" class="tn-clear-read" title="Remove only notifications already marked as read">Clear read</button><button type="button" id="tn-popover-read-all">Mark all as read</button></div>
                </div>
            </div>
            <a class="workspace-user account-shortcut" href="<?= e(url('account.php')) ?>" title="Open My Account"><?= profile_avatar($headerProfile) ?><div><strong><?= e(profile_display_name($headerProfile)) ?></strong><small><?= e(role_label($user['role'])) ?></small></div></a>
        </header>
        <?php foreach (get_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>
