<?php

use Illuminate\Support\Facades\DB;

try {
    $result = DB::select("SHOW CREATE TABLE orders");
    print_r($result);
    
    $result2 = DB::select("SHOW FULL TABLES WHERE Tables_in_nuviora = 'orders'");
    print_r($result2);

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
