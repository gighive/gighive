<?php declare(strict_types=1);
namespace Production\Api\Controllers;

use GuzzleHttp\Psr7\Response;
use Production\Api\Repositories\SessionRepository;
use Production\Api\Presentation\ViewRenderer;

final class RandomController
{
    public function __construct(
        private SessionRepository $repo,
        private ?ViewRenderer $view = null
    ) {
        $this->view = $this->view ?? new ViewRenderer();
    }

    public function playRandom(): Response
    {
        $rows = $this->repo->fetchMediaList();

        // Normalize rows to compute URL similarly to MediaController
        $playable = [];
        foreach ($rows as $row) {
            $file      = (string)($row['file_name'] ?? '');
            $typeRaw   = (string)($row['file_type'] ?? '');
            $ext       = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $dir       = ($ext === 'mp3') ? '/audio' : (($ext === 'mp4') ? '/video' : '');
            if ($dir === '' && ($typeRaw === 'audio' || $typeRaw === 'video')) {
                $dir = '/' . $typeRaw;
            }
            $url = ($dir && $file) ? $dir . '/' . rawurlencode($file) : '';
            if ($url !== '') {
                $playable[] = [
                    'url'       => $url,
                    'file_name' => $file,
                    'crew'      => (string)($row['crew'] ?? ''),
                    'date'      => (string)($row['date'] ?? ''),
                    'type'      => ($ext === 'mp4' ? 'video' : ($ext === 'mp3' ? 'audio' : $typeRaw)),
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
