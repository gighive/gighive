<?php

use OpenApi\Annotations as OA;

/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         title="StormPigs API",
 *         version="1.0.0",
 *         description="API documentation for the StormPigs music library"
 *     ),
 *     @OA\Server(
 *         url="http://localhost",
 *         description="Local development server"
 *     )
 * )
 */

return new class {}; // <-- This ensures PHP executes the file

