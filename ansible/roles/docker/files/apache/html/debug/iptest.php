<?php
// Get the server address
$serverAddress = $_SERVER['SERVER_ADDR'] ?? 'Unknown';
// Display the server address
echo "You are hitting server address: " . $serverAddress;
echo "<br>";

// Get the remote server address
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
// Display the server address
echo "You are hitting remote address: " . $remoteAddress;
echo "<br>";

// Get the http_x_forwarded_host address
$xhostAddress = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? 'Unknown';
// Display the server address
echo "You are hitting forwarded_host address: " . $xhostAddress;
echo "<br>";

// Get the http_x_forwarded_server address
$xserverAddress = $_SERVER['HTTP_X_FORWARDED_SERVER'] ?? 'Unknown';
// Display the server address
echo "You are hitting forwarded_server address: " . $xserverAddress;
echo "<br>";

// Get the http_via address
$xviaAddress = $_SERVER['HTTP_VIA'] ?? 'Unknown';
// Display the server address
echo "You are hitting via address: " . $xviaAddress;
echo "<br>";

// Check if the X-Forwarded-For header exists
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    // Display the value of the X-Forwarded-For header
    echo "X-Forwarded-For: " . htmlspecialchars($_SERVER['HTTP_X_FORWARDED_FOR']);
} else {
    echo "X-Forwarded-For header is not set.";
    echo "<br>";
}
?>
