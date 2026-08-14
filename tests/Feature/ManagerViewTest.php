<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Feature;

use Jayesh\LaravelGeminiTranslator\Tests\TestCase;
use Jayesh\LaravelGeminiTranslator\TranslationServiceProvider;

class ManagerViewTest extends TestCase
{
    public function test_manager_view_renders_semantic_workspace(): void
    {
        $html = view('gemini-translator::manager', [
            'modules' => ['Blog'],
            'scopes' => ['json', 'php'],
            'languages' => ['en', 'hi', 'gu'],
            'languageNames' => ['English' => 'en', 'Hindi' => 'hi', 'Gujarati' => 'gu'],
        ])->render();

        $this->assertStringContainsString('Translation Manager', $html);
        $this->assertStringContainsString('class="translation-manager"', $html);
        $this->assertStringContainsString('class="manager-filters"', $html);
        $this->assertStringContainsString('class="manager-button manager-button-save"', $html);
        $this->assertStringContainsString('Blog', $html);
        $this->assertStringContainsString('manager-select', $html);
        $this->assertStringContainsString('Skip to translations', $html);
        $this->assertStringContainsString('href="#manager-main"', $html);
        $this->assertStringContainsString('<search', $html);
        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringContainsString('<fieldset', $html);
        $this->assertStringContainsString('<caption', $html);
        $this->assertStringContainsString('data-skeleton', $html);
        $this->assertStringContainsString('data-empty', $html);
        $this->assertStringContainsString('data-error', $html);
        $this->assertStringContainsString('popover="auto"', $html);
        $this->assertStringContainsString('resize: none', $html);
        $this->assertStringContainsString('@layer', $html);
        $this->assertStringContainsString('--color-surface', $html);
        $this->assertStringContainsString('prefers-reduced-motion', $html);
        $this->assertStringContainsString('forced-colors', $html);
        $this->assertStringContainsString('appearance: base-select', $html);
        $this->assertStringContainsString('::picker(select)', $html);
        $this->assertStringContainsString('input[type="checkbox"]', $html);
        $this->assertStringContainsString('showModal', $html);
        $this->assertStringContainsString('AbortController', $html);
        $this->assertStringNotContainsString('container-xl', $html);
        $this->assertStringNotContainsString('btn btn-success', $html);
        $this->assertStringNotContainsString('d-flex', $html);
        $this->assertStringContainsString('.manager-shell', $html);
        $this->assertStringContainsString('data-translation-manager', $html);
        $this->assertStringContainsString('manager-key-head', $html);
        $this->assertStringContainsString('inset-inline-start: 0', $html);
        $this->assertStringContainsString('role="switch"', $html);
        $this->assertStringContainsString('data-action="theme"', $html);
        $this->assertStringContainsString('manager-theme-sun', $html);
        $this->assertStringContainsString('manager-theme-moon', $html);
        $this->assertStringContainsString('gemini-translator-theme', $html);
        $this->assertStringContainsString('data-theme="dark"', $html);
        $this->assertStringContainsString('Use dark theme', $html);
        $this->assertStringContainsString('<option value="5" selected>5</option>', $html);
        $this->assertStringContainsString('<option value="10">10</option>', $html);
        $this->assertStringContainsString('<option value="15">15</option>', $html);
        $this->assertStringContainsString('rows per page', $html);
        $this->assertStringContainsString('manager-pager-status', $html);
        $this->assertStringContainsString('data-file-filter', $html);
        $this->assertStringContainsString('data-files-menu', $html);
    }

    public function test_manager_view_uses_composer_defaults_and_inlines_assets_once(): void
    {
        $html = view('gemini-translator::manager')->render();

        $this->assertStringContainsString('Translation Manager', $html);
        $this->assertStringContainsString('Browse, edit and save translations', $html);
        $this->assertSame(1, substr_count($html, '@layer reset, tokens'));
        $this->assertSame(1, substr_count($html, 'new AbortController'));
        $this->assertSame(1, substr_count($html, '<style>'));
    }

    public function test_manager_publish_tags_are_registered(): void
    {
        $publishGroups = TranslationServiceProvider::pathsToPublish(TranslationServiceProvider::class);

        $this->assertNotEmpty($publishGroups);

        $tagged = TranslationServiceProvider::pathsToPublish(TranslationServiceProvider::class, 'gemini-translator-manager');
        $this->assertNotEmpty($tagged);

        $destinations = array_values($tagged);
        $this->assertTrue(collect($destinations)->contains(fn(string $path): bool => str_contains($path, 'views/vendor/gemini-translator')));
        $this->assertTrue(collect($destinations)->contains(fn(string $path): bool => str_contains($path, 'vendor/gemini-translator')));
    }
}
