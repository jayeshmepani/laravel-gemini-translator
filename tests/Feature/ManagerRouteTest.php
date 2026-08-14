<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Jayesh\LaravelGeminiTranslator\Tests\Fakes\ManagerUser;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class ManagerRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        File::ensureDirectoryExists(lang_path());
        File::put(lang_path('en.json'), json_encode([
            'messages.welcome' => 'Welcome',
            'Hello' => 'Hello',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function test_guest_can_open_the_manager_when_the_app_has_no_auth_routes(): void
    {
        $this->get('/translations-manager')
            ->assertOk()
            ->assertSee('Translation Manager', false);
    }

    public function test_guest_must_sign_in_when_the_app_has_a_login_route(): void
    {
        Route::get('/login', static fn(): string => 'login-form')->name('login');

        $this->get('/translations-manager')
            ->assertRedirect('/login');

        $this->getJson('/translations-manager/data')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'Unauthenticated.');

        $this->postJson('/translations-manager/save', [
            'changes' => [[
                'lang' => 'en',
                'module' => '',
                'scope' => 'json',
                'key' => 'Hello',
                'value' => 'Nope',
            ]],
        ])->assertUnauthorized();

        $this->postJson('/translations-manager/scan')->assertUnauthorized();
        $this->postJson('/translations-manager/add-languages', [
            'languages' => ['fr'],
        ])->assertUnauthorized();
    }

    public function test_manager_page_is_registered(): void
    {
        $this->signIn();

        $this->get('/translations-manager')
            ->assertOk()
            ->assertSee('Translation Manager', false)
            ->assertSee('/translations-manager/data', false);
    }

    public function test_named_routes_exist(): void
    {
        $this->assertTrue(route('gemini-translator.manager.show') !== '');
        $this->assertStringEndsWith('/translations-manager', route('gemini-translator.manager.show'));
        $this->assertStringEndsWith('/translations-manager/data', route('gemini-translator.manager.data'));
    }

    public function test_data_endpoint_returns_lang_rows(): void
    {
        $this->signIn();
        $response = $this->getJson('/translations-manager/data');

        $response->assertOk()
            ->assertJsonFragment(['key' => 'messages.welcome', 'en' => 'Welcome']);
        $this->assertGreaterThanOrEqual(2, $response->json('total'));
    }

    public function test_save_endpoint_writes_json(): void
    {
        $this->signIn();
        $this->postJson('/translations-manager/save', [
            'changes' => [[
                'lang' => 'en',
                'module' => '',
                'scope' => 'json',
                'key' => 'Hello',
                'value' => 'Hi there',
            ]],
        ])->assertOk()->assertJsonPath('message', 'Translations saved.');

        $data = json_decode((string) File::get(lang_path('en.json')), true);
        $this->assertSame('Hi there', $data['Hello'] ?? null);
    }

    public function test_add_languages_succeeds_when_one_module_lang_is_not_writable(): void
    {
        $blogLang = base_path('Modules/Blog/lang');
        File::ensureDirectoryExists($blogLang);
        File::put($blogLang . '/en.json', json_encode(['Hi' => 'Hi']));
        chmod($blogLang, 0555);

        try {
            File::delete(lang_path('pt.json'));
            $this->postJson('/translations-manager/add-languages', [
                'languages' => ['pt'],
            ])->assertOk()->assertJsonPath('message', 'Languages added.');

            $this->assertFileExists(lang_path('pt.json'));
        } finally {
            chmod($blogLang, 0775);
        }
    }

    public function test_non_module_file_filter_limits_php_groups(): void
    {
        File::ensureDirectoryExists(lang_path('en'));
        File::put(lang_path('en/messages.php'), "<?php\n\nreturn ['welcome' => 'Welcome'];\n");
        File::put(lang_path('en/validation.php'), "<?php\n\nreturn ['required' => 'Required'];\n");

        $this->signIn();
        $response = $this->getJson('/translations-manager/data?' . http_build_query([
            'type' => 'non-module',
            'scope' => 'php',
            'files' => ['validation.php'],
            'limit' => 'All',
        ]));

        $response->assertOk();
        $keys = array_column($response->json('rows'), 'key');
        $this->assertContains('validation.required', $keys);
        $this->assertNotContains('messages.welcome', $keys);
    }

    public function test_disabled_manager_returns_not_found(): void
    {
        config(['gemini-translator.manager.enabled' => false]);

        $this->signIn();
        $this->get('/translations-manager')->assertNotFound();
    }

    private function signIn(): ManagerUser
    {
        $user = new ManagerUser(['id' => 1, 'email' => 'editor@example.com', 'name' => 'Editor']);
        $this->actingAs($user);

        return $user;
    }
}
