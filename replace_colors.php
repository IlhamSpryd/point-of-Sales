<?php
$dir = new RecursiveDirectoryIterator('resources/views');
$ite = new RecursiveIteratorIterator($dir);
$files = new RegexIterator($ite, '/.*\.blade\.php$/', RegexIterator::GET_MATCH);
$count = 0;
foreach($files as $file) {
    $path = $file[0];
    $content = file_get_contents($path);
    $original = $content;
    
    // Replace gray-900, gray-800, black with yovel-ink
    $content = preg_replace('/\btext-(gray-900|gray-800|black)\b/', 'text-yovel-ink', $content);
    $content = preg_replace('/\bbg-(gray-900|gray-800|black)\b/', 'bg-yovel-ink', $content);
    $content = preg_replace('/\bborder-(gray-900|gray-800|black)\b/', 'border-yovel-ink', $content);
    $content = preg_replace('/\bshadow-(gray-900|gray-800|black)\b/', 'shadow-yovel-ink', $content);
    
    // Also replace hardcoded [#37352F]
    $content = str_replace('text-[#37352F]', 'text-yovel-ink', $content);
    $content = str_replace('bg-[#37352F]', 'bg-yovel-ink', $content);
    $content = str_replace('border-[#37352F]', 'border-yovel-ink', $content);

    if ($original !== $content) {
        file_put_contents($path, $content);
        echo 'Updated: ' . $path . PHP_EOL;
        $count++;
    }
}
echo 'Total files updated: ' . $count . PHP_EOL;
