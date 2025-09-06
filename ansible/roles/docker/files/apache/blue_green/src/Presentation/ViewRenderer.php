<?php declare(strict_types=1);
namespace Production\Api\Presentation;

use GuzzleHttp\Psr7\Response;

final class ViewRenderer
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        // Default to <project>/src/Views/
        $this->baseDir = $baseDir ?? dirname(__DIR__) . '/Views';
    }

    /**
     * Render a PHP template under the baseDir and return a PSR-7 Response.
     *
     * @param string $template Relative path under views/, e.g. 'media/list.php'
     * @param array  $data     Variables available to the template
     */
    public function render(string $template, array $data = []): Response
    {
        $file = rtrim($this->baseDir, '/').'/'.ltrim($template, '/');
        if (!is_file($file)) {
            $body = sprintf('Template not found: %s', $file);
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
        }

        // Extract variables for the template
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            /** @noinspection PhpIncludeInspection */
            include $file;
            $html = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $html = 'Template error: ' . $e->getMessage();
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], $html);
        }

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }
}
