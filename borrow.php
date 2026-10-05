<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$role = $user['role'];
$uid = (int)($user['user_id'] ?? $user['id']);

// Handle borrowing item submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['borrow_item'])) {
    if ($role !== 'teacher') {
        die("Only teachers can lend/borrow items.");
    }
    
    $ics_item_id = (int)$_POST['ics_item_id'];
    $borrower_id = (int)$_POST['borrower_id'];
    $quantity = (int)$_POST['quantity'];
    $program_purpose = trim($_POST['program_purpose']);
    $date_borrowed = $_POST['date_borrowed'] ?? date('Y-m-d');

    if (!$ics_item_id || !$borrower_id || $quantity <= 0 || empty($program_purpose)) {
        die("All fields are required and quantity must be greater than zero.");
    }

    if ($borrower_id === $uid) {
        die("You cannot borrow an item to yourself.");
    }

    // Verify item belongs to logged-in teacher's active ICS
    $check = db()->prepare("
        SELECT ii.qty_issued, 
               COALESCE((SELECT SUM(quantity) FROM borrow_logs WHERE ics_item_id = ii.ics_item_id AND status = 'borrowed'), 0) as already_borrowed
        FROM ics_items ii
        JOIN ics c ON ii.ics_id = c.ics_id
        WHERE ii.ics_item_id = ? AND c.teacher_id = ? AND c.status = 'active'
    ");
    $check->execute([$ics_item_id, $uid]);
    $itemData = $check->fetch();

    if (!$itemData) {
        die("Invalid item selection or you are not the accountable holder.");
    }

    $available_to_lend = $itemData['qty_issued'] - $itemData['already_borrowed'];
    if ($quantity > $available_to_lend) {
        die("Cannot lend more than available quantity ($available_to_lend available).");
    }

    db()->beginTransaction();
    try {
        $stmt = db()->prepare("
            INSERT INTO borrow_logs (ics_item_id, borrower_id, quantity, program_purpose, date_borrowed, status)
            VALUES (?, ?, ?, ?, ?, 'borrowed')
        ");
        $stmt->execute([$ics_item_id, $borrower_id, $quantity, $program_purpose, $date_borrowed]);
        
        db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Lent Equipment', 'Borrow', ?, ?)")
            ->execute([$uid, db()->lastInsertId(), "Lent qty $quantity for $program_purpose"]);

        db()->commit();
        header("Location: borrow.php?success=1");
        exit;
    } catch (Exception $e) {
        db()->rollBack();
        die("Error processing borrow record: " . $e->getMessage());
    }
}

// Handle Return submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_item'])) {
    $borrow_id = (int)$_POST['borrow_id'];
    
    // Verify borrow record exists
    $stmt = db()->prepare("SELECT * FROM borrow_logs WHERE borrow_id = ? AND status = 'borrowed'");
    $stmt->execute([$borrow_id]);
    $borrow = $stmt->fetch();

    if ($borrow) {
        db()->beginTransaction();
        try {
            $stmt = db()->prepare("UPDATE borrow_logs SET status = 'returned', date_returned = NOW() WHERE borrow_id = ?");
            $stmt->execute([$borrow_id]);

            db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Returned Equipment', 'Borrow', ?, ?)")
                ->execute([$uid, $borrow_id, "Item returned from borrower."]);

            db()->commit();
            header("Location: borrow.php?returned=1");
            exit;
        } catch (Exception $e) {
            db()->rollBack();
            die("Error processing return: " . $e->getMessage());
        }
    }
}

// Fetch teachers for lending dropdown
$teachers = db()->query("SELECT user_id, full_name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY full_name")->fetchAll();

