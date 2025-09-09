<?php declare(strict_types=1);
namespace Production\Api\Controllers;

/**
 * @OA\Tag(
 *     name="Songs",
 *     description="API endpoints related to songs"
 * )
 * @OA\PathItem(path="/songs")
 * @OA\Get(
 *     path="/songs",
 *     summary="Retrieve all songs",
 *     operationId="getAllSongs",
 *     tags={"Songs"},
 *     @OA\Response(
    @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Jam"))
 *         response=200,
 *         description="A list of songs",
 *         @OA\JsonContent(type="object")
 *     )
 * )
 */
class SongController {
    public function getAllSongs() {
        // Implementation here
    }
}
