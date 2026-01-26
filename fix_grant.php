<?php
use Illuminate\Support\Facades\DB;
try {
    DB::statement("GRANT ALTER ON *.* TO 'root'@'localhost'");
    echo "Granted ALTER\n";
} catch (\Exception $e) {
    echo "Grant failed: " . $e->getMessage() . "\n";
}
