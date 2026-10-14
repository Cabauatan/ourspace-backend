<?php

namespace App\Console\Commands;

use App\Services\AndroidSetupInviteExporter;
use App\Services\DeviceService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SetupOurSpace extends Command
{
    protected $signature = 'ourspace:setup {--partner-one=Partner One} {--partner-two=Partner Two} {--rotate-recovery : Replace only the recovery code, preserving devices and shared data} {--refresh-setup-for-apk : Renew the setup invite for an unpaired couple and export it privately to the existing Android debug build environment}';

    protected $description = 'Initialize the single private couple and display setup/recovery codes once.';

    public function handle(DeviceService $devices, AndroidSetupInviteExporter $exporter): int
    {
        if ($this->option('refresh-setup-for-apk')) {
            if ($this->option('rotate-recovery')) {
                $this->error('Choose either --refresh-setup-for-apk or --rotate-recovery.');

                return self::FAILURE;
            }

            try {
                $expiresAt = $devices->refreshUnpairedSetupCode(fn (string $code, CarbonInterface $expiry) => $exporter->write($code, $expiry));
            } catch (ValidationException $exception) {
                $this->error(collect($exception->errors())->flatten()->first());

                return self::FAILURE;
            } catch (RuntimeException) {
                $this->error('The Android debug environment could not be updated. The previous setup invite remains valid.');

                return self::FAILURE;
            }

            $this->info('Temporary setup invite refreshed and saved privately for the Android debug build.');
            $this->line('Expires at: '.$expiresAt->toIso8601String());
            $this->line('Partner names, recovery code, and shared data were preserved.');

            return self::SUCCESS;
        }

        if ($this->option('rotate-recovery')) {
            $this->line('New recovery code: '.$devices->rotateRecoveryCode());
            $this->warn('Keep this code privately. The previous recovery code no longer works.');

            return self::SUCCESS;
        }

        $one = trim((string) $this->option('partner-one'));
        $two = trim((string) $this->option('partner-two'));
        if ($one === '' || $two === '' || mb_strlen($one) > 80 || mb_strlen($two) > 80) {
            $this->error('Provide two partner names with 1–80 characters.');

            return self::FAILURE;
        }
        try {
            $result = $devices->initialize($one, $two);
        } catch (ValidationException) {
            $this->error('OurSpace is already initialized. Use --rotate-recovery only when you need a new recovery code.');

            return self::FAILURE;
        }
        $this->info('OurSpace initialized. These codes are displayed once; only hashes are stored.');
        $this->line('Setup code (valid for 24 hours, retired after both partners join): '.$result['setup_code']);
        $this->line('Recovery code: '.$result['recovery_code']);
        $this->warn('Save the recovery code in a password manager. Do not share these codes outside your couple.');

        return self::SUCCESS;
    }
}
