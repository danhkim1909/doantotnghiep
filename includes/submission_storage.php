<?php
declare(strict_types=1);

function bf_submission_path_is_within(string $path, string $root): bool
{
    $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
    if ($path === '') {
        $path = DIRECTORY_SEPARATOR;
    }
    if ($root === '') {
        $root = DIRECTORY_SEPARATOR;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }

    $prefix = $root === DIRECTORY_SEPARATOR ? DIRECTORY_SEPARATOR : $root . DIRECTORY_SEPARATOR;
    return $path === $root || str_starts_with($path, $prefix);
}

function bf_submission_storage_root(): string
{
    $projectRoot = realpath(BASE_PATH);
    if ($projectRoot === false) {
        throw new RuntimeException('Unable to resolve the application directory.');
    }

    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $documentRoot = is_string($documentRoot) && $documentRoot !== ''
        ? realpath($documentRoot)
        : false;
    $configured = trim((string) env_value('SUBMISSION_STORAGE_PATH', ''));
    if ($documentRoot === false && $configured === '') {
        throw new RuntimeException(
            'Set SUBMISSION_STORAGE_PATH when the document root cannot be determined.'
        );
    }

    if ($configured !== '') {
        $isAbsolute = DIRECTORY_SEPARATOR === '\\'
            ? preg_match('~^(?:[A-Za-z]:[\\\\/]|\\\\\\\\)~', $configured) === 1
            : str_starts_with($configured, '/');
        if (!$isAbsolute) {
            throw new RuntimeException('SUBMISSION_STORAGE_PATH must be an absolute path.');
        }
        if ($documentRoot !== false && bf_submission_path_is_within($configured, $documentRoot)) {
            throw new RuntimeException('Submission storage must be outside the document root.');
        }
        if (
            $documentRoot === false
            && bf_submission_path_is_within($configured, $projectRoot)
        ) {
            throw new RuntimeException('Submission storage must be outside the application directory.');
        }
        $path = $configured;
    } else {
        $parent = dirname($projectRoot);
        while ($documentRoot !== false && bf_submission_path_is_within($parent, $documentRoot)) {
            $next = dirname($parent);
            if ($next === $parent) {
                throw new RuntimeException('Unable to find a directory outside the document root.');
            }
            $parent = $next;
        }
        $path = $parent . DIRECTORY_SEPARATOR . 'brightfuture-private-storage';
    }

    if (is_link($path)) {
        throw new RuntimeException('Submission storage cannot be a symbolic link.');
    }
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create private submission storage.');
    }

    $realPath = realpath($path);
    if ($realPath === false || !is_dir($realPath)) {
        throw new RuntimeException('Unable to resolve private submission storage.');
    }
    if ($documentRoot !== false && bf_submission_path_is_within($realPath, $documentRoot)) {
        throw new RuntimeException('Submission storage must be outside the document root.');
    }

    return $realPath;
}

function bf_submission_storage_dir(): string
{
    $root = bf_submission_storage_root();
    $path = $root . DIRECTORY_SEPARATOR . 'submissions';
    if (is_link($path)) {
        throw new RuntimeException('Submission storage directory cannot be a symbolic link.');
    }
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create private submission storage.');
    }

    $realPath = realpath($path);
    if (
        $realPath === false
        || !is_dir($realPath)
        || !bf_submission_path_is_within($realPath, $root)
    ) {
        throw new RuntimeException('Unable to resolve private submission storage.');
    }

    return $realPath;
}

function bf_submission_file_key(string $fileName): string
{
    if (!preg_match('/^[a-f0-9]{40,64}\.[a-z0-9]{1,10}$/D', $fileName)) {
        throw new InvalidArgumentException('Invalid submission file name.');
    }

    return 'private-submissions/' . $fileName;
}

function bf_submission_resolve_file(string $storedPath): ?string
{
    $storedPath = str_replace('\\', '/', $storedPath);
    $isPrivate = str_starts_with($storedPath, 'private-submissions/');
    $isLegacy = str_starts_with($storedPath, 'uploads/submissions/');
    if ($isPrivate) {
        $fileName = substr($storedPath, strlen('private-submissions/'));
        $root = bf_submission_storage_dir();
    } elseif ($isLegacy) {
        $fileName = substr($storedPath, strlen('uploads/submissions/'));
        $legacyRoot = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'submissions';
        if (is_link($legacyRoot)) {
            return null;
        }
        $root = realpath($legacyRoot);
        if ($root === false || !is_dir($root)) {
            return null;
        }
    } elseif (preg_match('/^[a-f0-9]{40}\.[a-z0-9]{1,10}$/D', $storedPath)) {
        $fileName = $storedPath;
        $legacyRoot = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'submissions';
        if (is_link($legacyRoot)) {
            return null;
        }
        $root = realpath($legacyRoot);
        if ($root === false || !is_dir($root)) {
            return null;
        }
    } else {
        return null;
    }

    if (!preg_match('/^[a-f0-9]{40,64}\.[a-z0-9]{1,10}$/D', $fileName)) {
        return null;
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . $fileName);
    if (
        $path === false
        || !bf_submission_path_is_within($path, $root)
        || !is_file($path)
        || !is_readable($path)
    ) {
        return null;
    }

    return $path;
}
