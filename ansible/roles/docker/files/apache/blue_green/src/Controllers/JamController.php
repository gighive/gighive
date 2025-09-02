<?php declare(strict_types=1);
namespace Production\Api\Controllers;

/**
 * @OA\Tag(name="API")
 * @OA\Get(
 *     path="/jams",
 *     summary="Retrieve all jam sessions",
 *     @OA\Response(
    @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Jam"))
 *         response=200,
 *         description="A list of jam sessions",
 *         @OA\JsonContent(type="object")
 *     )
 * )
 */
class JamController {
    public function getAllJams() {
        // Implementation here
    }
}
