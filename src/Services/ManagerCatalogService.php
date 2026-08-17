<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Services;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Jayesh\LaravelGeminiTranslator\Support\LanguageCatalog;
use Jayesh\LaravelGeminiTranslator\Utils\LocaleHelper;
use Jayesh\LaravelGeminiTranslator\Utils\TextHelper;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Throwable;

final class ManagerCatalogService
{
    private const int PRIORITY_MODULE = 10;

    private const int PRIORITY_APP = 20;

    private const int PRIORITY_CUSTOM = 30;

    private const int PRIORITY_PUBLISHED = 40;

    /** @var list<string> */
    private const array SKIP_PACK_NAMES = ['vendor', 'node_modules', 'storage', '.git'];

    /** @return list<string> */
    public function modules(): array
    {
        $modules = [];
        foreach ($this->sources() as $source) {
            if ($source['module'] !== '') {
                $modules[] = $source['module'];
            }
        }
        $modules = array_values(array_unique($modules));
        sort($modules);

        return $modules;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return ['json', 'php'];
    }

    /**
     * Packs for a module (empty string is the lang/ root).
     *
     * @return list<string>
     */
    public function packs(?string $module = null): array
    {
        $packs = [];
        foreach ($this->sources() as $source) {
            if (!in_array($module, [null, '', 'all'], true) && $source['module'] !== $module) {
                continue;
            }
            $packs[] = $source['pack'];
        }

        return $this->uniqueSortedPacks($packs);
    }

    /**
     * module => packs (including the empty root pack).
     *
     * @return array<string, list<string>>
     */
    public function packMap(): array
    {
        $map = [];
        foreach ($this->sources() as $source) {
            $map[$source['module']][] = $source['pack'];
        }
        foreach ($map as $module => $packs) {
            $map[$module] = $this->uniqueSortedPacks($packs);
        }

        return $map;
    }

