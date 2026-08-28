import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

function findBladeFiles(dir) {
    let results = [];
    let list;
    try {
        list = readdirSync(dir);
    } catch {
        return results;
    }
    for (const file of list) {
        const path = join(dir, file);
        let stat;
        try {
            stat = statSync(path);
        } catch {
            continue;
        }
        if (stat && stat.isDirectory()) {
            results = results.concat(findBladeFiles(path));
        } else if (path.endsWith('.blade.php')) {
            results.push(path.replace(/\\/g, '/'));
        }
    }
    return results;
}

const files = findBladeFiles('resources/views');

const appScriptPattern = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;

const filesWithAppScripts = files.filter((file) => {
    const contents = readFileSync(file, 'utf8');

    if (/<script\b[^>]*\btype\s*=\s*["']application\/ld\+json["']/i.test(contents)) {
        return false;
    }

    let match;

    while ((match = appScriptPattern.exec(contents)) !== null) {
        const attributes = match[1] ?? '';

        if (/\bsrc\s*=/i.test(attributes) || /\btype\s*=\s*["']application\/ld\+json["']/i.test(attributes)) {
            continue;
        }

        if (match[2].trim() !== '') {
            return true;
        }
    }

    return false;
});

const mode = process.argv[2];
if (mode === '--write' || mode === '--check') {
    if (filesWithAppScripts.length > 0) {
        try {
            execFileSync('npx', ['prettier', mode, ...filesWithAppScripts], {
                stdio: 'inherit',
                shell: true,
            });
        } catch (error) {
            process.exit(error.status ?? 1);
        }
    }
} else {
    process.stdout.write(filesWithAppScripts.join('\n'));

    if (filesWithAppScripts.length > 0) {
        process.stdout.write('\n');
    }
}
