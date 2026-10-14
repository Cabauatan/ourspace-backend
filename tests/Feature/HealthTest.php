<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_returns_the_public_api_contract(): void
    {
        $this->getJson('/api/health')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.service', 'OurSpace API')
            ->assertJsonStructure(['success', 'message', 'data' => ['status', 'service', 'timestamp']])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_allowed_frontend_origin_receives_cors_headers(): void
    {
        $this->withHeader('Origin', 'http://127.0.0.1:5173')->getJson('/api/health')
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5173');
    }

    public function test_untrusted_origin_is_not_allowed_by_cors(): void
    {
        $this->withHeader('Origin', 'https://untrusted.example')->getJson('/api/health')
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_bearer_header_is_allowed_in_preflight(): void
    {
        $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5173',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/health')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5173');
    }

    public function test_missing_api_route_returns_a_safe_json_error(): void
    {
        $this->get('/api/missing')->assertNotFound()->assertExactJson([
            'success' => false, 'message' => 'The requested resource was not found.',
        ]);
    }

    public function test_wrong_method_returns_a_consistent_error(): void
    {
        $this->postJson('/api/health')->assertStatus(405)->assertJsonPath('success', false);
    }

    public function test_api_server_errors_do_not_expose_internal_details(): void
    {
        Route::get('/api/test-failure', function (): void {
            throw new RuntimeException('Internal connection details must remain private.');
        });

        $this->getJson('/api/test-failure')->assertInternalServerError()->assertExactJson([
            'success' => false,
            'message' => 'OurSpace is temporarily unavailable.',
        ]);
    }

    public function test_validation_errors_preserve_field_messages(): void
    {
        Route::post('/api/test-validation', function (): void {
            throw ValidationException::withMessages(['title' => ['The title field is required.']]);
        });

        $this->postJson('/api/test-validation')->assertUnprocessable()->assertExactJson([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => ['title' => ['The title field is required.']],
        ]);
    }
}
