<?php declare(strict_types=1);
namespace Production\Api\Controllers;

/**
 * @OA\Tag(name="API")
 * @OA\Get(
 *     path="/files/song/{songId}",
 *     summary="Retrieve file information for a specific song",
 *     @OA\Parameter(
    @OA\Schema(type="integer"),
 *         name="songId",
 *         in="path",
 *         required=true,
 *         description="Song ID",
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(
    @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Jam"))
 *         response=200,
 *         description="File details for the given song",
 *         @OA\JsonContent(type="object")
 *     ),
 *     @OA\Response(
    @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Jam"))
 *         response=404,
 *         description="No file found for this song",
 *         @OA\JsonContent(type="object")
 *     )
 * )
 */
class FileController {
    public function getFileBySongId($songId) {
        // Implementation here
    }
}
