<?php
declare(strict_types=1);
// Plaintext stdout only in the private pipe controlled by recovery.py.
if (PHP_SAPI !== 'cli' || getenv('PAWLIFE_BACKUP_PIPE') !== '1') exit(2);
require dirname(__DIR__) . '/src/bootstrap.php';
try {
    \PawLife\Firestore\BackupExport::run(\PawLife\Firestore\FirestoreClient::fromEnv(),
        \PawLife\Support\Env::get('FIREBASE_PROJECT_ID') ?? '',
        static function (array $record): void { echo \PawLife\Support\Json::encode($record) . "\n"; });
} catch (Throwable) { fwrite(STDERR, "BACKUP_EXPORT_FAILED\n"); exit(1); }
