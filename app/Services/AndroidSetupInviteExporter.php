<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class AndroidSetupInviteExporter
{
    public function write(string $code, CarbonInterface $expiresAt): void
    {
        $workspace = realpath(dirname(base_path()));
        if ($workspace === false) {
            throw new RuntimeException('The application workspace is unavailable.');
        }
        $frontend = $workspace.DIRECTORY_SEPARATOR.'ourspace-frontend';
        $path = $frontend.DIRECTORY_SEPARATOR.'.env.android-debug';
        if (is_link($frontend) || realpath($frontend) !== $frontend || is_link($path) || ! is_file($path) || realpath($path) !== $path) {
            throw new RuntimeException('The existing Android debug environment is unavailable.');
        }
        if (! preg_match('/\Asetup-[a-f0-9]{64}\z/', $code)) {
            throw new RuntimeException('The setup invite format is invalid.');
        }

        try {
            $existing = File::get($path);
            $newline = str_contains($existing, "\r\n") ? "\r\n" : "\n";
            $content = preg_replace('/^[ \t]*(?:export[ \t]+)?OURSPACE_SETUP_(?:CODE|EXPIRES_AT)[ \t]*=.*(?:\r?\n|$)/m', '', $existing);
            if ($content !== '' && ! str_ends_with($content, "\n")) {
                $content .= $newline;
            }
            $content .= 'OURSPACE_SETUP_CODE='.$code.$newline;
            $content .= 'OURSPACE_SETUP_EXPIRES_AT='.$expiresAt->toIso8601String().$newline;
            File::replace($path, $content, 0600);
            if (File::get($path) !== $content) {
                throw new RuntimeException('The Android debug environment was not saved.');
            }
        } catch (Throwable) {
            throw new RuntimeException('The Android debug environment could not be updated.');
        }
    }
}
