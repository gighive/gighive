<?php
namespace Production\Api;

use OpenApi\Annotations as OA;

/**
 * @OA\PathItem(
 *     path="/test"
 * )
 */
class TestApi {
    /**
     * @OA\Get(
     *     path="/test",
     *     summary="Test endpoint",
     *     @OA\Response(
     *         response=200,
     *         description="Successful response"
     *     )
     * )
     */
    public function testMethod() {
        echo json_encode(["message" => "Hello, world!"]);
    }
}

