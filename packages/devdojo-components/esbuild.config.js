const esbuild = require('esbuild');
const path = require('path');
const fs = require('fs');

const isProduction = process.env.NODE_ENV === 'production';
const isWatch = process.argv.includes('--watch');

// Compiled assets live in public/devdojo and are published to the host app's
// public/devdojo via `php artisan vendor:publish --tag=devdojo-assets`.
const outputDir = path.join(__dirname, 'public', 'devdojo');
if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
}

const sourceDir = path.join(__dirname, 'resources', 'js');
const entryPoints = {};

if (fs.existsSync(sourceDir)) {
    fs.readdirSync(sourceDir)
        .filter((file) => file.endsWith('.js'))
        .forEach((file) => {
            entryPoints[file.replace('.js', '')] = path.join(sourceDir, file);
        });
}

const buildOptions = {
    entryPoints,
    outdir: outputDir,
    format: 'iife',
    bundle: true,
    sourcemap: false,
    minify: isProduction,
    platform: 'browser',
    loader: {
        '.ttf': 'file',
    },
};

(async () => {
    try {
        if (isWatch) {
            const ctx = await esbuild.context(buildOptions);
            await ctx.watch();
            console.log('Watching resources/js for changes…');
        } else {
            await esbuild.build(buildOptions);
            console.log(`✓ Built component assets to ${outputDir}`);
        }
    } catch (err) {
        console.error('Build failed:', err);
        process.exit(1);
    }
})();
