<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Services;

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
    /** @return list<string> */
    public function modules(): array
    {
        $modules = [];
        foreach ($this->roots() as $module => $_path) {
            if ($module !== '') {
                $modules[] = $module;
            }
        }
        sort($modules);

        return $modules;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return ['json', 'php'];
    }

    /** @return list<string> PHP / JSON files in the app lang directory (non-module). */
    public function files(): array
    {
        $files = [];
        $base = lang_path();
        if (! File::isDirectory($base)) {
            return [];
        }

        foreach (File::directories($base) as $dir) {
            if (! $this->looksLikeLocale(basename($dir))) {
                continue;
            }
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
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
        foreach ($this->roots() as $path) {
            if (! File::isDirectory($path)) {
                continue;
            }
            foreach (File::files($path) as $file) {
                if ($file->getExtension() === 'json') {
                    $codes[] = LocaleHelper::canonicalize($file->getFilenameWithoutExtension());
                }
            }
            foreach (File::directories($path) as $dir) {
                $name = basename($dir);
                if ($this->looksLikeLocale($name)) {
                    $codes[] = LocaleHelper::canonicalize($name);
                    continue;
                }
                foreach (File::files($dir) as $file) {
                    if ($file->getExtension() === 'json') {
                        $codes[] = LocaleHelper::canonicalize($file->getFilenameWithoutExtension());
                    }
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
        $rows = array_values(array_filter($rows, function (array $row) use ($type, $module, $scope, $files): bool {
            if ($type === 'module' && $row['module'] === '') {
                return false;
            }
            if ($type === 'non-module' && $row['module'] !== '') {
                return false;
            }
            if ($module !== 'all' && $row['module'] !== $module) {
                return false;
            }
            if ($type === 'non-module' && $scope === 'php' && $files !== [] && ! in_array($row['file'] ?? '', $files, true)) {
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
                'scope' => $row['scope'],
                'file' => $row['file'] ?? '',
            ];
            foreach ($langCodes as $code) {
                $item[$code] = $row['translations'][$code] ?? '';
            }
            $payload[] = $item;
        }

        return ['total' => $total, 'rows' => $payload];
    }

    /** @param list<array{lang?: string, module?: string, scope?: string, key?: string, value?: mixed}> $changes */
    public function save(array $changes): int
    {
        $files = [];
        foreach ($changes as $change) {
            $lang = LocaleHelper::canonicalize($change['lang'] ?? '');
            $module = $change['module'] ?? '';
            $scope = $change['scope'] ?? 'json';
            $key = $change['key'] ?? '';
            if ($lang === '' || $key === '') {
                continue;
            }
            $path = $this->filePath($module, $scope, $lang, $key);
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
        foreach ($this->roots() as $base) {
            if (! File::isDirectory($base)) {
                continue;
            }
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

    /** @return list<array{key: string, module: string, scope: string, file: string, translations: array<string, string>}> */
    private function collect(): array
    {
        $index = [];
        foreach ($this->roots() as $module => $base) {
            if (! File::isDirectory($base)) {
                continue;
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
                    $slot = $index[$module . "\0json\0" . $key] ??= [
                        'key' => $key,
                        'module' => $module,
                        'scope' => 'json',
                        'file' => '*.json',
                        'translations' => [],
                    ];
                    $slot['translations'][$lang] = is_string($value) ? $value : '';
                    $index[$module . "\0json\0" . $key] = $slot;
                }
            }

            foreach (File::directories($base) as $dir) {
                $name = basename($dir);
                if ($this->looksLikeLocale($name)) {
                    $lang = LocaleHelper::canonicalize($name);
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
                            $key = $group . '.' . $suffix;
                            $slot = $index[$module . "\0php\0" . $key] ??= [
                                'key' => $key,
                                'module' => $module,
                                'scope' => 'php',
                                'file' => $relative,
                                'translations' => [],
                            ];
                            $slot['translations'][$lang] = is_string($value) ? $value : '';
                            $index[$module . "\0php\0" . $key] = $slot;
                        }
                    }
                    continue;
                }

                foreach (File::files($dir) as $file) {
                    if ($file->getExtension() !== 'json') {
                        continue;
                    }
                    $lang = LocaleHelper::canonicalize($file->getFilenameWithoutExtension());
                    $data = json_decode(File::get($file->getPathname()), true);
                    if (! is_array($data)) {
                        continue;
                    }
                    $scope = $name;
                    foreach ($data as $key => $value) {
                        if (! is_string($key)) {
                            continue;
                        }
                        $slot = $index[$module . "\0" . $scope . "\0" . $key] ??= [
                            'key' => $key,
                            'module' => $module,
                            'scope' => $scope,
                            'file' => $scope . '/*.json',
                            'translations' => [],
                        ];
                        $slot['translations'][$lang] = is_string($value) ? $value : '';
                        $index[$module . "\0" . $scope . "\0" . $key] = $slot;
                    }
                }
            }
        }

        return array_values($index);
    }

    /** @return array<string, string> module => path */
    private function roots(): array
    {
        $roots = ['' => lang_path()];
        $modulesPath = base_path('Modules');
        if (File::isDirectory($modulesPath)) {
            foreach (File::directories($modulesPath) as $dir) {
                $lang = $dir . DIRECTORY_SEPARATOR . 'lang';
                if (File::isDirectory($lang)) {
                    $roots[basename($dir)] = $lang;
                }
            }
        }

        return $roots;
    }

    private function looksLikeLocale(string $name): bool
    {
        return (bool) preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z0-9]+)?$/', $name);
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

    private function filePath(string $module, string $scope, string $lang, string $key): string
    {
        $base = $this->roots()[$module] ?? lang_path();
        if ($scope === 'php') {
            $group = explode('.', $key, 2)[0];

            return $base . DIRECTORY_SEPARATOR . $lang . DIRECTORY_SEPARATOR . $group . '.php';
        }
        if ($scope === 'json') {
            return $base . DIRECTORY_SEPARATOR . $lang . '.json';
        }

        return $base . DIRECTORY_SEPARATOR . $scope . DIRECTORY_SEPARATOR . $lang . '.json';
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
        return 'Cannot write ' . $path . '. The web server user cannot create files in lang/. '
            . 'From the project root run: sudo chgrp -R www-data lang && sudo chmod -R g+w lang';
    }
}
