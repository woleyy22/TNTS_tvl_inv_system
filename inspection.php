<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/inventory_aggregation.php';

$user = current_user();
if (!$user || !in_array($user['role'], ['supply_officer'], true)) {
    die("Unauthorized access. Only the Supply Officer can accept deliveries.");
}

$delivery_id = (int)($_GET['id'] ?? 0);

// Fetch pending delivery
$stmt = db()->prepare("SELECT * FROM deliveries WHERE delivery_id = ? AND status = 'pending_inspection'");
$stmt->execute([$delivery_id]);
$delivery = $stmt->fetch();

if (!$delivery) {
    die("Delivery not found, or it has already been inspected.");
}

// Fetch items
$stmt = db()->prepare("SELECT * FROM delivery_items WHERE delivery_id = ?");
$stmt->execute([$delivery_id]);
$items = $stmt->fetchAll();

if (empty($items)) {
    die("Cannot inspect a delivery with no items. Please go back and add items first.");
}

$inspection_error = '';
$existing_masters = db()->query('SELECT item_id, item_name, description, unit, item_type, item_category, quantity FROM inventory_items ORDER BY item_name, item_id')->fetchAll(PDO::FETCH_ASSOC);
$courses = db()->query('SELECT course_id, course_name FROM courses ORDER BY course_name')->fetchAll(PDO::FETCH_ASSOC);

// Handle Inspection Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $iar_number = generate_document_number('iar');
    $date_inspected = $_POST['date_inspected'] ?? date('Y-m-d');
    $result = $_POST['result'] ?? 'complete';
    $remarks = trim($_POST['remarks'] ?? '');
    $active_user_id = $user['id'] ?? $user['user_id'];
    
    // We use a database transaction to ensure everything saves successfully together
    db()->beginTransaction();
    try {
        // 1. Create the Inspection Report
        $stmt = db()->prepare("INSERT INTO inspection_reports (delivery_id, iar_number, inspected_by, date_inspected, result, remarks) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$delivery_id, $iar_number, $active_user_id, $date_inspected, $result, $remarks]);
        
        // 2. Loop through each item to accept it into INVENTORY
        $all_accepted = true;
        foreach ($items as $item) {
            $item_id = $item['delivery_item_id'];
            $qty_accepted = (int)($_POST['qty_accepted'][$item_id] ?? 0);
            
            if ($qty_accepted < 0 || $qty_accepted > (int)$item['qty_delivered']) {
                throw new RuntimeException('Accepted quantity is invalid for item: ' . $item['item_name']);
            }
            if ($qty_accepted < $item['qty_delivered']) {
                $all_accepted = false;
            }
            
            // Update delivery_items with the accepted amount
            $stmt = db()->prepare("UPDATE delivery_items SET qty_accepted = ? WHERE delivery_item_id = ?");
            $stmt->execute([$qty_accepted, $item_id]);
            
            // If we accepted at least 1, push it to the main inventory_items table!
            if ($qty_accepted > 0) {
                // Classification is internal inventory metadata, NOT a required delivery field.
                // It must be chosen explicitly at inspection, never guessed from unit cost.
                $category = (string)($_POST['inventory_category'][$item_id] ?? '');
                if (!in_array($category, ['equipment','non_consumable','consumable','expiring_material'], true)) throw new RuntimeException('Select a valid tracking category for: '.$item['item_name']);
                $item_type = in_array($category,['equipment','non_consumable'],true) ? 'equipment' : 'supply';
                // Optional course purpose is kept on the RECEIPT BATCH, not on the shared master item.
                $courseRaw = trim((string)($_POST['course_id'][$item_id] ?? ''));
                $courseId = null;
                if ($courseRaw !== '') {
                    if (!ctype_digit($courseRaw) || (int)$courseRaw < 1) throw new RuntimeException('Invalid course for: '.$item['item_name']);
                    $courseId = (int)$courseRaw;
                    $courseCheck = db()->prepare('SELECT 1 FROM courses WHERE course_id=?');
                    $courseCheck->execute([$courseId]);
                    if (!$courseCheck->fetchColumn()) throw new RuntimeException('Selected course was not found for: '.$item['item_name']);
                }
                $batchNumber=trim((string)($_POST['batch_number'][$item_id]??''));
                if (strlen($batchNumber)>80) throw new RuntimeException('Batch number is too long for: '.$item['item_name']);
                $expirationDate=trim((string)($_POST['expiration_date'][$item_id]??''));
                if ($expirationDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expirationDate) || !checkdate((int)substr($expirationDate,5,2),(int)substr($expirationDate,8,2),(int)substr($expirationDate,0,4)))) throw new RuntimeException('Invalid expiration date.');

                // A single master item may be referenced by several accepted delivery batches.
                $masterId = aggregate_accepted_delivery_item(db(), $item, $qty_accepted, $item_type,
                    (string)($_POST['master_choice'][$item_id] ?? 'auto'), $category, $courseId, $batchNumber ?: null, $expirationDate ?: null);

            }
        }
        
        // 3. Update delivery status
        $new_status = $all_accepted ? 'accepted' : 'partially_accepted';
        $stmt = db()->prepare("UPDATE deliveries SET status = ? WHERE delivery_id = ?");
        $stmt->execute([$new_status, $delivery_id]);
        
        // 4. Log the action
        db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Inspected Delivery', 'Inspection', ?, ?)")
            ->execute([$active_user_id, $delivery_id, "IAR processed. Status updated to $new_status."]);
            
        db()->commit();
        
        // Go back to the delivery view, which will now show it as Accepted!
        header("Location: delivery_view.php?id=" . $delivery_id);
        exit;
    } catch (Exception $e) {
        db()->rollBack();
        $inspection_error = $e->getMessage();
    }
}

