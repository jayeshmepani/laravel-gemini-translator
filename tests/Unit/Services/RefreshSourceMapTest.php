<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Services;

use Jayesh\LaravelGeminiTranslator\Services\TranslationService;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class RefreshSourceMapTest extends TestCase
{
    public function test_refresh_ignores_stale_file_wording_and_uses_key_shape(): void
    {
        $this->assertSame('By :author', TranslationService::sourceForRefresh('messages.user.by_author'));
        $this->assertSame('', TranslationService::sourceForRefresh('validation.attributes'));
        $this->assertSame('Welcome Page Title', TranslationService::sourceForRefresh('Welcome Page Title'));
        $this->assertSame('By :author', TranslationService::sourceForRefresh('By :author'));
        $this->assertSame('{0} No items|{1} One item|[2,10] Few items', TranslationService::sourceForRefresh('{0} No items|{1} One item|[2,10] Few items'));
    }

    public function test_refresh_clean_uses_official_laravel_english_not_ucwords_of_the_key(): void
    {
        $inArray = TranslationService::sourceForRefresh('validation.in_array_keys');
        $this->assertStringContainsString(':attribute', $inArray);
        $this->assertStringContainsString('keys', $inArray);
        $this->assertStringNotContainsString('In Array Keys', $inArray);

        $maxArray = TranslationService::sourceForRefresh('validation.max.array');
        $this->assertStringContainsString('The :attribute field', $maxArray);
        $this->assertStringContainsString(':max', $maxArray);
        $this->assertNotSame('Array', $maxArray);

        $this->assertSame(
            'These credentials do not match our records.',
            TranslationService::sourceForRefresh('auth.failed'),
        );
        $this->assertSame('Next &raquo;', TranslationService::sourceForRefresh('pagination.next'));
    }

    public function test_rebuild_source_map_does_not_copy_existing_lang_values(): void
    {
        $service = resolve(TranslationService::class);

        $map = $service->rebuildSourceMapForRefresh([
            '__MAIN__::messages' => ['user.by_author', 'goodbye', 'plural_test'],
            '__MAIN__::__JSON__' => ['Save Changes', 'Welcome Page Title'],
        ]);

        $this->assertSame('By :author', $map['messages.user.by_author']);
        $this->assertSame('Goodbye', $map['messages.goodbye']);
        $this->assertSame('Plural Test', $map['messages.plural_test']);
        $this->assertSame('Save Changes', $map['Save Changes']);
        $this->assertSame('Welcome Page Title', $map['Welcome Page Title']);
        $this->assertArrayNotHasKey('By :name', $map);
    }

    public function test_empty_existing_source_is_replaced_from_the_key(): void
    {
        $service = resolve(TranslationService::class);

        $map = $service->replaceEmptySourceWithKeyDerived(
            ['__MAIN__::__JSON__' => ['A key from a JSX file.', 'By :author']],
            [
                'A key from a JSX file.' => '',
                'By :author' => '   ',
            ],
        );

        $this->assertSame('A key from a JSX file.', $map['A key from a JSX file.']);
        $this->assertSame('By :author', $map['By :author']);
    }
}
