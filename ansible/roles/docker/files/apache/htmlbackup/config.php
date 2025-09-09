<?php
require __DIR__ . '/vendor/autoload.php';

// Load .env from project root
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Define constants from env
define('SMTP_HOST',   $_ENV['SMTP_HOST']);
define('SMTP_PORT',   (int) $_ENV['SMTP_PORT']);
define('SMTP_USER',   $_ENV['SMTP_USER']);
define('SMTP_PASS',   $_ENV['SMTP_PASS']);
define('FROM_EMAIL',  $_ENV['FROM_EMAIL']);
define('SITE_URL',    rtrim($_ENV['SITE_URL'], '/'));