$pageTitle = 'Inspection & Acceptance (IAR)';
$active = 'receiving';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-050">
    <h2>Create Inspection & Acceptance Report (IAR)</h2>
    <p class="legacy-037">Delivery #<?= e($delivery_id) ?> - <?= e($delivery['supplier_name']) ?></p>
</div>

<?php if ($inspection_error): ?><div class="legacy-181" role="alert" style="padding:12px;margin-bottom:16px;color:#8b0000;background:#ffe3e3;border-radius:6px;"><?= e($inspection_error) ?></div><?php endif; ?>
<div class="card legacy-064 p471-iar-card tnts-m28b-inspection">
    <form method="POST" id="inspection_form">
        <div class="legacy-065 tnts-m28b-iar-header-fields">
            <div class="legacy-049">
                <label class="legacy-046">IAR Number (Optional)</label>
                <input type="text" name="iar_number" placeholder="e.g. IAR-2026-001" class="legacy-047">
            </div>
            <div class="legacy-049">
                <label class="legacy-046">Date Inspected *</label>
                <input type="date" name="date_inspected" value="<?= date('Y-m-d') ?>" required class="legacy-047">
            </div>
            <div class="legacy-049">
                <label class="legacy-046">Overall Result *</label>
                <select name="result" required class="legacy-047">
                    <option value="complete">Complete Delivery</option>
                    <option value="incomplete">Incomplete Delivery</option>
                </select>
            </div>
        </div>

        <div class="legacy-074">Exact matches automatically join an existing inventory item. If a similar item already exists, explicitly select it or choose to create a separate item. Costs remain recorded per delivery.</div>
        <h3 class="legacy-066">Items to Accept</h3>
        <p class="legacy-067">Verify accepted quantities and select a tracking category. Course purpose and batch details are optional; record the expiry date of dated materials when known.</p>

        <!-- Phase 3.8.17: compact inspection list; expand details per delivered item. -->
