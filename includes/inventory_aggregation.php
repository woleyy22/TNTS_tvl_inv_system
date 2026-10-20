<?php
/** Task 4: master inventory / receipt-batch mapping. MariaDB / PHP 8. */
function aggregate_key_part($str): string {
    $str = trim(preg_replace('/\s+/u', ' ', (string)$str));
    return function_exists('mb_strtolower') ? mb_strtolower($str, 'UTF-8') : strtolower($str);
}
function aggregate_identity(array $item, string $type): string {
    return hash('sha256', json_encode([
        aggregate_key_part($item['item_name']), aggregate_key_part($item['description'] ?? ''),
        aggregate_key_part($item['unit']), $type
    ], JSON_UNESCAPED_UNICODE));
}
/** Must be called inside inspection transaction; serializes merges and prevents repeat acceptance. */
function aggregate_accepted_delivery_item(PDO $pdo, array $item, int $qty, string $type, string $choice, string $category = 'equipment', ?int $courseId = null, ?string $batchNumber = null, ?string $expirationDate = null): int {
    if (!$pdo->inTransaction()) throw new LogicException('Aggregation requires a transaction.');
    if (!in_array($category,['equipment','non_consumable','consumable','expiring_material'],true)) throw new InvalidArgumentException('Invalid item category.');
    if ($qty < 1 || !in_array($type, ['equipment','supply'], true)) throw new InvalidArgumentException('Invalid accepted quantity or item type.');
    $pdo->query('SELECT lock_id FROM inventory_aggregation_lock WHERE lock_id=1 FOR UPDATE')->fetchColumn();
    $di = (int)$item['delivery_item_id'];
    $check = $pdo->prepare('SELECT batch_id FROM inventory_receipt_batches WHERE delivery_item_id=?');
    $check->execute([$di]);
    if ($check->fetchColumn()) throw new RuntimeException('This delivery item has already been entered into inventory.');
    $key = aggregate_identity($item, $type);
    // Keep legacy master identity for backwards compatibility, but don't merge unlike categories.
    $match = $pdo->prepare('SELECT inventory_item_id FROM inventory_master_keys WHERE identity_hash=?');
    $match->execute([$key]);
    $exact = $match->fetchColumn();
    if ($exact !== false) {
        $checkMaster=$pdo->prepare('SELECT item_category FROM inventory_items WHERE item_id=?');
        $checkMaster->execute([(int)$exact]);
        if ($checkMaster->fetchColumn() !== $category) {
            throw new RuntimeException('Same item identity has a different tracking category. Please review the existing master item before accepting this delivery.');
        }
    }
    $selected = null;
    if ($choice === 'auto') {
        if ($exact !== false) $selected=(int)$exact;
        else {
            // A name match with conflicting specification must be explicitly resolved.
            $similar = $pdo->prepare('SELECT item_id FROM inventory_items WHERE LOWER(TRIM(item_name))=LOWER(TRIM(?)) AND LOWER(TRIM(unit))=LOWER(TRIM(?)) AND item_type=? AND item_category=? LIMIT 1');
            $similar->execute([$item['item_name'],$item['unit'],$type,$category]);
            if ($similar->fetchColumn()) throw new RuntimeException('A similar inventory item exists for "'.$item['item_name'].'". Select an existing master or choose Create separate item.');
        }
    } elseif ($choice === 'new') {
        if ($exact !== false) throw new RuntimeException('An identical item already exists. Use Automatic match to prevent duplicate master records.');
    } elseif (ctype_digit($choice) && (int)$choice > 0) {
        $selected=(int)$choice;
        $st=$pdo->prepare('SELECT item_name, unit, item_type, item_category FROM inventory_items WHERE item_id=? FOR UPDATE');
        $st->execute([$selected]); $master=$st->fetch(PDO::FETCH_ASSOC);
        if (!$master || aggregate_key_part($master['item_name']) !== aggregate_key_part($item['item_name']) ||
            aggregate_key_part($master['unit']) !== aggregate_key_part($item['unit']) || ($master['item_type'] !== $type || $master['item_category'] !== $category))
            throw new RuntimeException('Selected inventory master has a different name, unit, or tracking category.');
    } else throw new RuntimeException('Invalid inventory matching choice.');
    if ($selected !== null) {
        $pdo->prepare('UPDATE inventory_items SET quantity=quantity+?, unit_cost=CASE WHEN (unit_cost <=> ?) THEN unit_cost ELSE NULL END WHERE item_id=?')
            ->execute([$qty,$item['unit_cost'],$selected]);
    } else {
        $pdo->prepare('INSERT INTO inventory_items (delivery_item_id,item_name,description,item_type,item_category,unit,unit_cost,quantity) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$di,$item['item_name'],$item['description'],$type,$category,$item['unit'],$item['unit_cost'],$qty]);
        $selected=(int)$pdo->lastInsertId();
    }
    // Link exact identity on newly created records or an explicit compatible match.
    // Conflicting descriptions mapped to an existing item keep their own batch detail.
    if ($exact === false && ($choice !== 'new')) {
        $pdo->prepare('INSERT IGNORE INTO inventory_master_keys (identity_hash,inventory_item_id) VALUES (?,?)')->execute([$key,$selected]);
    }
    if ($choice === 'new') {
        $pdo->prepare('INSERT INTO inventory_master_keys (identity_hash,inventory_item_id) VALUES (?,?)')->execute([$key,$selected]);
    }
    // A batch may have its own intended course. The master item remains shared even
    // when its receipt batches serve different courses.
    $pdo->prepare('INSERT INTO inventory_receipt_batches (inventory_item_id,delivery_item_id,received_qty,batch_number,expiration_date,course_id) VALUES (?,?,?,?,?,?)')
        ->execute([$selected,$di,$qty,$batchNumber ?: null,$expirationDate ?: null,$courseId]);
    return $selected;
}