    /**
     * PHP files for the current type / module / pack.
     *
     * @param array{type?: string, module?: string, pack?: string} $filters
     *
     * @return list<string>
     */
    public function files(array $filters = []): array
    {
        $type = $filters['type'] ?? 'all';
        $module = $filters['module'] ?? 'all';
        $pack = $this->normalizePackFilter($filters['pack'] ?? 'all');

        $files = [];
        foreach ($this->sources() as $source) {
            if ($type === 'module' && $source['module'] === '') {
                continue;
            }
            if ($type === 'non-module' && $source['module'] !== '') {
                continue;
            }
            if ($module !== 'all' && $source['module'] !== $module) {
                continue;
            }
            if ($pack !== 'all' && $source['pack'] !== $pack) {
                continue;
            }
            if (! File::isDirectory($source['path'])) {
                continue;
            }
            foreach (File::directories($source['path']) as $dir) {
                if (! $this->isLocaleDirectory($dir)) {
                    continue;
                }
                foreach (File::allFiles($dir) as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }
                    $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /** @return list<string> */
    public function languages(): array
    {
        $codes = [];
        foreach ($this->sources() as $source) {
            if (! File::isDirectory($source['path'])) {
                continue;
            }
            foreach (File::files($source['path']) as $file) {
                if ($file->getExtension() === 'json') {
                    $codes[] = LocaleHelper::canonicalize($file->getFilenameWithoutExtension());
                }
            }
            foreach (File::directories($source['path']) as $dir) {
                if ($this->isLocaleDirectory($dir)) {
                    $codes[] = LocaleHelper::canonicalize(basename($dir));
                }
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /** @return array<string, string> */
    public function languageNames(): array
    {
        $names = LanguageCatalog::namesToCodes();
        foreach ($this->languages() as $code) {
            $label = LanguageCatalog::displayName($code);
            if (! isset($names[$label])) {
                $names[$label] = $code;
            }
        }
        ksort($names);

        return $names;
    }

    /** @return array<string, array<string, array<string, bool>>> */
    public function existingMap(): array
    {
        $map = [];
        foreach ($this->collect() as $row) {
            $module = $row['module'] !== '' ? $row['module'] : 'App';
            foreach ($row['translations'] as $lang => $_value) {
                $map[$module][$row['scope']][$lang] = true;
            }
        }

        return $map;
    }

    /** @return array{total: int, rows: list<array<string, mixed>>} */
    public function page(array $filters): array
    {
        $type = (string) ($filters['type'] ?? 'all');
        $module = (string) ($filters['module'] ?? 'all');
        $pack = $this->normalizePackFilter($filters['pack'] ?? 'all');
        $scope = (string) ($filters['scope'] ?? 'all');
        $files = $this->selectedFiles($filters['files'] ?? []);
        $language = (string) ($filters['language'] ?? 'all');
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $showMissing = filter_var($filters['showOnlyMissing'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sort = (string) ($filters['sort'] ?? 'key');
        $order = strtolower((string) ($filters['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $limit = $filters['limit'] ?? 15;
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $rows = $this->collect();
        $rows = array_values(array_filter($rows, function (array $row) use ($type, $module, $pack, $scope, $files): bool {
            if ($type === 'module' && $row['module'] === '') {
                return false;
            }
            if ($type === 'non-module' && $row['module'] !== '') {
                return false;
            }
            if ($module !== 'all' && $row['module'] !== $module) {
                return false;
            }
            if ($pack !== 'all' && $row['pack'] !== $pack) {
                return false;
            }
            if ($files !== [] && ! in_array($row['file'], $files, true)) {
                return false;
            }

            return $scope === 'all' || $row['scope'] === $scope;
        }));

        if ($search !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($search): bool {
                if (str_contains(strtolower($row['key']), $search)) {
                    return true;
                }
                foreach ($row['translations'] as $value) {
                    if (str_contains(strtolower($value), $search)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $langCodes = $language !== 'all' ? [$language] : $this->languages();
        if ($language !== 'all') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => array_key_exists($language, $row['translations'])));
        }
        if ($showMissing) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($langCodes): bool {
                foreach ($langCodes as $code) {
                    $value = $row['translations'][$code] ?? '';
                    if ($value === '') {
                        return true;
                    }
                }

                return false;
            }));
        }

        usort($rows, static function (array $a, array $b) use ($sort, $order): int {
            $left = $sort === 'key' ? $a['key'] : ($a['translations'][$sort] ?? '');
            $right = $sort === 'key' ? $b['key'] : ($b['translations'][$sort] ?? '');
            $cmp = strcasecmp($left, $right);
            if ($cmp === 0) {
                $cmp = strcasecmp($a['pack'], $b['pack']);
            }

            return $order === 'asc' ? $cmp : -$cmp;
        });

        $total = count($rows);
        if ($limit !== 'All' && $limit !== 'all') {
            $rows = array_slice($rows, $offset, max(1, (int) $limit));
        }

        $payload = [];
        foreach ($rows as $row) {
            $item = [
                'key' => $row['key'],
                'module' => $row['module'],
                'pack' => $row['pack'],
                'scope' => $row['scope'],
                'file' => $row['file'],
            ];
            foreach ($langCodes as $code) {
                $item[$code] = $row['translations'][$code] ?? '';
            }
            $payload[] = $item;
        }

        return [
            'total' => $total,
            'rows' => $payload,
            'files' => $this->files([
                'type' => $type,
                'module' => $module,
                'pack' => $filters['pack'] ?? 'all',
            ]),
        ];
    }

    /** @param list<array{lang?: string, module?: string, pack?: string, scope?: string, key?: string, value?: mixed}> $changes */
    public function save(array $changes): int
    {
        $files = [];
        foreach ($changes as $change) {
            $lang = LocaleHelper::canonicalize($change['lang'] ?? '');
            $module = $change['module'] ?? '';
            $pack = $this->normalizePackValue($change['pack'] ?? '');
            $scope = $change['scope'] ?? 'json';
            $key = $change['key'] ?? '';
            if ($lang === '' || $key === '') {
                continue;
            }
            $path = $this->filePath($module, $scope, $lang, $key, $pack);
            $files[$path]['scope'] = $scope;
            $files[$path]['items'][] = [
                'key' => $key,
                'value' => is_scalar($change['value'] ?? '') ? (string) $change['value'] : '',
            ];
        }

        $written = 0;
        foreach ($files as $path => $group) {
            $data = $this->readFile($path, $group['scope']);
            foreach ($group['items'] as $item) {
                $this->writeKey($data, $group['scope'], $item['key'], $item['value']);
            }
            $this->persist($path, $group['scope'], $data);
            $written++;
        }

        return $written;
    }

    /** @param list<string> $codes */
    public function addLanguages(array $codes): int
    {
        $created = 0;
        $failures = [];
        $source = 'en';
        $seen = [];
        foreach ($this->sources() as $entry) {
            $base = $entry['path'];
            if (isset($seen[$base]) || ! File::isDirectory($base)) {
                continue;
            }
            $seen[$base] = true;
            $enJson = $base . DIRECTORY_SEPARATOR . $source . '.json';
            if (File::isFile($enJson)) {
                $keys = json_decode(File::get($enJson), true);
                $empty = is_array($keys) ? array_fill_keys(array_keys($keys), '') : [];
                foreach ($codes as $code) {
                    $code = LocaleHelper::canonicalize($code);
                    if ($code === '' || $code === $source) {
                        continue;
                    }
                    $target = $base . DIRECTORY_SEPARATOR . $code . '.json';
                    if (! File::exists($target)) {
                        $created += $this->tryWritePath(
                            $target,
                            json_encode($empty, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                            $failures,
                        );
                    }
                }
            }

            $enDir = $base . DIRECTORY_SEPARATOR . $source;
            if (File::isDirectory($enDir)) {
                foreach (File::allFiles($enDir) as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }
                    $relative = $file->getRelativePathname();
                    foreach ($codes as $code) {
                        $code = LocaleHelper::canonicalize($code);
                        if ($code === '' || $code === $source) {
                            continue;
                        }
                        $target = $base . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . $relative;
                        if (! File::exists($target)) {
                            $created += $this->tryWritePath($target, File::get($file->getPathname()), $failures);
                        }
                    }
                }
            }
        }

        if ($created === 0 && $failures !== []) {
            throw new RuntimeException($failures[0]);
        }

        return $created;
    }

    public function scan(): int
    {
        $excludes = ['vendor', 'node_modules', 'storage', 'public', 'bootstrap', 'tests', 'lang', 'config', '.git'];
        $finder = new Finder;
        $finder->files()->in(base_path())->ignoreDotFiles(true)->ignoreVCS(true);
        foreach ($excludes as $exclude) {
            $finder->exclude($exclude);
        }
        $finder->name(['*.php', '*.blade.php', '*.vue', '*.js', '*.jsx', '*.ts', '*.tsx']);

        $keys = [];
        $patterns = TextHelper::getExtractionPatterns();
        try {
            foreach ($finder as $file) {
                $content = $file->getContents();
                foreach ($patterns as $pattern) {
                    if (preg_match_all($pattern, $content, $matches)) {
                        foreach ($matches[1] ?? [] as $key) {
                            if ($key !== '') {
                                $keys[$key] = true;
                            }
                        }
                    }
                }
            }
        } catch (Throwable) {
            return count($keys);
        }

        return count($keys);
    }

    /** @return list<array{key: string, module: string, pack: string, scope: string, file: string, translations: array<string, string>}> */
    private function collect(): array
    {
        $index = [];
        foreach ($this->sources() as $source) {
            $this->ingestSource($index, $source);
        }

        return array_values($index);
    }

    /**
     * @param array<string, array{key: string, module: string, pack: string, scope: string, file: string, translations: array<string, string>}> $index
     * @param array{module: string, pack: string, path: string, priority: int} $source
     */
    private function ingestSource(array &$index, array $source): void
    {
        $base = $source['path'];
        if (! File::isDirectory($base)) {
            return;
        }

        foreach (File::files($base) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            $lang = LocaleHelper::canonicalize($file->getFilenameWithoutExtension());
            $data = json_decode(File::get($file->getPathname()), true);
            if (! is_array($data)) {
                continue;
            }
            foreach ($data as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }
                $this->putTranslation(
                    $index,
                    $source['module'],
                    $source['pack'],
                    'json',
                    $key,
                    $source['pack'] === '' ? '*.json' : $source['pack'] . '/*.json',
                    $lang,
                    is_string($value) ? $value : '',
                );
            }
        }

        foreach (File::directories($base) as $dir) {
            if (! $this->isLocaleDirectory($dir)) {
                continue;
            }
            $lang = LocaleHelper::canonicalize(basename($dir));
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
                $group = str_replace(['.php', '/'], ['', '.'], $relative);
                $included = @include $file->getPathname();
                if (! is_array($included)) {
                    continue;
                }
                foreach (Arr::dot($included) as $suffix => $value) {
                    $this->putTranslation(
                        $index,
                        $source['module'],
                        $source['pack'],
                        'php',
                        $group . '.' . $suffix,
                        $relative,
                        $lang,
                        is_string($value) ? $value : '',
                    );
                }
            }
        }
    }

    /** @param array<string, array{key: string, module: string, pack: string, scope: string, file: string, translations: array<string, string>}> $index */
    private function putTranslation(
        array &$index,
        string $module,
        string $pack,
        string $scope,
        string $key,
        string $file,
        string $lang,
        string $value,
    ): void {
        $id = $module . "\0" . $pack . "\0" . $scope . "\0" . $key;
        $index[$id] ??= [
            'key' => $key,
            'module' => $module,
            'pack' => $pack,
            'scope' => $scope,
            'file' => $file,
            'translations' => [],
        ];
        $index[$id]['translations'][$lang] = $value;
    }

    /** @return list<array{module: string, pack: string, path: string, priority: int}> */
    private function sources(): array
    {
        /** @var array<string, array{module: string, pack: string, path: string, priority: int}> $bucket */
        $bucket = [];

        $this->expandPacks('', lang_path(), self::PRIORITY_APP, $bucket);

        $modulesPath = base_path('Modules');
        if (File::isDirectory($modulesPath)) {
            foreach (File::directories($modulesPath) as $dir) {
                $lang = $dir . DIRECTORY_SEPARATOR . 'lang';
                if (File::isDirectory($lang)) {
                    $this->expandPacks(basename($dir), $lang, self::PRIORITY_MODULE, $bucket);
                }
            }
        }

        $published = resource_path('lang/modules');
        if (File::isDirectory($published)) {
            foreach (File::directories($published) as $dir) {
                $this->expandPacks($this->canonicalModule(basename($dir)), $dir, self::PRIORITY_PUBLISHED, $bucket);
            }
        }

        foreach ($this->registeredEntries() as $entry) {
            $classified = $this->classifyPath($entry['path'], $entry['moduleHint']);
            if ($classified === null) {
                continue;
            }
            if ($classified['isRoot']) {
                $this->expandPacks($classified['module'], $entry['path'], $classified['priority'], $bucket);
            } else {
                $this->addSource($bucket, $classified['module'], $classified['pack'], $entry['path'], $classified['priority']);
            }
        }

        $sources = array_values($bucket);
        usort($sources, static function (array $left, array $right): int {
            $priority = $left['priority'] <=> $right['priority'];
            if ($priority !== 0) {
                return $priority;
            }
            $module = $left['module'] <=> $right['module'];
            if ($module !== 0) {
                return $module;
            }
            $pack = $left['pack'] <=> $right['pack'];
            if ($pack !== 0) {
                return $pack;
            }

            return $left['path'] <=> $right['path'];
        });

        return $sources;
    }

    /** @return list<array{path: string, moduleHint: string}> */
    private function registeredEntries(): array
    {
        $loader = $this->translatorLoader();
        if ($loader === null) {
            return [];
        }

        $entries = [];
        if (method_exists($loader, 'jsonPaths')) {
            foreach ($loader->jsonPaths() as $path) {
                if (is_string($path) && $path !== '') {
                    $entries[] = ['path' => $path, 'moduleHint' => ''];
                }
            }
        }
        if (method_exists($loader, 'paths')) {
            foreach ($loader->paths() as $path) {
                if (is_string($path) && $path !== '') {
                    $entries[] = ['path' => $path, 'moduleHint' => ''];
                }
            }
        }
        if (method_exists($loader, 'namespaces')) {
            foreach ($loader->namespaces() as $namespace => $path) {
                if (is_string($path) && $path !== '') {
                    $entries[] = [
                        'path' => $path,
                        'moduleHint' => is_string($namespace) ? $namespace : '',
                    ];
                }
            }
        }

        return $entries;
    }

    private function translatorLoader(): ?object
    {
        try {
            $translator = resolve(Translator::class);
        } catch (Throwable) {
            return null;
        }
        if (! is_object($translator) || ! method_exists($translator, 'getLoader')) {
            return null;
        }
        $loader = $translator->getLoader();

        return is_object($loader) ? $loader : null;
    }

    /** @return array{module: string, pack: string, isRoot: bool, priority: int}|null */
    private function classifyPath(string $path, string $moduleHint = ''): ?array
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === '') {
            return null;
        }

        if ($this->isComposerVendorPath($normalized)) {
            return null;
        }

        $modulesRoot = $this->normalizePath(base_path('Modules'));
        if ($modulesRoot !== '' && str_starts_with($normalized, $modulesRoot . '/')) {
            $relative = substr($normalized, strlen($modulesRoot) + 1);
            $parts = explode('/', $relative);
            if (($parts[1] ?? '') !== 'lang') {
                return null;
            }
            $packParts = array_slice($parts, 2);
            if ($packParts !== [] && $this->isLocaleDirectory($normalized)) {
                return [
                    'module' => $this->canonicalModule($parts[0]),
                    'pack' => '',
                    'isRoot' => true,
                    'priority' => self::PRIORITY_MODULE,
                ];
            }
            $pack = implode('/', $packParts);

            return [
                'module' => $this->canonicalModule($parts[0]),
                'pack' => $pack,
                'isRoot' => $pack === '',
                'priority' => self::PRIORITY_MODULE,
            ];
        }

        $publishedRoot = $this->normalizePath(resource_path('lang/modules'));
        if ($publishedRoot !== '' && str_starts_with($normalized, $publishedRoot . '/')) {
            $relative = substr($normalized, strlen($publishedRoot) + 1);
            $parts = explode('/', $relative);
            $packParts = array_slice($parts, 1);
            if ($packParts !== [] && $this->isLocaleDirectory($normalized)) {
                $packParts = [];
            }
            $pack = implode('/', $packParts);

            return [
                'module' => $this->canonicalModule($parts[0]),
                'pack' => $pack,
                'isRoot' => $pack === '',
                'priority' => self::PRIORITY_PUBLISHED,
            ];
        }

        $langRoot = $this->normalizePath(lang_path());
        if ($langRoot !== '' && ($normalized === $langRoot || str_starts_with($normalized, $langRoot . '/'))) {
            if ($normalized === $langRoot) {
                return [
                    'module' => '',
                    'pack' => '',
                    'isRoot' => true,
                    'priority' => self::PRIORITY_APP,
                ];
            }
            $relative = substr($normalized, strlen($langRoot) + 1);
            $first = explode('/', $relative)[0];
            if ($this->isLocaleDirectory($normalized) || in_array($first, self::SKIP_PACK_NAMES, true)) {
                return [
                    'module' => '',
                    'pack' => '',
                    'isRoot' => true,
                    'priority' => self::PRIORITY_APP,
                ];
            }

            return [
                'module' => '',
                'pack' => $relative,
                'isRoot' => false,
                'priority' => self::PRIORITY_APP,
            ];
        }

        $base = $this->normalizePath(base_path());
        $pack = $normalized;
        if ($base !== '' && str_starts_with($normalized, $base . '/')) {
            $pack = substr($normalized, strlen($base) + 1);
        }

        return [
            'module' => $this->canonicalModule($moduleHint),
            'pack' => $pack,
            'isRoot' => false,
            'priority' => self::PRIORITY_CUSTOM,
        ];
    }

    /** @param array<string, array{module: string, pack: string, path: string, priority: int}> $bucket */
    private function expandPacks(string $module, string $rootPath, int $priority, array &$bucket): void
    {
        $this->addSource($bucket, $module, '', $rootPath, $priority);
        if (! File::isDirectory($rootPath)) {
            return;
        }
        foreach (File::directories($rootPath) as $dir) {
            if (! $this->isPackDirectory($dir)) {
                continue;
            }
            $this->addSource($bucket, $module, basename($dir), $dir, $priority);
        }
    }

    /** @param array<string, array{module: string, pack: string, path: string, priority: int}> $bucket */
    private function addSource(array &$bucket, string $module, string $pack, string $path, int $priority): void
    {
        if (! File::isDirectory($path)) {
            return;
        }
        $normalized = $this->normalizePath($path);
        $key = $module . "\0" . $pack . "\0" . $normalized;
        if (isset($bucket[$key]) && $bucket[$key]['priority'] >= $priority) {
            return;
        }
        $bucket[$key] = [
            'module' => $module,
            'pack' => $pack,
            'path' => $normalized,
            'priority' => $priority,
        ];
    }

    private function isPackDirectory(string $path): bool
    {
        $name = basename($path);
        if (in_array($name, self::SKIP_PACK_NAMES, true) || $this->isLocaleDirectory($path)) {
            return false;
        }

        return $this->hasLangContent($path);
    }

    /**
     * Locale folders look like en/ or zh_CN/ and hold PHP groups.
     * Pack folders (app3, web) hold locale JSON or nested locale dirs.
     */
    private function isLocaleDirectory(string $path): bool
    {
        $name = basename($path);
        if (! $this->looksLikeLocale($name) || ! File::isDirectory($path)) {
            return false;
        }
        if ($this->hasLangContent($path)) {
            return false;
        }

        return true;
    }

    private function hasLangContent(string $path): bool
    {
        if (! File::isDirectory($path)) {
            return false;
        }
        foreach (File::files($path) as $file) {
            if ($file->getExtension() === 'json') {
                return true;
            }
        }
        foreach (File::directories($path) as $dir) {
            if ($this->looksLikeLocale(basename($dir))) {
                return true;
            }
        }

        return false;
    }

    private function canonicalModule(string $hint): string
    {
        $hint = trim($hint);
        if ($hint === '' || $hint === '*' || strcasecmp($hint, 'app') === 0) {
            return '';
        }
        $known = $this->moduleDirectoryNames();

        return $known[strtolower($hint)] ?? $hint;
    }

    /** @return array<string, string> */
    private function moduleDirectoryNames(): array
    {
        $names = [];
        foreach ([resource_path('lang/modules'), base_path('Modules')] as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }
            foreach (File::directories($root) as $dir) {
                $name = basename($dir);
                $names[strtolower($name)] = $name;
            }
        }

        return $names;
    }

    private function isComposerVendorPath(string $normalized): bool
    {
        $app = $this->normalizePath(base_path());
        $logicalVendor = rtrim(str_replace('\\', '/', base_path('vendor')), '/');
        if ($app !== '' && ($normalized === $app || str_starts_with($normalized, $app . '/'))) {
            return str_starts_with($normalized, $logicalVendor . '/')
                || str_starts_with($normalized, $app . '/vendor/');
        }

        $appVendor = $this->normalizePath(base_path('vendor'));

        return $appVendor !== '' && str_starts_with($normalized, $appVendor . '/');
    }

    private function looksLikeLocale(string $name): bool
    {
        return (bool) preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z0-9]+)?$/', $name);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = rtrim($path, '/');
        $real = realpath($path);

        return $real !== false ? str_replace('\\', '/', $real) : $path;
    }

    private function normalizePackFilter(mixed $raw): string
    {
        $pack = is_string($raw) ? $raw : 'all';
        if ($pack === '__root__') {
            return '';
        }

        return $pack;
    }

    private function normalizePackValue(mixed $raw): string
    {
        $pack = is_string($raw) ? $raw : '';
        if ($pack === '__root__' || $pack === 'all') {
            return '';
        }

        return $pack;
    }

    /** @param list<string> $packs */
    private function uniqueSortedPacks(array $packs): array
    {
        $packs = array_values(array_unique($packs));
        usort($packs, static function (string $left, string $right): int {
            if ($left === '') {
                return -1;
            }
            if ($right === '') {
                return 1;
            }

            return strcasecmp($left, $right);
        });

        return $packs;
    }

    /** @return list<string> */
    private function selectedFiles(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }

        $files = [];
        foreach ($raw as $file) {
            if (! is_string($file)) {
                continue;
            }
            $file = str_replace('\\', '/', trim($file));
            if ($file !== '') {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    private function filePath(string $module, string $scope, string $lang, string $key, string $pack = ''): string
    {
        $base = $this->writeBase($module, $pack);
        $separator = DIRECTORY_SEPARATOR;
        if ($scope === 'php') {
            $group = explode('.', $key, 2)[0];

            return $base . $separator . $lang . $separator . $group . '.php';
        }

        return $base . $separator . $lang . '.json';
    }

    private function writeBase(string $module, string $pack): string
    {
        $matches = [];
        foreach ($this->sources() as $source) {
            if ($source['module'] === $module && $source['pack'] === $pack) {
                $matches[] = $source;
            }
        }
        usort($matches, static fn(array $left, array $right): int => $right['priority'] <=> $left['priority']);
        if ($matches !== []) {
            return $matches[0]['path'];
        }

        $separator = DIRECTORY_SEPARATOR;
        $suffix = $pack === '' ? '' : $separator . str_replace('/', $separator, $pack);
        if ($module === '') {
            return lang_path() . $suffix;
        }

        $published = resource_path('lang/modules/' . $module);
        if (File::isDirectory($published) || File::isDirectory(resource_path('lang/modules/' . strtolower($module)))) {
            $base = File::isDirectory($published) ? $published : resource_path('lang/modules/' . strtolower($module));

            return $base . $suffix;
        }

        return base_path('Modules' . $separator . $module . $separator . 'lang') . $suffix;
    }

    /** @return array<string, mixed> */
    private function readFile(string $path, string $scope): array
    {
        if (! File::exists($path)) {
            return [];
        }
        if ($scope === 'php') {
            $data = @include $path;

            return is_array($data) ? $data : [];
        }
        $data = json_decode(File::get($path), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function writeKey(array &$data, string $scope, string $key, string $value): void
    {
        if ($scope === 'php') {
            $suffix = Str::after($key, '.');
            if ($suffix === $key) {
                $data[$key] = $value;

                return;
            }
            data_set($data, $suffix, $value);

            return;
        }
        $data[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    private function persist(string $path, string $scope, array $data): void
    {
        if ($scope === 'php') {
            $export = var_export($data, true);
            $export = (string) preg_replace('/^array\s*\(/', '[', $export);
            $export = (string) preg_replace('/\)$/', ']', $export);
            $this->writePath($path, "<?php\n\nreturn {$export};\n");

            return;
        }
        ksort($data);
        $this->writePath(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /** @param list<string> $failures */
    private function tryWritePath(string $path, string $contents, array &$failures): int
    {
        try {
            $this->writePath($path, $contents);

            return 1;
        } catch (Throwable $exception) {
            $failures[] = $exception->getMessage();

            return File::isFile($path) ? 1 : 0;
        }
    }

    private function writePath(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! File::isDirectory($directory)) {
            try {
                File::ensureDirectoryExists($directory);
            } catch (Throwable $exception) {
                throw new RuntimeException($this->unwritableMessage($directory), 0, $exception);
            }
        }

        try {
            $written = File::put($path, $contents);
        } catch (Throwable $exception) {
            if (File::isFile($path)) {
                return;
            }

            throw new RuntimeException($this->unwritableMessage($path), 0, $exception);
        }

        if ($written === false && ! File::isFile($path)) {
            throw new RuntimeException($this->unwritableMessage($path));
        }
    }

    private function unwritableMessage(string $path): string
    {
        $owner = $this->processOwnerName();
        $base = 'Cannot write ' . $path . '. The PHP process cannot create files there.';
        if ($owner === '') {
            return $base . ' Give write access on lang/ to the user that runs PHP-FPM, Apache, nginx, or `php artisan serve` (that is often www-data, nginx, apache, http, or your login user — not always www-data).';
        }

        return $base . ' PHP is running as `' . $owner . '`. From the project root, for example: '
            . 'sudo chown -R ' . $owner . ':' . $owner . ' lang && sudo chmod -R u+w lang';
    }

    private function processOwnerName(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name']) && is_string($info['name']) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        $user = get_current_user();

        return $user !== '' ? $user : '';
    }
}
