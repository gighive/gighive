<?php declare(strict_types=1);

// Simple router - delegate to the MVC router in /src/
// This keeps the /api/uploads.php URL working while using the new architecture

// Set up the environment to make /src/index.php think it's handling /api/uploads
$_SERVER['REQUEST_URI'] = '/api/uploads' . ($_SERVER['PATH_INFO'] ?? '');

// Include the MVC router
require_once __DIR__ . '/../src/index.php';
