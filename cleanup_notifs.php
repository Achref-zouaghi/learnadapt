<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=learnadapt', 'root', '');

// Mark old-style notifications (without related_topic_id) as read
$old = $pdo->exec("UPDATE notifications SET is_read = 1 WHERE type IN ('TASK_OVERDUE','TASK_DUE_TODAY') AND related_topic_id IS NULL");
echo "Old notifications without task link marked read: $old\n";

// Keep only the latest unread notification per task+type, mark rest as read
$pdo->exec("
    UPDATE notifications n
    INNER JOIN (
        SELECT related_topic_id, type, MAX(id) as max_id
        FROM notifications
        WHERE is_read = 0 AND type IN ('TASK_OVERDUE','TASK_DUE_TODAY') AND related_topic_id IS NOT NULL
        GROUP BY related_topic_id, type
    ) keep ON n.related_topic_id = keep.related_topic_id AND n.type = keep.type
    SET n.is_read = 1
    WHERE n.id < keep.max_id AND n.is_read = 0
");

$remaining = $pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
echo "Remaining unread: $remaining\n";
