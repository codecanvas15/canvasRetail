<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class RotateLogs extends Command
{
    protected $signature = 'logs:rotate';
    protected $description = 'Rotates log files if they exceed 10MB';

    public function handle()
    {
        $logPath = storage_path('logs/laravel.log');
        // $maxSize = 1;
        $maxSize = 10 * 1024 * 1024;

        if (File::exists($logPath) && File::size($logPath) > $maxSize) {
            $archivePath = storage_path('logs/laravel-' . Carbon::now()->format('Y-m-d_H-i-s') . '.log');

            // Move the log file to an archive file
            File::move($logPath, $archivePath);

            // Create a new empty log file
            File::put($logPath, '');

            $this->info('Log rotated: ' . $archivePath);
        } else {
            $this->info('Log size is under 10MB. No rotation needed.');
        }

        $files = File::files(storage_path('logs'));

        foreach ($files as $file) {
            $lastModified = Carbon::createFromTimestamp(File::lastModified($file));

            if (Carbon::now()->diffInDays($lastModified) > 7) {
                File::delete($file);
            }
        }

    }
}
