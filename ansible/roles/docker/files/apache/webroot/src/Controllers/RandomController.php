<?php declare(strict_types=1);
namespace Production\Api\Controllers;

use GuzzleHttp\Psr7\Response;
use Production\Api\Repositories\AssetRepository;
use Production\Api\Presentation\ViewRenderer;

final class RandomController
{
    public function __construct(
        private AssetRepository $assetRepo,
        private ?ViewRenderer $view = null
    ) {
        $this->view = $this->view ?? new ViewRenderer();
    }

    public function playRandom(): Response
    {
        $rows = $this->assetRepo->fetchAll();

        $playable = [];
        foreach ($rows as $row) {
            $checksumSha256 = (string)($row['checksum_sha256'] ?? '');
            $fileExt        = (string)($row['file_ext'] ?? '');
            $typeRaw        = (string)($row['file_type'] ?? '');
            $sourceRelpath  = (string)($row['source_relpath'] ?? '');

            $ext = $fileExt !== '' ? strtolower($fileExt) : strtolower(pathinfo($sourceRelpath, PATHINFO_EXTENSION));
            $servedFile = ($checksumSha256 !== '' && preg_match('/^[a-f0-9]{64}$/i', $checksumSha256) === 1)
                ? ($ext !== '' ? $checksumSha256 . '.' . $ext : $checksumSha256)
                : '';
            $dir = ($typeRaw === 'audio' || $typeRaw === 'video') ? '/' . $typeRaw : '';
            $url = ($dir && $servedFile) ? $dir . '/' . rawurlencode($servedFile) : '';
            if ($url !== '') {
                $playable[] = [
                    'url'       => $url,
                    'file_name' => $servedFile,
                    'crew'      => (string)($row['crew'] ?? ''),
                    'date'      => (string)($row['date'] ?? ''),
                    'type'      => $typeRaw,
                ];
            }
        }

        if (empty($playable)) {
            $body = json_encode(['error' => 'No playable media found']);
            return new Response(404, ['Content-Type' => 'application/json'], $body);
        }

        $idx = random_int(0, count($playable) - 1);
        $selected = $playable[$idx];

        // JSON mode support for in-page swap
        $format = $_GET['format'] ?? '';
        if ($format === 'json') {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode($selected));
        }

        // Simple (old-style) UI if requested
        $ui = $_GET['ui'] ?? '';
        if ($ui === 'simple' || $ui === '1') {
            return $this->view->render('media/random_simple.php', [
                'media' => $selected,
            ]);
        }

        return $this->view->render('media/random_player.php', [
            'media' => $selected,
        ]);
    }
}
