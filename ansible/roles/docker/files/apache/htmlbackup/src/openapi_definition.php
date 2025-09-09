<?php
require __DIR__ . '/../../vendor/autoload.php';

use OpenApi\Annotations as OA;

/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         version="1.0.0",
 *         title="Test API",
 *         description="Testing OpenAPI"
 *     ),
 *     @OA\Server(
 *         url="https://musiclibrary-dev",
 *         description="Music Library"
 *     )
 * )
 */
return new OA\OpenApi([]);

