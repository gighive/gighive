<?php
namespace Production\Api;

use Production\Api\Controllers\ResourceController;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class Router
{
    public function dispatch(string $method, string $uri): ResponseInterface
    {
        // Normalize: remove leading/trailing slashes and split path
        $segments = array_values(array_filter(explode('/', trim($uri, '/'))));

        // e.g. GET /items            → index
        //      POST /items           → store
        //      GET /items/{id}       → show
        //      PUT /items/{id}       → update
        //      DELETE /items/{id}    → delete

        if (count($segments) === 1 && $segments[0] === 'items') {
            $ctrl = new ResourceController();
            switch ($method) {
                case 'GET':
                    return $ctrl->index();
                case 'POST':
                    $input = json_decode(file_get_contents('php://input'), true) ?? [];
                    return $ctrl->store($input);
            }
        }

        if (count($segments) === 2 && $segments[0] === 'items' && is_numeric($segments[1])) {
            $id = (int) $segments[1];
            $ctrl = new ResourceController();
            switch ($method) {
                case 'GET':
                    return $ctrl->show($id);
                case 'PUT':
                    $input = json_decode(file_get_contents('php://input'), true) ?? [];
                    return $ctrl->update($id, $input);
                case 'DELETE':
                    return $ctrl->delete($id);
            }
        }

        // Fallback 404
        return new Response(
            404,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => 'Not Found'])
        );
    }
}

