<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Must be logged in
$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Block teachers from accessing this module
if ($user['role'] === 'teacher') {
    header('Location: dashboard.php');
    exit;
}

$action = $_GET['action'] ?? 'list';
if ($user['role'] === 'tvl_head' && $action !== 'list') { http_response_code(403); exit('TVL Head access is view-only.'); }

// Process new delivery form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    if ($user['role'] !== 'supply_officer') {
        die("Only the Supply Officer can add deliveries.");
    }

    $po_number = trim($_POST['po_number'] ?? '');
    $dr_number = trim($_POST['dr_number'] ?? '');
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $date_received = trim($_POST['date_received'] ?? date('Y-m-d'));
    
    // Safely grab the user ID whether your login script called it 'id' or 'user_id'
    $active_user_id = $user['id'] ?? $user['user_id'];

    if (empty($supplier_name) || empty($date_received)) {
        die("Supplier Name and Date Received are required.");
    }

    // Permanent document number, reserved transactionally for the actual received date.
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_received);
    if (!$date || $date->format('Y-m-d') !== $date_received) {
        die('Invalid Date Received.');
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $year = (int)$date->format('Y');
        $seed = $pdo->prepare('INSERT INTO receiving_number_sequences (receive_year, last_number) VALUES (?,0) ON DUPLICATE KEY UPDATE receive_year=receive_year');
        $seed->execute([$year]);
        $lock = $pdo->prepare('SELECT last_number FROM receiving_number_sequences WHERE receive_year=? FOR UPDATE');
        $lock->execute([$year]);
        $next = (int)$lock->fetchColumn() + 1;
        $number = sprintf('REC-%04d-%03d', $year, $next);
        $pdo->prepare('UPDATE receiving_number_sequences SET last_number=? WHERE receive_year=?')->execute([$next,$year]);
        $stmt = $pdo->prepare("INSERT INTO deliveries (receiving_number, po_number, dr_number, supplier_name, date_received, received_by, status)
                               VALUES (?, ?, ?, ?, ?, ?, 'pending_inspection')");
        $stmt->execute([$number, $po_number, $dr_number, $supplier_name, $date_received, $active_user_id]);
        $delivery_id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Created Delivery', 'Receiving', ?, ?)")
            ->execute([$active_user_id, $delivery_id, 'Receiving '.$number.' from '.$supplier_name]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Receiving create failed: '.$error->getMessage());
        http_response_code(500);
        die('Could not save delivery. Check that the receiving database tables are installed and try again.');
    }

    // Redirect to the view page where they will add individual items to this delivery
    header("Location: delivery_view.php?id=" . $delivery_id);
    exit;
}

// Set up page layout variables
$pageTitle = $action === 'add' ? 'New Delivery' : 'Receiving & Deliveries';
$active = 'receiving'; 
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header">
    <h2><?= e($pageTitle) ?></h2>
    <?php if ($action === 'list' && $user['role'] === 'supply_officer'): ?>
        <a href="?action=add" class="btn btn-primary legacy-053">+ Log New Delivery</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
    <div class="card legacy-054 tnts-table-card" data-tnts-grid>
        <div class="tnts-grid-toolbar">
            <div class="tnts-grid-title"><strong>Receiving records</strong><small data-grid-count aria-live="polite"></small></div>
            <div class="tnts-grid-filters">
                <label class="tnts-grid-search">Search
                    <input type="search" data-grid-filter="search" placeholder="Record, supplier, PO or DR" autocomplete="off">
                </label>
                <label class="tnts-grid-short">Status
                    <select data-grid-filter="status">
                        <option value="">All statuses</option>
                        <option value="pending_inspection">Pending inspection</option>
                        <option value="partially_accepted">Partially accepted</option>
                        <option value="accepted">Accepted</option>
                    </select>
                </label>
                <label class="tnts-grid-date">Received from<input type="date" data-grid-filter="from"></label>
                <label class="tnts-grid-date">Received through<input type="date" data-grid-filter="to"></label>
                <label class="tnts-grid-short">Show
                    <select data-grid-filter="size"><option value="10">10 rows</option><option value="25">25 rows</option><option value="50">50 rows</option><option value="all">All rows</option></select>
                </label>
            </div>
        </div>
        <div class="tnts-grid-scroll">
        <table class="tnts-data-table" data-grid-table aria-label="Receiving and deliveries">
            <thead><tr>
                <th style="width:18%"><button type="button" class="tnts-grid-sort" data-grid-sort="record" data-grid-type="text" data-sort-direction="none">Record No. <span class="tnts-grid-indicator" data-grid-indicator aria-hidden="true">–</span></button></th>
                <th style="width:23%"><button type="button" class="tnts-grid-sort" data-grid-sort="supplier" data-grid-type="text" data-sort-direction="none">Supplier <span class="tnts-grid-indicator" data-grid-indicator aria-hidden="true">–</span></button></th>
                <th style="width:15%"><button type="button" class="tnts-grid-sort" data-grid-sort="date" data-grid-type="date" data-sort-direction="none">Received <span class="tnts-grid-indicator" data-grid-indicator aria-hidden="true">–</span></button></th>
                <th style="width:17%"><button type="button" class="tnts-grid-sort" data-grid-sort="reference" data-grid-type="text" data-sort-direction="none">PO / DR <span class="tnts-grid-indicator" data-grid-indicator aria-hidden="true">–</span></button></th>
                <th style="width:15%"><button type="button" class="tnts-grid-sort" data-grid-sort="status" data-grid-type="text" data-sort-direction="none">Status <span class="tnts-grid-indicator" data-grid-indicator aria-hidden="true">–</span></button></th>
                <th style="width:12%">Details</th>
            </tr></thead>
            <tbody>
            <?php
            // Existing receiving query and navigation remain unchanged.
            $stmt=db()->query("SELECT d.*,u.full_name AS receiver_name FROM deliveries d JOIN users u ON d.received_by=u.user_id ORDER BY d.created_at DESC,d.delivery_id DESC");
            $deliveries=$stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <?php foreach($deliveries as $d): ?>
                <tr data-grid-record
                    data-record="<?= e($d['receiving_number']) ?>"
                    data-supplier="<?= e($d['supplier_name']??'') ?>"
                    data-date="<?= e($d['date_received']) ?>"
                    data-reference="<?= e(trim(($d['po_number']??'').' '.($d['dr_number']??''))) ?>"
                    data-status="<?= e($d['status']) ?>"
                    data-search="<?= e(implode(' ',[$d['receiving_number'],$d['supplier_name']??'',$d['po_number']??'',$d['dr_number']??'',$d['receiver_name']??''])) ?>">
                    <td><span class="tnts-grid-main"><?= e($d['receiving_number']) ?></span></td>
                    <td><span class="tnts-grid-main"><?= e($d['supplier_name']?:'–') ?></span></td>
                    <td><?= e(date('M d, Y',strtotime($d['date_received']))) ?></td>
                    <td><div class="tnts-grid-stack"><span class="tnts-grid-muted">PO: <?= e($d['po_number']?:'–') ?></span><span class="tnts-grid-muted">DR: <?= e($d['dr_number']?:'–') ?></span></div></td>
                    <td><span class="tnts-grid-pill tnts-grid-pill--<?= e($d['status']) ?>"><?= e(ucwords(str_replace('_',' ',$d['status']))) ?></span></td>
                    <td><a class="tnts-grid-link" href="delivery_view.php?id=<?= (int)$d['delivery_id'] ?>">View / Inspect →</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if(!$deliveries): ?><tr><td colspan="6" class="tnts-grid-empty">No deliveries logged yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <div class="tnts-grid-pager">
            <span data-grid-page-info class="tnts-grid-muted" aria-live="polite"></span>
            <div class="tnts-grid-pager-actions"><button type="button" data-grid-prev>← Previous</button><button type="button" data-grid-next>Next →</button></div>
        </div>
    </div>
    <script defer src="<?= e(url('assets/js/table_ui.js')) ?>"></script>

<?php elseif ($action === 'add' && $user['role'] === 'supply_officer'): ?>
    <div class="card legacy-058">
        <form method="POST" action="?action=add">
            <div class="legacy-045">
                <label class="legacy-059">Supplier Name *</label>
                <input type="text" name="supplier_name" required class="legacy-047">
            </div>
            
            <div class="legacy-045">
                <label class="legacy-059">Date Received *</label>
                <input type="date" name="date_received" value="<?= date('Y-m-d') ?>" required class="legacy-047">
            </div>

            <div class="legacy-060">
                <div class="legacy-049">
                    <label class="legacy-059">PO Number (Optional)</label>
                    <input type="text" name="po_number" placeholder="e.g. PO-2026-001" class="legacy-047">
                </div>
                <div class="legacy-049">
                    <label class="legacy-059">DR Number (Optional)</label>
                    <input type="text" name="dr_number" placeholder="Delivery Receipt #" class="legacy-047">
                </div>
            </div>

            <div class="legacy-061">
                <button type="submit" class="legacy-062">Save & Add Items</button>
                <a href="receiving.php" class="legacy-063">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>