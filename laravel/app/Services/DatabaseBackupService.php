<?php

namespace App\Services;

class DatabaseBackupService
{
    /**
     * Create a backup of the database.
     *
     * @param  callable|null  $logger
     * @return string|bool The path to the backup file or false on failure.
     */
    public function backupDatabase($logger = null)
    {
        $backupDir = storage_path('backups');

        if (! file_exists($backupDir)) {
            if (! mkdir($backupDir, 0777, true) && ! is_dir($backupDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $backupDir));
            }
        }

        $backupFilePath = $backupDir.'/laravelbreezestarter_'.now()->format('Y_m_d_H_i_s').'.sql';

        $dbName = env('DB_DATABASE');
        $dbUser = env('DB_USERNAME');
        $dbPassword = env('DB_PASSWORD');
        $dbHost = env('DB_HOST');

        $command = sprintf(
            'mysqldump --user=%s --password=%s --host=%s %s > %s',
            escapeshellarg($dbUser),
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbName),
            escapeshellarg($backupFilePath)
        );

        $output = null;
        $returnVar = null;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            $logger && $logger('Database backup failed. Command output: '.implode("\n", $output));

            return false;
        }

        $logger && $logger('Database backed up to: '.$backupFilePath);

        return $backupFilePath;
    }
}
