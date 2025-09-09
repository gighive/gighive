<?php declare(strict_types=1);
namespace Production\Api\Controllers;

use OpenApi\Annotations as OA;

/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         version="1.0.0",
 *         title="StormPigs API",
 *         description="API documentation for StormPigs",
 *         @OA\Contact(
 *             name="Your Name",
 *             email="your-email@example.com"
 *         ),
 *         @OA\License(
 *             name="MIT",
 *             url="https://opensource.org/licenses/MIT"
 *         )
 *     ),
 *     @OA\Server(
 *         url="https://yourapi.com/api",
 *         description="Production Server"
 *     )
 * )
 */

/**
 * @OA\Tag(
 *     name="API",
 *     description="Endpoints related to API operations"
 * )
 */

/**
 * @OA\PathItem(
 *     path="/api"
 * )
 */
class BaseController {
    // Base functionality for API controllers
}
?>
