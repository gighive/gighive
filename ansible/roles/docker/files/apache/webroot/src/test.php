<?php
// Simple test script to verify /src routing works
echo "SUCCESS: /src/test.php is working!\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'null') . "\n";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'null') . "\n";
echo "PATH_INFO: " . ($_SERVER['PATH_INFO'] ?? 'null') . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
?>
