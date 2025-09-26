<?php declare(strict_types=1);
namespace Production\Api\Controllers;

use GuzzleHttp\Psr7\Response;
use Production\Api\Repositories\SessionRepository;
use Production\Api\Presentation\ViewRenderer;

final class MediaController
{
    public function __construct(
        private SessionRepository $repo,
        private ?ViewRenderer $view = null
    ) {
        $this->view = $this->view ?? new ViewRenderer();
    }

    private static function secondsToHms(?string $seconds): string
    {
        if ($seconds === null || $seconds === '' || !ctype_digit((string)$seconds)) {
            return '';
        }
        $s = (int)$seconds;
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $sec);
    }

    public function list(): Response
    {
        $rows = $this->repo->fetchMediaList();

        $counter = 1;
        $viewRows = [];
        foreach ($rows as $row) {
            $id        = isset($row['id']) ? (string)$row['id'] : '';
            $date      = (string)($row['date'] ?? '');
            $orgName   = (string)($row['org_name'] ?? '');
            $rating    = (string)($row['rating'] ?? '');
            $keywords  = (string)($row['keywords'] ?? '');
            $duration  = self::secondsToHms(isset($row['duration_seconds']) ? (string)$row['duration_seconds'] : '');
            $durationSec = isset($row['duration_seconds']) && $row['duration_seconds'] !== null
                ? (string)$row['duration_seconds']
                : '';
            $location  = (string)($row['location'] ?? '');
            $summary   = (string)($row['summary'] ?? '');
            $crew      = (string)($row['crew'] ?? '');
            $songTitle = (string)($row['song_title'] ?? '');
            $typeRaw   = (string)($row['file_type'] ?? '');
            $file      = (string)($row['file_name'] ?? '');

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $dir = ($ext === 'mp3') ? '/audio' : (($ext === 'mp4') ? '/video' : '');
            if ($dir === '' && ($typeRaw === 'audio' || $typeRaw === 'video')) {
                $dir = '/' . $typeRaw;
            }
            $url = ($dir && $file) ? $dir . '/' . rawurlencode($file) : '';

            $viewRows[] = [
                'id'        => $id,
                'idx'       => $counter++,
                'date'      => $date,
                'org_name'  => $orgName,
                'rating'    => $rating,
                'keywords'  => $keywords,
                'duration'  => $duration,
                'durationSec' => $durationSec,
                'location'  => $location,
                'summary'   => $summary,
                'crew'      => $crew,
                'songTitle' => $songTitle,
                'type'      => $typeRaw,
                'url'       => $url,
            ];
        }

        return $this->view->render('media/list.php', [
            'rows' => $viewRows,
        ]);
    }
}
