<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/submission_storage.php';

$pdo = db();
$rows = $pdo->query(
    "SELECT id, file_path FROM exercise_submissions
     WHERE file_path IS NOT NULL AND file_path <> ''
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
$referenceCheck = $pdo->prepare(
    "SELECT COUNT(*) FROM exercise_submissions
     WHERE REPLACE(file_path, CHAR(92), '/') IN (:prefixed, :bare)"
);
$updated = 0;

foreach ($rows as $row) {
    $submissionId = (int) $row['id'];
    $storedPath = is_string($row['file_path']) ? $row['file_path'] : '';
    $normalizedPath = str_replace('\\', '/', $storedPath);

    if (str_starts_with($normalizedPath, 'private-submissions/')) {
        $privatePath = bf_submission_resolve_file($normalizedPath);
        if ($privatePath === null) {
            throw new RuntimeException('Private file is missing for submission ' . $submissionId . '.');
        }
        $fileName = basename($privatePath);
    } else {
        $sourcePath = bf_submission_resolve_file($normalizedPath);
        if ($sourcePath === null) {
            throw new RuntimeException('Legacy file is missing or invalid for submission ' . $submissionId . '.');
        }
        $fileName = basename($sourcePath);
        $targetPath = bf_submission_storage_dir() . DIRECTORY_SEPARATOR . $fileName;

        if (is_file($targetPath)) {
            if (hash_file('sha256', $sourcePath) !== hash_file('sha256', $targetPath)) {
                throw new RuntimeException('Private destination conflicts for submission ' . $submissionId . '.');
            }
        } elseif (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Could not copy file for submission ' . $submissionId . '.');
        }
        if (!chmod($targetPath, 0600)) {
            throw new RuntimeException('Could not restrict permissions for submission ' . $submissionId . '.');
        }

        $privateKey = bf_submission_file_key($fileName);
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'UPDATE exercise_submissions SET file_path = :new_path
                 WHERE id = :id AND file_path = :old_path'
            );
            $update->execute([
                'new_path' => $privateKey,
                'id' => $submissionId,
                'old_path' => $storedPath,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Submission file reference changed during migration.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        $updated++;
    }

    $legacyPrefix = 'uploads/submissions/' . $fileName;
    $referenceCheck->execute(['prefixed' => $legacyPrefix, 'bare' => $fileName]);
    if ((int) $referenceCheck->fetchColumn() > 0) {
        continue;
    }

    $legacyFile = bf_submission_resolve_file($legacyPrefix);
    if ($legacyFile !== null) {
        $privateFile = bf_submission_resolve_file('private-submissions/' . $fileName);
        if (
            $privateFile === null
            || hash_file('sha256', $legacyFile) !== hash_file('sha256', $privateFile)
        ) {
            throw new RuntimeException('Legacy and private files do not match for submission ' . $submissionId . '.');
        }
        if (!unlink($legacyFile)) {
            throw new RuntimeException('Could not remove public legacy file for submission ' . $submissionId . '.');
        }
    }
}

echo 'Submission storage migration complete. Updated references: ' . $updated . PHP_EOL;
