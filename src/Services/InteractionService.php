<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Services;

use Illuminate\Console\Command;
use Jayesh\LaravelGeminiTranslator\Contracts\PromptInterface;

class InteractionService
{
    public function __construct(
        private readonly PromptInterface $prompt,
    ) {}

    /** Prompt for scan targets */
    public function promptForScanTargets(array $availableTargets, ?Command $command = null): array
    {
        if (count($availableTargets) <= 1) {
            return array_keys($availableTargets);
        }

        $displayChoices = ['__ALL_TARGETS__' => '-- ALL TARGETS --']
            + collect($availableTargets)->mapWithKeys(fn($target, $key) => [$key => $target['name']])->all();

        $selected = $this->promptForMultiChoice(
            label: 'Which parts of the application would you like to scan and process?',
            options: $displayChoices,
            hint: 'Select the main application and/or any specific modules.',
            default: ['__ALL_TARGETS__'],
            command: $command,
        );

        if (in_array('__ALL_TARGETS__', $selected, true)) {
            return array_keys($availableTargets);
        }

        return $selected;
    }

    /**
     * Prompt for language packs (lang/, lang/app3/, lang/web/).
     * Skipped when only the root pack exists.
     *
     * @param array<string, string> $availablePacks pack id => label ('' is lang/)
     *
     * @return list<string>
     */
    public function promptForPackSelection(array $availablePacks, ?Command $command = null): array
    {
        if ($availablePacks === []) {
            return [''];
        }
        if (count($availablePacks) <= 1) {
            return array_keys($availablePacks);
        }

        $displayChoices = ['__ALL_PACKS__' => '-- ALL PACKS --'];
        foreach ($availablePacks as $pack => $label) {
            $displayChoices[$pack === '' ? '__root__' : $pack] = $label;
        }

        $selected = $this->promptForMultiChoice(
            label: 'Which language packs would you like to process?',
            options: $displayChoices,
            hint: 'Packs are extra lang folders such as lang/app3/ and lang/web/. Use the space bar to select.',
            default: ['__ALL_PACKS__'],
            command: $command,
        );

        if (in_array('__ALL_PACKS__', $selected, true)) {
            return array_keys($availablePacks);
        }

        $packs = [];
        foreach ($selected as $value) {
            $packs[] = $value === '__root__' ? '' : (string) $value;
        }

        return $packs;
    }

    /** Prompt for file selection */
    public function promptForFileSelection(array $availableFiles, array $scanTargets, ?Command $command = null): array
    {
        if (count($availableFiles) <= 1) {
            return array_values($availableFiles);
        }

        $displayChoices = ['__ALL_FILES__' => '-- ALL FILES --']
            + collect($availableFiles)->mapWithKeys(function ($contextualFileKey) use ($scanTargets) {
                [$targetKey, $fileKey] = explode('::', $contextualFileKey, 2);

                $targetName = $scanTargets[$targetKey]['name'] ?? $targetKey;

                if (str_ends_with($fileKey, '__JSON__')) {
                    $path = str_replace('__JSON__', '', $fileKey);
                    $displayName = "{$targetName}: JSON File ({$path}*.json)";
                } else {
                    $normalized = str_replace('\\', '/', $fileKey);
                    $slash = strrpos($normalized, '/');
                    $displayName = $slash === false
                        ? "{$targetName}: {$fileKey}.php"
                        : $targetName . ': lang/' . substr($normalized, 0, $slash) . '/' . substr($normalized, $slash + 1) . '.php';
                }

                return [$contextualFileKey => $displayName];
            })->all();

        $selected = $this->promptForMultiChoice(
            label: 'Which translation files would you like to process?',
            options: $displayChoices,
            hint: 'Use the space bar to select options.',
            default: ['__ALL_FILES__'],
            command: $command,
        );

        // Handle different selection scenarios:
        // 1. User selected "-- ALL FILES --" option
        // 2. User manually selected all individual files (e.g., 1,2,3,4,5,6,7,8,9)
        // 3. User selected both "-- ALL FILES --" and some individual files
        // In all cases above, return all available files

        if (in_array('__ALL_FILES__', $selected, true)) {
            // Scenario 1 & 3: "-- ALL FILES --" was selected
            return $availableFiles;
        }

        // Scenario 2: Check if user manually selected all individual files
        // Remove '__ALL_FILES__' from both arrays for comparison
        $availableFileKeys = array_values($availableFiles);
        $selectedWithoutAll = array_values(array_diff($selected, ['__ALL_FILES__']));

        // Sort both for accurate comparison
        sort($availableFileKeys);
        sort($selectedWithoutAll);

        if ($availableFileKeys === $selectedWithoutAll) {
            // User manually selected all files, return the complete list
            return $availableFiles;
        }

        return $selected;
    }

    /** Prompt for consolidation */
    public function promptForConsolidation(bool $hasModulesSelected, bool $noInteraction = false, bool $consolidateModulesOption = false, ?Command $command = null): bool
    {
        if ($hasModulesSelected && ! $consolidateModulesOption && ! $noInteraction) {
            return $this->prompt->confirm(
                label: "Consolidate all module translations into the main application's `lang` directory?",
                default: false,
                hint: 'No: Keep translations inside each module (e.g., Modules/Settings/lang). Yes: Put all translations in the root `lang/`.',
                command: $command,
            );
        }

        return $consolidateModulesOption;
    }

    /**
     * Prompt for multi choice
     * Accepts command context to enable Windows fallback functionality.
     */
    public function promptForMultiChoice(string $label, array $options, string $hint = '', ?array $default = null, ?Command $command = null): array
    {
        // 1️⃣ Non-interactive environment (CI, cron, supervisor)
        // Do not prompt. Just return defaults or everything.
        if ($command !== null && method_exists($command, 'isInteractive') && ! $command->isInteractive()) {
            return $default ?? array_keys($options);
        }

        return $this->prompt->multiselect(
            label: $label,
            options: $options,
            hint: $hint,
            default: $default,
            command: $command,
        );
    }
}
