<?php

namespace Jayesh\LaravelGeminiTranslator\Services;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

class InteractionService
{
    /**
     * Prompt for scan targets
     */
    public function promptForScanTargets(array $availableTargets, $command = null): array
    {
        if (count($availableTargets) <= 1) {
            return array_keys($availableTargets);
        }

        $displayChoices = ['__ALL_TARGETS__' => '-- ALL TARGETS --'] +
            collect($availableTargets)->mapWithKeys(fn($target, $key) => [$key => $target['name']])->all();

        $selected = $this->promptForMultiChoice(
            label: 'Which parts of the application would you like to scan and process?',
            options: $displayChoices,
            hint: 'Select the main application and/or any specific modules.',
            default: ['__ALL_TARGETS__'],
            command: $command
        );

        if (in_array('__ALL_TARGETS__', $selected)) {
            return array_keys($availableTargets);
        }
        return $selected;
    }

    /**
     * Prompt for file selection
     */
    public function promptForFileSelection(array $availableFiles, array $scanTargets, $command = null): array
    {
        if (count($availableFiles) <= 1) {
            return array_keys($availableFiles);
        }

        $displayChoices = ['__ALL_FILES__' => '-- ALL FILES --'] +
            collect($availableFiles)->mapWithKeys(function ($contextualFileKey) use ($scanTargets) {
                [$targetKey, $fileKey] = explode('::', $contextualFileKey, 2);

                $targetName = $scanTargets[$targetKey]['name'] ?? $targetKey;

                if (str_ends_with($fileKey, '__JSON__')) {
                    $path = str_replace('__JSON__', '', $fileKey);
                    $displayName = "{$targetName}: JSON File ({$path}*.json)";
                } else {
                    $displayName = "{$targetName}: {$fileKey}.php";
                }
                return [$contextualFileKey => $displayName];
            })->all();

        $selected = $this->promptForMultiChoice(
            label: 'Which translation files would you like to process?',
            options: $displayChoices,
            hint: 'Use comma-separated numbers (e.g., "1,3") on Windows/simple terminals. Use <space> to select, <enter> to confirm on other systems.',
            default: ['__ALL_FILES__'],
            command: $command
        );

        // Handle different selection scenarios:
        // 1. User selected "-- ALL FILES --" option
        // 2. User manually selected all individual files (e.g., 1,2,3,4,5,6,7,8,9)
        // 3. User selected both "-- ALL FILES --" and some individual files
        // In all cases above, return all available files

        if (in_array('__ALL_FILES__', $selected)) {
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

    /**
     * Prompt for consolidation
     */
    public function promptForConsolidation(bool $hasModulesSelected, bool $noInteraction = false, bool $consolidateModulesOption = false): bool
    {
        if ($hasModulesSelected && !$consolidateModulesOption && !$noInteraction) {
            return confirm(
                label: 'Consolidate all module translations into the main application\'s `lang` directory?',
                default: false,
                hint: 'No: Keep translations inside each module (e.g., Modules/Settings/lang). Yes: Put all translations in the root `lang/`.'
            );
        }

        return $consolidateModulesOption;
    }

    /**
     * Prompt for multi choice
     * Accepts command context to enable Windows fallback functionality
     */
    public function promptForMultiChoice(string $label, array $options, string $hint = '', ?array $default = null, $command = null): array
    {
        // 1️⃣ Non-interactive environment (CI, cron, supervisor)
        // Do not prompt. Just return defaults or everything.
        if ($command && method_exists($command, 'isInteractive') && !$command->isInteractive()) {
            return $default ?? array_keys($options);
        }

        // 2️⃣ Windows interactive terminal fallback
        // Laravel Prompts multiselect does NOT work properly on Windows cmd/powershell
        if (PHP_OS_FAMILY === 'Windows') {
            if ($command) {
                $command->line("<fg=yellow;options=bold>{$label}</>");
                if ($hint) {
                    $command->comment($hint);
                }

                // Basic numbered list selection using the command's choice method (the original approach)
                $selection = $command->choice(
                    question: $label,
                    choices: array_values($options),
                    default: null,
                    attempts: null,
                    multiple: true
                );

                $flipped = array_flip($options);

                return array_values(
                    array_filter(
                        array_map(fn($display) => $flipped[$display] ?? null, $selection)
                    )
                );
            } else {
                // If no command context is provided, fall back to multiselect
                // This maintains backward compatibility while allowing the fix when command is available
                return multiselect(
                    label: $label,
                    options: $options,
                    hint: $hint,
                    default: $default ?? []
                );
            }
        }

        // 3️⃣ Interactive Linux/macOS → full multiselect UI
        return multiselect(
            label: $label,
            options: $options,
            hint: $hint,
            default: $default ?? []
        );
    }
}
