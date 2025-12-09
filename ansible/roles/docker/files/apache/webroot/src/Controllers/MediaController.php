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

        $targetDate = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
        $targetOrg  = isset($_GET['org'])  ? trim((string)$_GET['org'])  : '';

        // Basic validation: expect YYYY-MM-DD for date; empty strings are treated as nulls
        if ($targetDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            $targetDate = '';
        }

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
            'rows'       => $viewRows,
            'targetDate' => $targetDate !== '' ? $targetDate : null,
            'targetOrg'  => $targetOrg  !== '' ? $targetOrg  : null,
        ]);
    }

    /**
     * Return media list as JSON instead of HTML
     */
    public function listJson(): Response
    {
        $rows = $this->repo->fetchMediaList();

        $counter = 1;
        $entries = [];
        foreach ($rows as $row) {
            $id        = isset($row['id']) ? (int)$row['id'] : 0;
            $date      = (string)($row['date'] ?? '');
            $orgName   = (string)($row['org_name'] ?? '');
            $duration  = self::secondsToHms(isset($row['duration_seconds']) ? (string)$row['duration_seconds'] : '');
            $durationSec = isset($row['duration_seconds']) && $row['duration_seconds'] !== null
                ? (int)$row['duration_seconds']
                : 0;
            $songTitle = (string)($row['song_title'] ?? '');
            $typeRaw   = (string)($row['file_type'] ?? '');
            $file      = (string)($row['file_name'] ?? '');

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $dir = ($ext === 'mp3') ? '/audio' : (($ext === 'mp4') ? '/video' : '');
            if ($dir === '' && ($typeRaw === 'audio' || $typeRaw === 'video')) {
                $dir = '/' . $typeRaw;
            }
            $url = ($dir && $file) ? $dir . '/' . rawurlencode($file) : '';

            $entries[] = [
                'id'               => $id,
                'index'            => $counter++,
                'date'             => $date,
                'org_name'         => $orgName,
                'duration'         => $duration,
                'duration_seconds' => $durationSec,
                'song_title'       => $songTitle,
                'file_type'        => $typeRaw,
                'file_name'        => $file,
                'url'              => $url,
            ];
        }

        $body = json_encode(['entries' => $entries], JSON_PRETTY_PRINT);
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }
}
