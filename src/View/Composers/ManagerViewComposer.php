<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\View\Composers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Jayesh\LaravelGeminiTranslator\Services\ManagerCatalogService;

final class ManagerViewComposer
{
    private static ?string $styles = null;

    private static ?string $script = null;

    public function compose(View $view): void
    {
        $data = $view->getData();
        $pageTitle = $data['pageTitle'] ?? __('Translation Manager');
        $catalog = resolve(ManagerCatalogService::class);

        $view->with([
            'modules' => $data['modules'] ?? $catalog->modules(),
            'scopes' => $data['scopes'] ?? $catalog->scopes(),
            'files' => $data['files'] ?? $catalog->files(),
            'languages' => $data['languages'] ?? $catalog->languages(),
            'languageNames' => $data['languageNames'] ?? $catalog->languageNames(),
            'endpoints' => $data['endpoints'] ?? $this->defaultEndpoints(),
            'pageTitle' => $pageTitle,
            'pageLede' => $data['pageLede'] ?? __('Browse, edit and save translations'),
            'documentTitle' => $data['documentTitle'] ?? $pageTitle,
        ]);

        if (! array_key_exists('managerStyles', $data)) {
            $view->with('managerStyles', self::styles());
        }

        if (! array_key_exists('managerScript', $data)) {
            $view->with('managerScript', self::script());
        }
    }

    private static function styles(): string
    {
        return self::$styles ??= self::readAsset('css/manager.css');
    }

    private static function script(): string
    {
        return self::$script ??= self::readAsset('js/manager.js');
    }

    private static function readAsset(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/resources/assets/' . $relativePath;

        return File::isFile($path) ? File::get($path) : '';
    }

    /** @return array<string, string> */
    private function defaultEndpoints(): array
    {
        if (! Route::has('gemini-translator.manager.data')) {
            return [];
        }

        return [
            'data' => route('gemini-translator.manager.data'),
            'languages' => route('gemini-translator.manager.languages'),
            'existing' => route('gemini-translator.manager.existing'),
            'scan' => route('gemini-translator.manager.scan'),
            'save' => route('gemini-translator.manager.save'),
            'add_languages' => route('gemini-translator.manager.add-languages'),
        ];
    }
}
