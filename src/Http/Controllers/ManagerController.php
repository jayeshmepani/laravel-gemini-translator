<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jayesh\LaravelGeminiTranslator\Services\ManagerCatalogService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final readonly class ManagerController
{
    public function __construct(private ManagerCatalogService $catalog)
    {
        if (! (bool) config('gemini-translator.manager.enabled', true)) {
            throw new NotFoundHttpException;
        }
    }

    public function show(): View
    {
        return view('gemini-translator::manager', $this->pageData());
    }

    public function data(Request $request): JsonResponse
    {
        try {
            return response()->json($this->catalog->page($request->all()));
        } catch (Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }
    }

    public function languages(): JsonResponse
    {
        return response()->json($this->catalog->languageNames());
    }

    public function existing(): JsonResponse
    {
        try {
            return response()->json($this->catalog->existingMap());
        } catch (Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }
    }

    public function scan(): JsonResponse
    {
        try {
            $count = $this->catalog->scan();

            return response()->json([
                'message' => 'Scan complete. Found ' . $count . ' unique translation keys.',
                'count' => $count,
            ]);
        } catch (Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }
    }

    public function save(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'changes' => ['required', 'array'],
            'changes.*.lang' => ['required', 'string'],
            'changes.*.key' => ['required', 'string'],
            'changes.*.value' => ['nullable', 'string'],
            'changes.*.module' => ['nullable', 'string'],
            'changes.*.pack' => ['nullable', 'string'],
            'changes.*.scope' => ['nullable', 'string'],
        ]);

        try {
            $written = $this->catalog->save($payload['changes']);

            return response()->json([
                'message' => 'Translations saved.',
                'written' => $written,
            ]);
        } catch (Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }
    }

    public function addLanguages(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'languages' => ['required', 'array'],
            'languages.*' => ['string'],
        ]);

        try {
            $created = $this->catalog->addLanguages($payload['languages']);

            return response()->json([
                'message' => 'Languages added.',
                'created' => $created,
            ]);
        } catch (Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }
    }

    /** @return array<string, mixed> */
    public function pageData(): array
    {
        return [
            'modules' => $this->catalog->modules(),
            'scopes' => $this->catalog->scopes(),
            'files' => $this->catalog->files(),
            'packs' => $this->catalog->packs(),
            'packMap' => $this->catalog->packMap(),
            'languages' => $this->catalog->languages(),
            'languageNames' => $this->catalog->languageNames(),
            'endpoints' => [
                'data' => route('gemini-translator.manager.data'),
                'languages' => route('gemini-translator.manager.languages'),
                'existing' => route('gemini-translator.manager.existing'),
                'scan' => route('gemini-translator.manager.scan'),
                'save' => route('gemini-translator.manager.save'),
                'add_languages' => route('gemini-translator.manager.add-languages'),
            ],
        ];
    }
}