// Fetch teacher's items available to lend
$my_lendable_items = [];
if ($role === 'teacher') {
    $stmt = db()->prepare("
        SELECT ii.ics_item_id, i.item_name, i.unit, ii.qty_issued, c.ics_number,
               COALESCE((SELECT SUM(quantity) FROM borrow_logs WHERE ics_item_id = ii.ics_item_id AND status = 'borrowed'), 0) as borrowed_qty
        FROM ics_items ii
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        WHERE c.teacher_id = ? AND c.status = 'active'
    ");
    $stmt->execute([$uid]);
    $my_lendable_items = $stmt->fetchAll();
}

// Fetch active borrow logs
if ($role === 'teacher') {
    $stmt = db()->prepare("
        SELECT b.*, i.item_name, i.unit, c.ics_number, u.full_name as borrower_name, lender_user.full_name as lender_name
        FROM borrow_logs b
        JOIN ics_items ii ON b.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        JOIN users u ON b.borrower_id = u.user_id
        JOIN users lender_user ON c.teacher_id = lender_user.user_id
        WHERE c.teacher_id = ? OR b.borrower_id = ?
        ORDER BY b.date_borrowed DESC
    ");
    $stmt->execute([$uid, $uid]);
    $borrow_logs = $stmt->fetchAll();
} else {
    $stmt = db()->query("
        SELECT b.*, i.item_name, i.unit, c.ics_number, u.full_name as borrower_name, lender_user.full_name as lender_name
        FROM borrow_logs b
        JOIN ics_items ii ON b.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        JOIN users u ON b.borrower_id = u.user_id
        JOIN users lender_user ON c.teacher_id = lender_user.user_id
        ORDER BY b.date_borrowed DESC
    ");
    $borrow_logs = $stmt->fetchAll();
}

$pageTitle = 'Borrow & Return Log';
$active = 'borrow';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2>Borrow & Return Log</h2>
        <p style="color: #666; margin: 0;">Track equipment lent out during school programs while maintaining primary accountability.</p>
    </div>
    <?php if ($role === 'teacher'): ?>
        <button onclick="openBorrowModal()" style="padding: 8px 16px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">+ Lend Item</button>
    <?php endif; ?>
</div>

<?php if (isset($_GET['success'])): ?>
    <div style="background: #d1fae5; color: #065f46; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
        Equipment lending recorded successfully! Note: You remain accountable on your ICS.
    </div>
<?php endif; ?>

<?php if (isset($_GET['returned'])): ?>
    <div style="background: #d1fae5; color: #065f46; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
        Equipment successfully marked as returned!
    </div>
<?php endif; ?>

<div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <table style="width: 100%; border-collapse: collapse; text-align: left;">
        <thead>
            <tr style="background: #f9fafb; border-bottom: 2px solid #eee;">
                <th style="padding: 12px;">Item Details</th>
                <th style="padding: 12px;">Lender (ICS Holder)</th>
                <th style="padding: 12px;">Borrower</th>
                <th style="padding: 12px;">Qty</th>
                <th style="padding: 12px;">Program / Purpose</th>
                <th style="padding: 12px;">Date Borrowed</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px; text-align: center;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($borrow_logs)): ?>
                <tr>
                    <td colspan="8" style="padding: 20px; text-align: center; color: #666;">No borrow or lend records found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($borrow_logs as $log): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px;">
                            <strong><?= e($log['item_name']) ?></strong><br>
                            <small style="color: #666;">ICS: <?= e($log['ics_number']) ?></small>
                        </td>
                        <td style="padding: 12px;"><?= e($log['lender_name']) ?></td>
                        <td style="padding: 12px; font-weight: bold; color: #800000;"><?= e($log['borrower_name']) ?></td>
                        <td style="padding: 12px;"><?= e($log['quantity']) ?> <?= e($log['unit']) ?></td>
                        <td style="padding: 12px;"><?= e($log['program_purpose']) ?></td>
                        <td style="padding: 12px;"><?= e(date('M d, Y', strtotime($log['date_borrowed']))) ?></td>
                        <td style="padding: 12px;">
                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold;
                                background: <?= $log['status'] === 'borrowed' ? '#fef3c7; color: #92400e;' : '#d1fae5; color: #065f46;' ?>;">
                                <?= e(ucfirst($log['status'])) ?>
                            </span>
                        </td>
                        <td style="padding: 12px; text-align: center;">
                            <?php if ($log['status'] === 'borrowed'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="borrow_id" value="<?= $log['borrow_id'] ?>">
                                    <button type="submit" name="return_item" onclick="return confirm('Mark this item as returned?')" style="padding: 4px 10px; background: #10b981; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em;">
                                        Mark Returned
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="color: #666; font-size: 0.85em;"><?= e(date('M d, Y', strtotime($log['date_returned']))) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Lend Item Modal -->
<?php if ($role === 'teacher'): ?>
<div id="borrowModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center;">
    <div style="background: white; padding: 25px; border-radius: 8px; width: 450px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h3 style="margin-top: 0; color: #800000;">Lend Equipment to Teacher</h3>
        <form method="POST">
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Select Your Accountable Item *</label>
                <select name="ics_item_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- Choose Item from your ICS --</option>
                    <?php foreach ($my_lendable_items as $mi): 
                        $avail = $mi['qty_issued'] - $mi['borrowed_qty'];
                        if ($avail > 0):
                    ?>
                        <option value="<?= $mi['ics_item_id'] ?>">
                            <?= e($mi['item_name']) ?> (ICS: <?= e($mi['ics_number']) ?>) - Available: <?= $avail ?> <?= e($mi['unit']) ?>
                        </option>
                    <?php endif; endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Borrowing Teacher *</label>
                <select name="borrower_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- Select Teacher Borrower --</option>
                    <?php foreach ($teachers as $t): if ($t['user_id'] != $uid): ?>
                        <option value="<?= $t['user_id'] ?>"><?= e($t['full_name']) ?></option>
                    <?php endif; endforeach; ?>
                </select>
            </div>

            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 1;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Quantity *</label>
                    <input type="number" name="quantity" min="1" value="1" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div style="flex: 1;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Date Borrowed *</label>
                    <input type="date" name="date_borrowed" value="<?= date('Y-m-d') ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Program / Purpose *</label>
                <input type="text" name="program_purpose" required placeholder="e.g. TLE School Fair 2026 Demo" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeBorrowModal()" style="padding: 8px 16px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                <button type="submit" name="borrow_item" style="padding: 8px 16px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Confirm Lend</button>
            </div>
        </form>
    </div>
</div>

<script>
function openBorrowModal() {
    document.getElementById('borrowModal').style.display = 'flex';
}
function closeBorrowModal() {
    document.getElementById('borrowModal').style.display = 'none';
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>