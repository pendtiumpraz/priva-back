<?php
/**
 * Extend every `$user->role === 'superadmin'` guard so 'root' passes too.
 * Only broadens, never narrows. Leaves query-filter `'role' => 'superadmin'`
 * untouched since those are literal where() equals on a column.
 *
 *   php scripts/root-inherit.php [--apply]
 */

$root = dirname(__DIR__) . '/app';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) $files[] = $f->getPathname();
}

$rules = [
    '/(\$[a-zA-Z_][\w>\-?]*->role)\s*===\s*[\'"]superadmin[\'"]/' => 'EQ',
    '/(\$[a-zA-Z_][\w>\-?]*->role)\s*!==\s*[\'"]superadmin[\'"]/' => 'NEQ',
];

$apply = in_array('--apply', $argv);
$touched = 0; $total = 0; $preview = [];
foreach ($files as $f) {
    $src = file_get_contents($f);
    $orig = $src;
    $subs = 0;
    foreach ($rules as $re => $kind) {
        $src = preg_replace_callback($re, function($m) use (&$subs, $kind) {
            $subs++;
            return $kind === 'EQ'
                ? "in_array({$m[1]}, ['root','superadmin'], true)"
                : "! in_array({$m[1]}, ['root','superadmin'], true)";
        }, $src);
    }
    if ($src !== $orig) {
        $touched++; $total += $subs;
        $preview[] = [str_replace($root.DIRECTORY_SEPARATOR, '', $f), $subs];
        if ($apply) file_put_contents($f, $src);
    }
}
echo "\n=== root-inherit " . ($apply ? '(APPLIED)' : '(DRY RUN)') . " ===\n";
echo "Files touched: $touched\n";
echo "Substitutions: $total\n\n";
foreach ($preview as [$f,$s]) echo "  " . str_pad((string)$s, 3, ' ', STR_PAD_LEFT) . "  $f\n";
if (!$apply) echo "\nRun with --apply to write.\n";
