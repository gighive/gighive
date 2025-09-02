<?php
require __DIR__ . '/../../vendor/autoload.php';

use OpenApi\Generator;
use OpenApi\Annotations as OA;

echo "✅ OpenAPI is loaded!\n";

/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         version="1.0.0",
 *         title="Standalone Test API",
 *         description="Testing OpenAPI standalone."
 *     )
 * )
 */

echo "✅ Annotations loaded!\n";

$openApi = Generator::scan([__FILE__]);
print_r($openApi);

