<?php
// ---------- small helpers ----------

// Escape text before printing it in HTML
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

// One-time messages shown at the top of the next page
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function get_flashes(): array
{
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $f;
}

function role_label(string $role): string
{
    $labels = [
        'supply_officer' => 'Supply Officer',
        'tvl_head'       => 'TVL Head',
        'teacher'        => 'Teacher',
    ];
    return isset($labels[$role]) ? $labels[$role] : $role;
}

function fmt_datetime(?string $ts): string
{
    return $ts ? date('M d, Y g:i A', strtotime($ts)) : '';
}

// ---------- form protection (CSRF) ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    $real = isset($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
    if ($real === '' || !hash_equals($real, $sent)) {
        http_response_code(400);
        die('Invalid form token. Go back, refresh the page and try again.');
    }
}

// ---------- history log ----------
function log_action(?int $userId, string $action, string $module, ?int $recordId = null, ?string $details = null): void
{
    try {
        $st = db()->prepare(
            'INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?,?,?,?,?)'
        );
        $st->execute([$userId, $action, $module, $recordId, $details]);
    } catch (Throwable $ex) {
        // logging must never break the page
    }
}

// ---------- sidebar menu ----------
// [file, label, icon, roles allowed]
function nav_items(string $role): array
{
    $all = [
        ['dashboard.php', 'Dashboard',    'home',   ['supply_officer', 'tvl_head', 'teacher']],
        ['receiving.php', 'Receiving',    'truck',  ['supply_officer', 'tvl_head']],
        ['inventory.php', 'Inventory',    'box',    ['supply_officer', 'tvl_head', 'teacher']],
        ['ics_issue.php', 'Issue ICS',    'file',   ['supply_officer']],
        ['item_maintenance.php', 'Maintenance', 'file', ['teacher']],
        ['borrow.php', 'Borrow & Return', 'clip',   ['supply_officer', 'tvl_head', 'teacher']],
        ['condemnation.php', 'Condemnation', 'alert', ['supply_officer', 'tvl_head', 'teacher']],
        ['transfers.php', 'Property Transfers', 'repeat', ['supply_officer', 'tvl_head']], // <-- ADDED HERE
        ['reports.php', 'Reports & Docs', 'file',   ['supply_officer', 'tvl_head', 'teacher']],
        ['courses.php',   'Courses',      'book',   ['supply_officer', 'tvl_head']],
        ['history.php',   'History Logs', 'clock',  ['supply_officer', 'tvl_head']],
        ['accounts.php',  'Accounts',     'users',  ['supply_officer']],
    ];
    $out = [];
    foreach ($all as $item) {
        if (in_array($role, $item[3], true)) {
            $out[] = $item;
        }
    }
    return $out;
}

// ---------- icons (simple line icons) ----------
function icon(string $name, int $size = 20): string
{
    $paths = [
        'home'    => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'box'     => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
        'users'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'clock'   => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'book'    => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'logout'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'alert'   => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'check'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'clip'    => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
        'truck'   => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'file'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'cap'     => '<path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/>',
        'menu'    => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'repeat'  => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>', // <-- SVG PATH ADDED HERE
    ];
    $p = isset($paths[$name]) ? $paths[$name] : '';
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $p . '</svg>';
}