<?php

namespace Tests\Feature;

use App\Models\CoupleProfile;
use App\Models\Device;
use App\Services\AndroidSetupInviteExporter;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SetupOurSpaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ?string $fixtureDirectory = null;

    protected function tearDown(): void
    {
        if ($this->fixtureDirectory !== null) {
            File::deleteDirectory($this->fixtureDirectory);
        }

        parent::tearDown();
    }

    public function test_refresh_exports_a_working_temporary_invite_and_preserves_the_couple_and_environment(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->startOfDay());
        $couple = CoupleProfile::factory()->create([
            'id' => 1,
            'partner_one_name' => 'Marcel',
            'partner_two_name' => 'My partner',
            'relationship_start_date' => '2020-01-02',
            'anniversary_date' => '2020-06-03',
        ]);
        $existing = "# Private Android debug build\r\nVITE_API_BASE_URL=http://192.168.100.51:8000/api\r\nVITE_REVERB_HOST=192.168.100.51\r\nVITE_REVERB_PORT=8080\r\nVITE_REVERB_SCHEME=http\r\nVITE_REVERB_APP_KEY=test-public-reverb-key\r\n";
        $path = $this->environment($existing."OURSPACE_SETUP_CODE=old-invite\r\nOURSPACE_SETUP_EXPIRES_AT=old-expiry\r\n");

        [$status, $output] = $this->refreshInvite();

        $this->assertSame(0, $status);
        $values = Dotenv::parse(File::get($path));
        $code = $values['OURSPACE_SETUP_CODE'];
        $this->assertMatchesRegularExpression('/\Asetup-[a-f0-9]{64}\z/', $code);
        $this->assertSame('2026-10-13T00:00:00+00:00', $values['OURSPACE_SETUP_EXPIRES_AT']);
        $this->assertSame($existing.'OURSPACE_SETUP_CODE='.$code."\r\nOURSPACE_SETUP_EXPIRES_AT=2026-10-13T00:00:00+00:00\r\n", File::get($path));
        $this->assertStringNotContainsString($code, $output);
        $this->assertStringNotContainsString('test-recovery-code', $output);
        $this->assertDatabaseHas('couple_profiles', [
            'id' => 1,
            'partner_one_name' => 'Marcel',
            'partner_two_name' => 'My partner',
            'setup_code_hash' => hash('sha256', $code),
            'recovery_code_hash' => $couple->recovery_code_hash,
        ]);
        $this->assertSame('2020-01-02', $couple->fresh()->relationship_start_date->toDateString());
        $this->assertSame('2020-06-03', $couple->fresh()->anniversary_date->toDateString());

        $this->postJson('/api/device/register', $this->registration('test-setup-code'))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/device/register', $this->registration($code))->assertCreated();
        $this->postJson('/api/device/register', $this->registration('test-recovery-code', 'partner_two'))->assertCreated();
    }

    #[DataProvider('phoneHistory')]
    public function test_refresh_rejects_a_couple_with_any_phone_history_and_preserves_the_old_invite(?string $revokedAt): void
    {
        $couple = CoupleProfile::factory()->create(['id' => 1]);
        Device::factory()->for($couple, 'couple')->create(['revoked_at' => $revokedAt]);
        $existing = "VITE_API_BASE_URL=http://192.168.100.51:8000/api\n";
        $path = $this->environment($existing);

        [$status, $output] = $this->refreshInvite();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('A phone has already joined this couple.', $output);
        $this->assertSame($existing, File::get($path));
        $this->assertSame($couple->setup_code_hash, $couple->fresh()->setup_code_hash);
        $this->assertSame($couple->recovery_code_hash, $couple->fresh()->recovery_code_hash);
        $this->assertDatabaseCount('devices', 1);
    }

    public static function phoneHistory(): array
    {
        return ['active phone' => [null], 'revoked phone' => ['2026-10-11 00:00:00']];
    }

    public function test_refresh_rejects_an_uninitialized_backend_without_changing_the_environment(): void
    {
        $this->assertDatabaseCount('couple_profiles', 0);
        $existing = "VITE_REVERB_HOST=192.168.100.51\n";
        $path = $this->environment($existing);

        [$status, $output] = $this->refreshInvite();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Initialize OurSpace', $output);
        $this->assertSame($existing, File::get($path));
        $this->assertDatabaseCount('couple_profiles', 0);
    }

    public function test_environment_write_failure_rolls_back_the_invite_change(): void
    {
        $couple = CoupleProfile::factory()->create(['id' => 1]);
        $this->mock(AndroidSetupInviteExporter::class)->shouldReceive('write')->once()->andThrow(new RuntimeException('Cannot write environment.'));

        $status = Artisan::call('ourspace:setup', ['--refresh-setup-for-apk' => true, '--no-interaction' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('The previous setup invite remains valid.', Artisan::output());
        $this->assertSame($couple->setup_code_hash, $couple->fresh()->setup_code_hash);
        $this->assertSame($couple->setup_code_expires_at->toIso8601String(), $couple->fresh()->setup_code_expires_at->toIso8601String());
        $this->postJson('/api/device/register', $this->registration('test-setup-code'))->assertCreated();
    }

    public function test_missing_debug_environment_preserves_the_existing_setup_invite(): void
    {
        $couple = CoupleProfile::factory()->create(['id' => 1]);
        $path = $this->environment('');
        File::delete($path);

        [$status] = $this->refreshInvite();

        $this->assertSame(1, $status);
        $this->assertFileDoesNotExist($path);
        $this->assertSame($couple->setup_code_hash, $couple->fresh()->setup_code_hash);
    }

    public function test_refresh_cannot_be_combined_with_recovery_rotation(): void
    {
        $couple = CoupleProfile::factory()->create(['id' => 1]);

        $status = Artisan::call('ourspace:setup', ['--refresh-setup-for-apk' => true, '--rotate-recovery' => true, '--no-interaction' => true]);

        $this->assertSame(1, $status);
        $this->assertSame($couple->setup_code_hash, $couple->fresh()->setup_code_hash);
        $this->assertSame($couple->recovery_code_hash, $couple->fresh()->recovery_code_hash);
        $this->assertStringNotContainsString('recovery-', Artisan::output());
    }

    private function environment(string $content): string
    {
        Artisan::all();
        $this->fixtureDirectory = storage_path('framework/testing/setup-export-'.Str::uuid());
        File::makeDirectory($this->fixtureDirectory.DIRECTORY_SEPARATOR.'ourspace-backend', 0755, true);
        File::makeDirectory($this->fixtureDirectory.DIRECTORY_SEPARATOR.'ourspace-frontend');
        $path = $this->fixtureDirectory.DIRECTORY_SEPARATOR.'ourspace-frontend'.DIRECTORY_SEPARATOR.'.env.android-debug';
        File::put($path, $content);

        return $path;
    }

    private function refreshInvite(): array
    {
        $originalBasePath = base_path();
        $this->app->setBasePath($this->fixtureDirectory.DIRECTORY_SEPARATOR.'ourspace-backend');
        try {
            $status = Artisan::call('ourspace:setup', ['--refresh-setup-for-apk' => true, '--no-interaction' => true]);

            return [$status, Artisan::output()];
        } finally {
            $this->app->setBasePath($originalBasePath);
        }
    }

    private function registration(string $code, string $partner = 'partner_one'): array
    {
        return ['code' => $code, 'partner_type' => $partner, 'device_name' => 'Test phone', 'device_identifier' => (string) Str::uuid()];
    }
}
