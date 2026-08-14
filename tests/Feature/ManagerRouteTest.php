<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Feature;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Jayesh\LaravelGeminiTranslator\Services\ManagerCatalogService;
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

    public function test_composer_vendor_lang_is_not_a_pack(): void
    {
        $this->signIn();
        $map = $this->app->make(ManagerCatalogService::class)->packMap();
        foreach ($map as $packs) {
            foreach ($packs as $pack) {
                $this->assertStringNotContainsString('vendor/laravel/framework', $pack);
            }
        }
    }

    public function test_data_endpoint_reads_registered_custom_json_dir(): void
    {
        $custom = base_path('custom_dir');
        File::ensureDirectoryExists($custom);
        File::put($custom . '/en.json', json_encode(['Custom greeting' => 'From custom'], JSON_UNESCAPED_UNICODE));
        $this->app->make(Translator::class)->addJsonPath($custom);

        $this->signIn();
        $response = $this->getJson('/translations-manager/data?' . http_build_query([
            'type' => 'non-module',
            'pack' => 'custom_dir',
            'limit' => 'All',
        ]));

        $response->assertOk();
        $this->assertContains('Custom greeting', array_column($response->json('rows'), 'key'));
        $row = collect($response->json('rows'))->firstWhere('key', 'Custom greeting');
        $this->assertSame('custom_dir', $row['pack'] ?? null);
        $this->assertSame('From custom', $row['en'] ?? null);
    }

    public function test_module_packs_are_scanned_separately(): void
    {
        File::deleteDirectory(resource_path('lang/modules'));
        $blog = base_path('Modules/Blog/lang');
        File::ensureDirectoryExists($blog);
        File::put($blog . '/en.json', json_encode(['Hello' => 'Root hello'], JSON_UNESCAPED_UNICODE));
        File::ensureDirectoryExists($blog . '/app3');
        File::put($blog . '/app3/en.json', json_encode(['Hello' => 'App3 hello'], JSON_UNESCAPED_UNICODE));
        File::ensureDirectoryExists($blog . '/web');
        File::put($blog . '/web/en.json', json_encode(['Hello' => 'Web hello'], JSON_UNESCAPED_UNICODE));
        File::ensureDirectoryExists($blog . '/app3/en');
        File::put($blog . '/app3/en/messages.php', "<?php\n\nreturn ['title' => 'App3 title'];\n");

        $this->app->make(Translator::class)->addJsonPath($blog);
        $this->app->make(Translator::class)->addJsonPath($blog . '/app3');
        $this->app->make(Translator::class)->addJsonPath($blog . '/web');
        $this->app->make(Translator::class)->addNamespace('blog', $blog);

        $this->signIn();

        $all = $this->getJson('/translations-manager/data?' . http_build_query([
            'type' => 'module',
            'module' => 'Blog',
            'limit' => 'All',
        ]));
        $all->assertOk();
        $hello = array_values(array_filter(
            $all->json('rows'),
            static fn(array $row): bool => $row['key'] === 'Hello',
        ));
        $this->assertCount(3, $hello);
        $byPack = [];
        foreach ($hello as $row) {
            $byPack[$row['pack']] = $row['en'];
        }
        $this->assertSame('Root hello', $byPack[''] ?? null);
        $this->assertSame('App3 hello', $byPack['app3'] ?? null);
        $this->assertSame('Web hello', $byPack['web'] ?? null);

        $app3 = $this->getJson('/translations-manager/data?' . http_build_query([
            'type' => 'module',
            'module' => 'Blog',
            'pack' => 'app3',
            'limit' => 'All',
        ]));
        $app3->assertOk();
        $keys = array_column($app3->json('rows'), 'key');
        $this->assertContains('Hello', $keys);
        $this->assertContains('messages.title', $keys);
        foreach ($app3->json('rows') as $row) {
            $this->assertSame('app3', $row['pack']);
        }
        $helloApp3 = collect($app3->json('rows'))->firstWhere('key', 'Hello');
        $this->assertSame('App3 hello', $helloApp3['en'] ?? null);
    }

    public function test_published_module_lang_overrides_module_path(): void
    {
        $module = base_path('Modules/Blog/lang');
        $published = resource_path('lang/modules/Blog');
        File::ensureDirectoryExists($module);
        File::ensureDirectoryExists($published);
        File::put($module . '/en.json', json_encode(['Hello' => 'Module copy'], JSON_UNESCAPED_UNICODE));
        File::put($published . '/en.json', json_encode(['Hello' => 'Published copy'], JSON_UNESCAPED_UNICODE));

        $this->signIn();
        try {
            $response = $this->getJson('/translations-manager/data?' . http_build_query([
                'type' => 'module',
                'module' => 'Blog',
                'pack' => '__root__',
                'limit' => 'All',
            ]));

            $response->assertOk();
            $hello = collect($response->json('rows'))->firstWhere('key', 'Hello');
            $this->assertSame('Published copy', $hello['en'] ?? null);
        } finally {
            File::deleteDirectory($published);
        }
    }

    public function test_save_writes_into_selected_module_pack(): void
    {
        $web = base_path('Modules/Blog/lang/web');
        File::ensureDirectoryExists($web);
        File::put($web . '/en.json', json_encode(['Hello' => 'Web hello'], JSON_UNESCAPED_UNICODE));

        $this->signIn();
        $this->postJson('/translations-manager/save', [
            'changes' => [[
                'lang' => 'en',
                'module' => 'Blog',
                'pack' => 'web',
                'scope' => 'json',
                'key' => 'Hello',
                'value' => 'Saved web',
            ]],
        ])->assertOk();

        $data = json_decode((string) File::get($web . '/en.json'), true);
        $this->assertSame('Saved web', $data['Hello'] ?? null);
    }

    public function test_selected_language_only_lists_keys_from_that_locale_file(): void
    {
        File::deleteDirectory(resource_path('lang/modules'));
        $app3 = base_path('Modules/Blog/lang/app3');
        File::deleteDirectory($app3);
        File::ensureDirectoryExists($app3 . '/en');
        File::ensureDirectoryExists($app3 . '/hi');
        File::put($app3 . '/en/messages.php', "<?php\n\nreturn ['comments' => ['errors1' => 'English only']];\n");
        File::put($app3 . '/hi/messages.php', "<?php\n\nreturn ['comments' => ['errors' => 'Hindi only']];\n");

        $this->signIn();
        $response = $this->getJson('/translations-manager/data?' . http_build_query([
            'type' => 'module',
            'module' => 'Blog',
            'pack' => 'app3',
            'scope' => 'php',
            'language' => 'en',
            'limit' => 'All',
        ]));

        $response->assertOk();
        $keys = array_column($response->json('rows'), 'key');
        $this->assertSame(['messages.comments.errors1'], $keys);
        $this->assertSame(['messages.php'], $response->json('files'));
    }

    public function test_module_php_file_filter_limits_groups_in_the_selected_pack(): void
    {
        File::deleteDirectory(resource_path('lang/modules'));
        $app3 = base_path('Modules/Blog/lang/app3');
        File::deleteDirectory($app3);
        File::ensureDirectoryExists($app3 . '/en');
        File::put($app3 . '/en/messages.php', "<?php\n\nreturn ['title' => 'Title'];\n");
        File::put($app3 . '/en/validation.php', "<?php\n\nreturn ['required' => 'Required'];\n");

        $this->signIn();
        $response = $this->getJson('/translations-manager/data?' . http_build_query([
            'type' => 'module',
            'module' => 'Blog',
            'pack' => 'app3',
            'scope' => 'php',
            'files' => ['messages.php'],
            'limit' => 'All',
        ]));

        $response->assertOk();
        $keys = array_column($response->json('rows'), 'key');
        $this->assertContains('messages.title', $keys);
        $this->assertNotContains('validation.required', $keys);
        $this->assertEqualsCanonicalizing(['messages.php', 'validation.php'], $response->json('files'));
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
