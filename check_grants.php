<?php
use Illuminate\Support\Facades\DB;
try {
    $grants = DB::select("SHOW GRANTS FOR CURRENT_USER");
    foreach ($grants as $g) {
        foreach ($g as $k => $v) { echo $v . "\n"; }
    }
} catch (\Exception $e) {
    echo $e->getMessage();
}
