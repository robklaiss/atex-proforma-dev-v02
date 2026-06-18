<?php

declare(strict_types=1);

function createDatabaseBackup(): ?string
{
    ensureStorageDirectories();

    if (!is_file(SQLITE_PATH)) {
        return null;
    }

    $base = BACKUP_PATH . '/backup_' . date('Y-m-d_H-i-s') . '.sqlite';
    $target = $base;
    $counter = 1;
    while (is_file($target)) {
        $target = substr($base, 0, -7) . '_' . $counter . '.sqlite';
        $counter++;
    }

    if (!copy(SQLITE_PATH, $target)) {
        throw new RuntimeException('No se pudo crear el backup de la base de datos.');
    }

    cleanupOldBackups();
    return $target;
}

function listDatabaseBackups(): array
{
    ensureStorageDirectories();
    $files = glob(BACKUP_PATH . '/*.sqlite') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    return array_map(static function (string $path): array {
        return [
            'name' => basename($path),
            'path' => $path,
            'mtime' => filemtime($path) ?: 0,
            'size' => filesize($path) ?: 0,
        ];
    }, $files);
}

function cleanupOldBackups(): void
{
    $backups = listDatabaseBackups();
    if (count($backups) <= MIN_BACKUPS_TO_KEEP) {
        return;
    }

    usort($backups, static fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
    $cutoff = time() - (BACKUP_RETENTION_DAYS * 86400);
    $remaining = count($backups);

    foreach ($backups as $backup) {
        if ($remaining <= MIN_BACKUPS_TO_KEEP) {
            break;
        }
        if ($backup['mtime'] < $cutoff && is_file($backup['path'])) {
            unlink($backup['path']);
            $remaining--;
        }
    }
}

function resolveBackupPath(string $filename): string
{
    $name = safeBasename($filename);
    if (!str_ends_with($name, '.sqlite')) {
        throw new RuntimeException('Archivo de backup invalido.');
    }

    $path = BACKUP_PATH . '/' . $name;
    $realBackupDir = realpath(BACKUP_PATH);
    $realPath = realpath($path);

    if (!$realBackupDir || !$realPath || !str_starts_with($realPath, $realBackupDir . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('No se encontro el backup solicitado.');
    }

    return $realPath;
}

function restoreDatabaseBackup(string $filename): void
{
    ensureStorageDirectories();
    $backupPath = resolveBackupPath($filename);

    createDatabaseBackup();
    closeDb();

    if (!copy($backupPath, SQLITE_PATH)) {
        throw new RuntimeException('No se pudo restaurar el backup.');
    }
}

function formatFileSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return formatNumber($bytes / 1048576) . ' MB';
    }
    if ($bytes >= 1024) {
        return formatNumber(round($bytes / 1024, 1)) . ' KB';
    }
    return formatInteger($bytes) . ' B';
}
