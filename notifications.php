<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/notifications.php';
$me=require_login();$pdo=db();
$pageTitle='Notifications';$active='notifications.php';
include __DIR__.'/includes/header.php';
?>
<section class="tn-notify-page">
  <div class="tn-notify-page-heading"><div><h1>Notifications</h1><p class="muted">Updates about your accountable inventory, requests and account activity.</p></div><div class="tn-notify-page-actions"><button type="button" id="tn-read-all" class="btn btn-outline">Mark all as read</button></div></div>
  <div class="card tn-notify-history-card">
    <div class="tn-notify-history-toolbar"><div><strong>Notification history</strong> <small id="tn-history-count" class="muted"></small></div><label class="tn-notify-toggle"><input type="checkbox" id="tn-history-unread"> Unread only</label></div>
    <div id="tn-notify-history" aria-live="polite"><p class="muted">Loading notifications…</p></div>
    <div class="tn-notify-page-nav"><button type="button" class="btn btn-outline" id="tn-prev" disabled>Previous</button><span id="tn-page-text" class="muted"></span><button type="button" class="btn btn-outline" id="tn-next" disabled>Next</button></div>
  </div>
  <p class="muted tn-notify-note">Marking an alert as read does not approve, accept, receive or finalize its related transaction. Open the record to take any authorized action.</p>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
