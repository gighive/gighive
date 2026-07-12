<?php declare(strict_types=1);

function json_ok(mixed $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function json_err(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