<style>
/* Scoped to this inspection page; do not change other tables or the navigation. */
.p3817-iar-wrap{width:100%;max-width:100%;min-width:0;overflow-x:auto;border:1px solid #dfe4ee;border-radius:12px;background:#fff;margin:14px 0 22px}
.p3817-iar-table{width:100%;min-width:0!important;table-layout:fixed;border-collapse:collapse;font-size:.9rem}
.p3817-iar-table th,.p3817-iar-table td{padding:13px 11px;border-bottom:1px solid #e7eaf1;vertical-align:middle;text-align:left;overflow-wrap:anywhere}
.p3817-iar-table thead{background:#f4f6f9}
.p3817-iar-table th{font-size:.74rem;letter-spacing:.03em;text-transform:uppercase;color:#617089;font-weight:750}
.p3817-iar-table .p3817-item-name{display:block;color:#22324b;font-weight:750;margin-bottom:3px}
.p3817-iar-table .p3817-subtext{display:block;color:#657188;font-size:.76rem}
.p3817-iar-table .p3817-qty-cell input,.p3817-iar-table .p3817-category-cell select{width:100%;min-width:0;max-width:100%;box-sizing:border-box;min-height:40px;padding:7px 9px;border:1px solid #d3dbe8;border-radius:8px;background:#fff;color:#25344b}
.p3817-iar-table .p3817-qty-cell input:focus,.p3817-iar-table .p3817-category-cell select:focus,.p3817-iar-details input:focus,.p3817-iar-details select:focus{outline:2px solid #8c1d40;outline-offset:1px}
.p3817-iar-table .p3817-status{display:block;font-size:.72rem;color:#68768d;margin-bottom:6px}
.p3817-iar-table .p3817-status.is-ready{color:#147c54}
.p3817-iar-table .p3817-status.is-warning{color:#a24d15}
.p3817-iar-table .p3817-toggle{width:100%;max-width:145px;white-space:nowrap;background:#fff;border:1px solid #d5b2bf;border-radius:8px;color:#8b173a;font-size:.82rem;font-weight:700;cursor:pointer;padding:9px 10px;min-height:40px}
.p3817-iar-table .p3817-toggle:hover,.p3817-iar-table .p3817-toggle[aria-expanded="true"]{background:#f9eef2}
.p3817-iar-table .p3817-toggle:focus-visible{outline:3px solid #9d2953;outline-offset:2px}
.p3817-iar-table tr.p3817-inspect-details[hidden]{display:none!important}
.p3817-iar-table tr.p3817-inspect-details td{padding:0 13px 16px;background:#fcfcfe}
.p3817-iar-table .p3817-iar-details{border:1px solid #dce3ef;border-left:3px solid #86163a;background:#f8f9fc;border-radius:9px;padding:15px;margin:9px 0 0;min-width:0}
.p3817-iar-details-header{font-weight:750;color:#26354c;margin:0 0 13px}
.p3817-iar-details-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.p3817-iar-details-grid>*{min-width:0}
.p3817-iar-details label{display:block;font-size:.81rem;color:#53617a;font-weight:700;margin-bottom:6px}
.p3817-iar-details input,.p3817-iar-details select{display:block;width:100%;max-width:100%;min-width:0;box-sizing:border-box;min-height:41px;padding:8px 10px;background:#fff;border:1px solid #ced7e5;border-radius:8px;color:#26344d;font-size:.85rem}
.p3817-iar-details .p3817-helper{display:block;font-size:.73rem;color:#647188;line-height:1.45;margin:7px 0 0}
.p3817-iar-details .p3817-date-field{margin-top:10px}
.p3817-iar-table tbody tr.p3817-summary-row:hover{background:#fffafd}
.p3817-iar-table tbody tr:last-child td{border-bottom:0}
@media(min-width:900px){.p3817-iar-table th:nth-child(1){width:27%}.p3817-iar-table th:nth-child(2){width:12%}.p3817-iar-table th:nth-child(3){width:17%}.p3817-iar-table th:nth-child(4){width:26%}.p3817-iar-table th:nth-child(5){width:18%}}
@media(max-width:950px){.p3817-iar-details-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.p3817-iar-table{min-width:700px!important}.p3817-iar-details-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.p3817-iar-table td,.p3817-iar-table th{padding:10px 9px}}
@media(max-width:490px){.p3817-iar-details-grid{grid-template-columns:1fr}}
</style>
<div class="p3817-iar-wrap" role="region" aria-label="Inspection items" tabindex="0">
  <table class="p3817-iar-table">
    <thead>
      <tr>
        <th scope="col">Item / Unit</th>
        <th scope="col">Delivered</th>
        <th scope="col">Qty to Accept</th>
        <th scope="col">Tracking Category</th>
        <th scope="col">Inspect</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <?php $lineId = (int)$item['delivery_item_id']; ?>
      <tr class="p3817-summary-row" data-iar-line="<?= $lineId ?>">
        <td>
          <strong class="p3817-item-name"><?= e($item['item_name']) ?></strong>
          <span class="p3817-subtext"><?= e($item['unit'] ?: 'Unit not specified') ?> · Cost: <?= e(display_unit_cost($item['unit_cost'])) ?></span>
        </td>
        <td><strong><?= (int)$item['qty_delivered'] ?></strong> <span class="p3817-subtext"><?= e($item['unit'] ?: '') ?></span></td>
        <td class="p3817-qty-cell">
          <input aria-label="Quantity to accept for <?= e($item['item_name']) ?>" type="number" name="qty_accepted[<?= $lineId ?>]"
                 value="<?= e($_POST['qty_accepted'][$lineId] ?? $item['qty_delivered']) ?>"
                 min="0" max="<?= (int)$item['qty_delivered'] ?>" step="1" required>
        </td>
        <td class="p3817-category-cell">
          <select name="inventory_category[<?= $lineId ?>]" class="inspection-type"
                  aria-label="Tracking category for <?= e($item['item_name']) ?>">
            <option value="">Choose category</option>
            <?php foreach (['equipment'=>'Equipment','non_consumable'=>'Non-Consumable','consumable'=>'Consumable','expiring_material'=>'Expiring Material'] as $k=>$v): ?>
              <option value="<?= e($k) ?>" <?= ($_POST['inventory_category'][$lineId] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <span class="p3817-status" aria-live="polite">Needs category</span>
          <button class="p3817-toggle" type="button" aria-expanded="false" aria-controls="iar-details-<?= $lineId ?>">
            Inspect details <span aria-hidden="true">▾</span>
          </button>
        </td>
      </tr>
      <tr class="p3817-inspect-details" id="iar-details-<?= $lineId ?>" data-iar-details="<?= $lineId ?>" hidden>
        <td colspan="5">
          <div class="p3817-iar-details">
            <div class="p3817-iar-details-header">Additional inspection details — <?= e($item['item_name']) ?></div>
            <div class="p3817-iar-details-grid">
              <div>
                <label for="iar-course-<?= $lineId ?>">Course Purpose (Optional)</label>
                <select id="iar-course-<?= $lineId ?>" name="course_id[<?= $lineId ?>]" aria-label="Course purpose for <?= e($item['item_name']) ?>">
                  <option value="">Unclassified / No course</option>
                  <?php foreach($courses as $crs): ?>
                    <option value="<?= (int)$crs['course_id'] ?>" <?= (int)($_POST['course_id'][$lineId] ?? 0) === (int)$crs['course_id'] ? 'selected' : '' ?>><?= e($crs['course_name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="p3817-helper">Purpose of this delivery batch; does not restrict the teacher who may receive it.</span>
              </div>
              <div>
                <label for="iar-batch-<?= $lineId ?>">Batch Number (Optional)</label>
                <input id="iar-batch-<?= $lineId ?>" name="batch_number[<?= $lineId ?>]" placeholder="Batch number" maxlength="80" value="<?= e($_POST['batch_number'][$lineId] ?? '') ?>">
                <label for="iar-expiry-<?= $lineId ?>" class="p3817-date-field">Expiration Date (If Applicable)</label>
                <input id="iar-expiry-<?= $lineId ?>" type="date" name="expiration_date[<?= $lineId ?>]" value="<?= e($_POST['expiration_date'][$lineId] ?? '') ?>">
              </div>
              <div>
                <label for="iar-match-<?= $lineId ?>">Inventory Matching</label>
                <select id="iar-match-<?= $lineId ?>" name="master_choice[<?= $lineId ?>]">
                  <option value="auto">Automatic match (exact only)</option>
                  <option value="new" <?= ($_POST['master_choice'][$lineId] ?? '') === 'new' ? 'selected' : '' ?>>Create separate inventory item</option>
                  <?php foreach ($existing_masters as $master): ?>
                    <?php if (strcasecmp(trim((string)$master['item_name']),trim((string)$item['item_name']))===0 && strcasecmp(trim((string)$master['unit']),trim((string)$item['unit']))===0): ?>
                    <option value="<?= (int)$master['item_id'] ?>" <?= (string)($_POST['master_choice'][$lineId] ?? '')===(string)$master['item_id']?'selected':'' ?>>Match #<?= (int)$master['item_id'] ?> — <?= e($master['description'] ?: 'No description') ?> (<?= e($master['item_type']) ?>)</option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
                <span class="p3817-helper">Different specifications need confirmation. Different units or tracking categories cannot be combined; course purposes may share one master item.</span>
              </div>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

        <div class="legacy-050">
            <label class="legacy-046">Inspection Remarks / Notes (Optional)</label>
            <textarea name="remarks" rows="3" placeholder="Note any missing items, damages, or notes for the supplier here..." class="legacy-047"></textarea>
        </div>

        <div class="legacy-074">
            <strong>Signatories:</strong> This IAR will be recorded under your name (<strong><?= e($user['name'] ?? $user['full_name'] ?? 'Admin') ?></strong>). As confirmed, Mr. Arnold G. Angeles (Supply Officer) and Mr. Reynald Ace (Property Custodian) will sign the physical PDF generation of this form.
        </div>

        <button type="submit" class="legacy-075">Confirm Inspection & Add to Inventory</button>
        <a href="delivery_view.php?id=<?= $delivery_id ?>" class="legacy-076">Cancel</a>
    </form>
</div>

<script>
(function () {
  const form = document.getElementById('inspection_form');
  if (!form) return;
  const rows = [...form.querySelectorAll('.p3817-summary-row')];

  function setExpanded(row, expand) {
    const btn = row.querySelector('.p3817-toggle');
    const details = document.getElementById(btn.getAttribute('aria-controls'));
    if (!details) return;
    details.hidden = !expand;
    btn.setAttribute('aria-expanded', expand ? 'true' : 'false');
    btn.innerHTML = expand ? 'Hide details <span aria-hidden="true">▴</span>' : 'Inspect details <span aria-hidden="true">▾</span>';
  }
  function validateRow(row) {
    const qty = row.querySelector('input[name^="qty_accepted"]');
    const category = row.querySelector('select.inspection-type');
    const status = row.querySelector('.p3817-status');
    const num = Number(qty.value);
    const accepting = Number.isFinite(num) && num > 0;
    category.required = accepting;
    category.style.borderColor = '';
    if (qty.value === '' || !Number.isInteger(num) || num < 0 || num > Number(qty.max)) {
      status.textContent = 'Check quantity';
      status.className = 'p3817-status is-warning';
      return false;
    }
    if (!accepting) {
      status.textContent = 'Not accepting';
      status.className = 'p3817-status';
      return true;
    }
    if (!category.value) {
      status.textContent = 'Needs category';
      status.className = 'p3817-status is-warning';
      return false;
    }
    status.textContent = 'Ready';
    status.className = 'p3817-status is-ready';
    return true;
  }

  rows.forEach(row => {
    const toggle = row.querySelector('.p3817-toggle');
    toggle.addEventListener('click', () => setExpanded(row, toggle.getAttribute('aria-expanded') !== 'true'));
    row.querySelector('input[name^="qty_accepted"]').addEventListener('input', () => validateRow(row));
    row.querySelector('.inspection-type').addEventListener('change', () => validateRow(row));
    validateRow(row);
  });

  form.addEventListener('submit', event => {
    let firstInvalid = null;
    for (const row of rows) {
      if (!validateRow(row) && !firstInvalid) firstInvalid = row;
    }
    if (firstInvalid) {
      event.preventDefault();
      firstInvalid.scrollIntoView({behavior:'smooth',block:'center'});
      const qty = firstInvalid.querySelector('input[name^="qty_accepted"]');
      const category = firstInvalid.querySelector('.inspection-type');
      const num = Number(qty.value);
      (qty.value === '' || !Number.isInteger(num) || num < 0 || num > Number(qty.max) ? qty : category).focus({preventScroll:true});
      alert('Check quantities and select a tracking category for each accepted item.');
    }
  });

  // After a server-side error, keep any edited optional inspection fields easy to locate.
  const hadServerError = <?= $inspection_error ? 'true' : 'false' ?>;
  if (hadServerError) {
    rows.forEach(row => {
      const detail = document.getElementById(row.querySelector('.p3817-toggle').getAttribute('aria-controls'));
      if (detail && [...detail.querySelectorAll('input,select')].some(el => el.value && el.value !== 'auto')) setExpanded(row,true);
    });
  }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>