<?php
$paths = [
    'ffmpeg',
    '/usr/bin/ffmpeg',
    '/usr/local/bin/ffmpeg',
    '/snap/bin/ffmpeg',
];

echo "Checking FFMPEG paths:\n";
foreach ($paths as $path) {
    $output = [];
    $return_var = 0;
    exec("$path -version 2>&1", $output, $return_var);
    echo "Path: $path | Return: $returnVar | Output: " . ($output[0] ?? 'N/A') . "\n";
}
