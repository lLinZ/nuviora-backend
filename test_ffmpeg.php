<?php
$output = [];
$return_var = 0;
exec('ffmpeg -version', $output, $return_var);
echo "Return var: " . $return_var . "\n";
echo "Output: " . implode("\n", $output) . "\n";

if ($return_var === 0) {
    echo "FFMPEG is installed.\n";
} else {
    echo "FFMPEG is NOT installed.\n";
}
