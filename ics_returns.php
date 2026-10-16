<?php
require_once __DIR__ . '/includes/auth.php';
$me = require_role(['supply_officer']);
$pdo = db();
$actor = (int)$me['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $icsId = (int)($_POST['ics_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $confirmed = isset($_POST['verified_serviceable']);
    try {
        if (!$confirmed || $reason === '') { throw new RuntimeException('Physical serviceability verification and return reason are required.'); }
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT c.*, u.full_name FROM ics c JOIN users u ON u.user_id = c.teacher_id WHERE c.ics_id = ? FOR UPDATE");
        $st->execute([$icsId]); $doc = $st->fetch();
        if (!$doc || $doc['status'] !== 'active') { throw new RuntimeException('ICS is no longer active.'); }
        $checks = $pdo->prepare("SELECT COUNT(*) FROM ics_items ii LEFT JOIN borrow_logs b ON b.ics_item_id = ii.ics_item_id AND b.status = 'borrowed' LEFT JOIN condemnations co ON co.ics_item_id = ii.ics_item_id AND (co.status = 'requested' OR co.status = 'approved') WHERE ii.ics_id = ? AND (b.borrow_id IS NOT NULL OR co.condemnation_id IS NOT NULL)");
        $checks->execute([$icsId]);
        if ((int)$checks->fetchColumn() > 0) { throw new RuntimeException('This ICS has a condemnation history or an outstanding loan. Whole-ICS serviceable returns are not permitted; use the appropriate reconciliation workflow.'); }
        // The custodian confirms all units are physically received and serviceable.
        // Closing a slip restores its quantities to issuable stock in the existing stock formula.
        $pdo->prepare("INSERT INTO ics_returns (ics_id, verified_by, reason, verified_serviceable) VALUES (?, ?, ?, 1)")->execute([$icsId, $actor, $reason]);
        $pdo->prepare("UPDATE ics SET status = 'closed' WHERE ics_id = ? AND status = 'active'")->execute([$icsId]);
        log_action($actor, 'Returned ICS', 'ICS', $icsId, 'All items physically received and confirmed serviceable: ' . $reason);
        $pdo->commit();
        flash('success', 'ICS returned, closed and archived. Its physically verified serviceable items are now available for reissue.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('ics_returns.php');
}
$records = $pdo->query("SELECT c.ics_id, c.ics_number, c.date_issued, u.full_name, cr.course_name, COALESCE(SUM(ii.qty_issued), 0) AS qty FROM ics c JOIN users u ON c.teacher_id = u.user_id JOIN courses cr ON c.course_id = cr.course_id LEFT JOIN ics_items ii ON ii.ics_id = c.ics_id WHERE c.status = 'active' GROUP BY c.ics_id, c.ics_number, c.date_issued, u.full_name, cr.course_name ORDER BY c.date_issued DESC")->fetchAll();
$pageTitle = 'Accountability Returns'; $active = 'accounts.php';
include __DIR__.'/includes/header.php';
?>
<div class="page-head"><h1>Receive &amp; Close ICS</h1><p>Only the Supply Officer may certify a complete physical return. If items are damaged, missing, or still borrowed, resolve those cases first. Closing an ICS restores its quantity to available stock.</p></div>
<div class="card"><h3>Active ICS awaiting resolution</h3>
<?php if (!$records): ?><p>No active ICS to return.</p><?php endif; ?>
<?php foreach ($records as $r): ?>
<form method="post" class="card" style="margin-bottom:16px;padding:16px">
<?= csrf_field() ?>
<input type="hidden" name="ics_id" value="<?= (int)$r['ics_id'] ?>">
<p><strong><?= e($r['ics_number']) ?></strong> — <?= e($r['full_name']) ?> / <?= e($r['course_name']) ?> (<?= (int)$r['qty'] ?> units on this slip)</p>
<p><a href="<?= e(url('print_ics.php?id='.(int)$r['ics_id'])) ?>" target="_blank">Review printable ICS and items</a></p>
<label>Return receipt details / reason <input name="reason" required maxlength="500" placeholder="e.g. All 10 units received and physically inspected"></label>
<p><label><input type="checkbox" name="verified_serviceable" value="1" required> I personally verified that EVERY unit on this ICS was received and is serviceable; no outstanding damage, disposal or shortages remain.</label></p>
<button type="submit" class="btn btn-primary" onclick="return confirm('Confirm all quantities were physically received and serviceable? This will close the entire ICS and release its units to available stock.')">Receive all items &amp; close ICS</button>
</form>
<?php endforeach; ?></div>
<?php include __DIR__.'/includes/footer.php'; ?>
