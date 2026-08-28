import html from 'eslint-plugin-html';
import phpMarkup from 'eslint-plugin-php-markup';

const bladeInlineScriptProcessor = {
    meta: {
        name: 'blade-inline-script-processor',
        version: '1.0.0',
    },
    preprocess(text, filename) {
        const lines = text.split(/\r?\n/);
        const output = Array(lines.length).fill('');
        let inScript = false;
        let skipCurrentScript = false;

        lines.forEach((line, index) => {
            if (!inScript) {
                const scriptMatch = line.match(/<script\b([^>]*)>/i);

                if (!scriptMatch) {
                    return;
                }

                const attributes = scriptMatch[1] ?? '';
                skipCurrentScript = /\bsrc\s*=/i.test(attributes) || /\btype\s*=\s*["']application\/ld\+json["']/i.test(attributes);
                inScript = true;

                const afterOpenTag = line.slice((scriptMatch.index ?? 0) + scriptMatch[0].length);
                const closeIndex = afterOpenTag.search(/<\/script>/i);
                const scriptLine = closeIndex >= 0 ? afterOpenTag.slice(0, closeIndex) : afterOpenTag;

                if (!skipCurrentScript) {
                    output[index] = sanitizeBladeScriptLine(scriptLine);
                }

                if (closeIndex >= 0) {
                    inScript = false;
                    skipCurrentScript = false;
                }

                return;
            }

            const closeIndex = line.search(/<\/script>/i);
            const scriptLine = closeIndex >= 0 ? line.slice(0, closeIndex) : line;

            if (!skipCurrentScript) {
                output[index] = sanitizeBladeScriptLine(scriptLine);
            }

            if (closeIndex >= 0) {
                inScript = false;
                skipCurrentScript = false;
            }
        });

        return [
            {
                text: output.join('\n'),
                filename: `${filename}.js`,
            },
        ];
    },
    postprocess(messages) {
        return messages.flat();
    },
    supportsAutofix: false,
};

function sanitizeBladeScriptLine(line) {
    return line
        .replace(/{{--.*?--}}/g, '')
        .replace(/@this\b/g, 'window.Livewire')
        .replace(/@\w+(?:\s*\([^)]*\))?/g, 'undefined')
        .replace(/\{!!.*?!!\}/g, 'undefined')
        .replace(/\{\{.*?\}\}/g, 'undefined');
}

export default [
    {
        ignores: ['node_modules/**', 'vendor/**', 'public/dist/**', 'storage/framework/views/**'],
    },
    {
        files: ['resources/views/**/*.blade.php'],
        plugins: {
            html,
            'php-markup': phpMarkup,
            'blade-inline-scripts': {
                processors: {
                    scripts: bladeInlineScriptProcessor,
                },
            },
        },
        processor: 'blade-inline-scripts/scripts',
    },
    {
        files: ['resources/views/**/*.blade.php.js'],
        plugins: {
            html,
            'php-markup': phpMarkup,
        },
        settings: {
            'html/html-extensions': ['.blade.php'],
            'php/php-extensions': ['.blade.php'],
            'php/markup-replacement': {
                php: '',
                '=': '0',
            },
            'php/keep-eol': true,
            'php/remove-whitespace': false,
            'php/remove-empty-line': false,
            'php/remove-php-lint': false,
        },
        languageOptions: {
            ecmaVersion: 2023,
            sourceType: 'script',
            globals: {
                AbortController: 'readonly',
                clearTimeout: 'readonly',
                console: 'readonly',
                document: 'readonly',
                fetch: 'readonly',
                HTMLDialogElement: 'readonly',
                HTMLElement: 'readonly',
                JSON: 'readonly',
                localStorage: 'readonly',
                setTimeout: 'readonly',
                window: 'readonly',
            },
        },
        rules: {
            eqeqeq: ['error', 'always', { null: 'ignore' }],
            'no-console': 'off',
            'no-undef': 'error',
            'no-unused-vars': [
                'warn',
                {
                    argsIgnorePattern: '^_|^e$',
                    args: 'after-used',
                    caughtErrorsIgnorePattern: '^_|^e$',
                    ignoreRestSiblings: true,
                    varsIgnorePattern: '^_',
                },
            ],
        },
    },
];
