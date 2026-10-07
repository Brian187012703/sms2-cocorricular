<?php
require_once __DIR__ . '/../../app/shared/backup_restore.php';

$res = generate_database_backup($conn, 'HOSTFORGE_RELEASE');
if ($res['success']) {
    $target1 = __DIR__ . '/../hostforge_production_sms_db.sql';
    $target2 = __DIR__ . '/../sms_db.sql';
    copy($res['filepath'], $target1);
    copy($res['filepath'], $target2);
    echo "SUCCESS: Fresh release dump saved to:\n";
    echo "  - $target1 (" . round(filesize($target1)/1024, 2) . " KB)\n";
    echo "  - $target2 (" . round(filesize($target2)/1024, 2) . " KB)\n";
} else {
    echo "ERROR: Backup failed\n";
    exit(1);
}
