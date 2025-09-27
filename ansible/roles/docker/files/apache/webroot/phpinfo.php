<?php
/**
 * PHP Information Debug Page
 * 
 * WARNING: This file exposes sensitive server configuration information.
 * Only use for debugging and remove from production servers.
 * 
 * Access: https://your-domain.com/phpinfo.php
 */

// Security check - only allow access from specific IPs or with authentication
// Uncomment and modify as needed:
// $allowed_ips = ['127.0.0.1', '::1', 'your.ip.address.here'];
// if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
//     http_response_code(403);
//     die('Access denied');
// }

// Display PHP configuration information
phpinfo();

// Additional custom checks for upload capabilities
echo '<hr><h2>Custom Upload Configuration Checks</h2>';
echo '<table border="1" cellpadding="5" cellspacing="0">';
echo '<tr><th>Setting</th><th>Value</th><th>Recommended for Large Uploads</th></tr>';

$checks = [
    'upload_max_filesize' => ['current' => ini_get('upload_max_filesize'), 'recommended' => '2G or higher'],
    'post_max_size' => ['current' => ini_get('post_max_size'), 'recommended' => '2G or higher'],
    'max_execution_time' => ['current' => ini_get('max_execution_time'), 'recommended' => '300 or 0 (unlimited)'],
    'max_input_time' => ['current' => ini_get('max_input_time'), 'recommended' => '300 or -1 (unlimited)'],
    'memory_limit' => ['current' => ini_get('memory_limit'), 'recommended' => '512M or higher'],
    'file_uploads' => ['current' => ini_get('file_uploads') ? 'On' : 'Off', 'recommended' => 'On'],
    'max_file_uploads' => ['current' => ini_get('max_file_uploads'), 'recommended' => '20 or higher'],
];

foreach ($checks as $setting => $info) {
    echo "<tr>";
    echo "<td><strong>{$setting}</strong></td>";
    echo "<td>{$info['current']}</td>";
    echo "<td>{$info['recommended']}</td>";
    echo "</tr>";
}

echo '</table>';

// Check for relevant PHP extensions
echo '<hr><h2>Relevant PHP Extensions for Uploads</h2>';
echo '<table border="1" cellpadding="5" cellspacing="0">';
echo '<tr><th>Extension</th><th>Status</th><th>Purpose</th></tr>';

$extensions = [
    'curl' => 'HTTP client functionality',
    'fileinfo' => 'File type detection',
    'json' => 'JSON encoding/decoding',
    'mbstring' => 'Multibyte string handling',
    'openssl' => 'SSL/TLS support',
    'pdo' => 'Database connectivity',
    'zip' => 'Archive handling',
];

foreach ($extensions as $ext => $purpose) {
    $status = extension_loaded($ext) ? '<span style="color: green;">✓ Loaded</span>' : '<span style="color: red;">✗ Not loaded</span>';
    echo "<tr><td><strong>{$ext}</strong></td><td>{$status}</td><td>{$purpose}</td></tr>";
}

echo '</table>';

// Server information
echo '<hr><h2>Server Environment</h2>';
echo '<table border="1" cellpadding="5" cellspacing="0">';
echo '<tr><th>Variable</th><th>Value</th></tr>';

$server_vars = [
    'SERVER_SOFTWARE',
    'HTTP_HOST',
    'REQUEST_METHOD',
    'REQUEST_URI',
    'SERVER_PROTOCOL',
    'HTTP_USER_AGENT',
    'REMOTE_ADDR',
    'DOCUMENT_ROOT',
];

foreach ($server_vars as $var) {
    $value = $_SERVER[$var] ?? 'Not set';
    echo "<tr><td><strong>{$var}</strong></td><td>{$value}</td></tr>";
}

echo '</table>';

echo '<hr><p><strong>Note:</strong> Remember to delete this file after debugging!</p>';
?>
