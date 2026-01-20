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

    private static function deriveContainerLabel(array $format): string
    {
        $formatNameRaw = '';
        if (isset($format['format_name']) && is_string($format['format_name'])) {
            $formatNameRaw = trim($format['format_name']);
        }
        $formatLong = '';
        if (isset($format['format_long_name']) && is_string($format['format_long_name'])) {
            $formatLong = trim($format['format_long_name']);
        }

        $tokens = [];
        if ($formatNameRaw !== '') {
            $tokens = array_values(array_filter(array_map('trim', explode(',', $formatNameRaw)), static fn($t) => $t !== ''));
        }

        $tags = isset($format['tags']) && is_array($format['tags']) ? $format['tags'] : [];
        $majorBrand = isset($tags['major_brand']) && is_string($tags['major_brand']) ? strtolower(trim($tags['major_brand'])) : '';
        $compat = isset($tags['compatible_brands']) && is_string($tags['compatible_brands']) ? strtolower(trim($tags['compatible_brands'])) : '';

        // Heuristic: for ISO-BMFF (MP4 family), ffprobe often reports a broad demuxer list.
        // Use brands to collapse to a single friendly container label.
        if ($majorBrand !== '' || $compat !== '') {
            if (
                $majorBrand === 'isom' ||
                $majorBrand === 'iso2' ||
                $majorBrand === 'mp41' ||
                $majorBrand === 'mp42' ||
                str_contains($compat, 'mp41') ||
                str_contains($compat, 'mp42') ||
                str_contains($compat, 'isom') ||
                str_contains($compat, 'iso2')
            ) {
                return 'mp4';
            }
            if (str_starts_with($majorBrand, '3gp') || str_contains($compat, '3gp')) {
                return '3gp';
            }
            if ($majorBrand === 'qt  ' || $majorBrand === 'qt' || str_contains($formatLong, 'quicktime')) {
                return 'mov';
            }
        }

        // Fall back to a single token if present.
        if (!empty($tokens)) {
            return $tokens[0];
        }

        return $formatLong !== '' ? $formatLong : '';
    }

    private static function formatBitrate(?string $bps): string
    {
        if ($bps === null) {
            return '';
        }
        $bps = trim($bps);
        if ($bps === '' || !ctype_digit($bps)) {
            return '';
        }
        $n = (int)$bps;
        if ($n <= 0) {
            return '';
        }
        if ($n >= 1000000) {
            return sprintf('~%.1f Mbps', $n / 1000000);
        }
        return sprintf('~%d kbps', (int)round($n / 1000));
    }

    private static function parseRate(?string $raw): float
    {
        if ($raw === null) {
            return 0.0;
        }
        $raw = trim($raw);
        if ($raw === '' || $raw === '0/0') {
            return 0.0;
        }
        if (str_contains($raw, '/')) {
            [$num, $den] = array_pad(explode('/', $raw, 2), 2, '0');
            $num = is_numeric($num) ? (float)$num : 0.0;
            $den = is_numeric($den) ? (float)$den : 0.0;
            if ($den <= 0.0) {
                return 0.0;
            }
            return $num / $den;
        }
        return is_numeric($raw) ? (float)$raw : 0.0;
    }

    private static function mediaInfoSummary(?string $mediaInfoJson): string
    {
        if ($mediaInfoJson === null) {
            return '';
        }
        $mediaInfoJson = trim($mediaInfoJson);
        if ($mediaInfoJson === '') {
            return '';
        }

        $decoded = json_decode($mediaInfoJson, true);
        if (!is_array($decoded)) {
            return '';
        }

        $lines = [];

        $format = isset($decoded['format']) && is_array($decoded['format']) ? $decoded['format'] : [];
        $formatNameRaw = isset($format['format_name']) && is_string($format['format_name']) ? trim($format['format_name']) : '';
        $formatLong = isset($format['format_long_name']) && is_string($format['format_long_name']) ? trim($format['format_long_name']) : '';
        $formatBitrate = self::formatBitrate(isset($format['bit_rate']) ? (string)$format['bit_rate'] : null);
        $durationInt = 0;
        $durationRaw = isset($format['duration']) ? (string)$format['duration'] : '';
        if ($durationRaw !== '' && is_numeric($durationRaw)) {
            $durationInt = (int)round((float)$durationRaw);
        }

        $firstLineParts = [];
        if ($formatLong !== '') {
            $firstLineParts[] = $formatLong;
        }
        if ($formatNameRaw !== '') {
            $firstLineParts[] = $formatNameRaw;
        }
        if ($formatBitrate !== '') {
            $firstLineParts[] = $formatBitrate;
        }
        if ($durationInt > 0) {
            $firstLineParts[] = (string)$durationInt . 's';
        }
        if (!empty($firstLineParts)) {
            $lines[] = implode(' • ', $firstLineParts);
        }

        $streams = isset($decoded['streams']) && is_array($decoded['streams']) ? $decoded['streams'] : [];

        $bestAudio = null;
        $bestAudioChannels = -1;
        $bestVideo = null;
        $bestVideoPixels = -1;
        foreach ($streams as $s) {
            if (!is_array($s)) {
                continue;
            }
            $type = isset($s['codec_type']) && is_string($s['codec_type']) ? $s['codec_type'] : '';
            if ($type === 'audio') {
                $ch = isset($s['channels']) ? (int)$s['channels'] : 0;
                if ($ch > $bestAudioChannels) {
                    $bestAudioChannels = $ch;
                    $bestAudio = $s;
                }
            } elseif ($type === 'video') {
                $w = isset($s['width']) ? (int)$s['width'] : 0;
                $h = isset($s['height']) ? (int)$s['height'] : 0;
                $px = $w * $h;
                if ($px > $bestVideoPixels) {
                    $bestVideoPixels = $px;
                    $bestVideo = $s;
                }
            }
        }

        if (is_array($bestAudio)) {
            $codec = isset($bestAudio['codec_name']) && is_string($bestAudio['codec_name']) ? $bestAudio['codec_name'] : '';
            $ch = isset($bestAudio['channels']) ? (int)$bestAudio['channels'] : 0;
            $sr = isset($bestAudio['sample_rate']) ? (string)$bestAudio['sample_rate'] : '';
            $srK = '';
            if ($sr !== '' && ctype_digit($sr)) {
                $srK = sprintf('%dkHz', (int)round(((int)$sr) / 1000));
            }
            $abr = self::formatBitrate(isset($bestAudio['bit_rate']) ? (string)$bestAudio['bit_rate'] : null);

            $parts = [];
            if ($codec !== '') {
                $parts[] = $codec;
            }
            if ($ch > 0) {
                $parts[] = $ch . 'ch';
            }
            if ($srK !== '') {
                $parts[] = $srK;
            }
            if ($abr !== '') {
                $parts[] = $abr;
            }
            if (!empty($parts)) {
                $lines[] = 'A: ' . implode(' • ', $parts);
            }
        }

        if (is_array($bestVideo)) {
            $codec = isset($bestVideo['codec_name']) && is_string($bestVideo['codec_name']) ? $bestVideo['codec_name'] : '';
            $w = isset($bestVideo['width']) ? (int)$bestVideo['width'] : 0;
            $h = isset($bestVideo['height']) ? (int)$bestVideo['height'] : 0;
            $fps = self::parseRate(isset($bestVideo['avg_frame_rate']) ? (string)$bestVideo['avg_frame_rate'] : null);
            if ($fps <= 0.0) {
                $fps = self::parseRate(isset($bestVideo['r_frame_rate']) ? (string)$bestVideo['r_frame_rate'] : null);
            }
            $pixFmt = isset($bestVideo['pix_fmt']) && is_string($bestVideo['pix_fmt']) ? trim($bestVideo['pix_fmt']) : '';

            $parts = [];
            if ($codec !== '') {
                $parts[] = $codec;
            }
            if ($w > 0 && $h > 0) {
                $parts[] = $w . 'x' . $h;
            }
            if ($fps > 0.0) {
                $parts[] = sprintf('%.2ffps', $fps);
            }
            if ($pixFmt !== '') {
                $parts[] = $pixFmt;
            }
            if (!empty($parts)) {
                $lines[] = 'V: ' . implode(' • ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    private static function envInt(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false) {
            return $default;
        }
        $raw = trim((string)$raw);
        if ($raw === '' || !ctype_digit($raw)) {
            return $default;
        }
        $val = (int)$raw;
        return $val > 0 ? $val : $default;
    }

    private static function getPageFromRequest(): int
    {
        $raw = isset($_GET['page']) ? trim((string)$_GET['page']) : '';
        if ($raw === '' || !ctype_digit($raw)) {
            return 1;
        }
        $page = (int)$raw;
        return $page > 0 ? $page : 1;
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

    private static function servedFileName(?string $checksumSha256, string $sourceRelpath, string $fallbackFileName): string
    {
        $checksumSha256 = $checksumSha256 !== null ? trim($checksumSha256) : '';
        $ext = strtolower(pathinfo($sourceRelpath, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = strtolower(pathinfo($fallbackFileName, PATHINFO_EXTENSION));
        }

        if ($checksumSha256 !== '' && preg_match('/^[a-f0-9]{64}$/i', $checksumSha256) === 1) {
            return $ext !== '' ? ($checksumSha256 . '.' . $ext) : $checksumSha256;
        }

        return $fallbackFileName;
    }

    private static function getFiltersFromRequest(): array
    {
        $keys = [
            'date',
            'org_name',
            'rating',
            'keywords',
            'location',
            'summary',
            'crew',
            'song_title',
            'file_type',
            'file_name',
            'source_relpath',
            'duration_seconds',
            'media_info',
        ];

        $filters = [];
        foreach ($keys as $k) {
            $raw = isset($_GET[$k]) ? trim((string)$_GET[$k]) : '';
            if ($raw === '') {
                continue;
            }
            $filters[$k] = $raw;
        }

        return $filters;
    }

    public function list(): Response
    {
        $filters = self::getFiltersFromRequest();
        [$filterErrors, $filterWarnings] = $this->repo->validateMediaListFilters($filters);

        $appFlavor = getenv('APP_FLAVOR');
        $appFlavor = $appFlavor !== false ? trim((string)$appFlavor) : '';
        if ($appFlavor === '') {
            $appFlavor = 'stormpigs';
        }

        $threshold = self::envInt('MEDIA_LIST_PAGINATION_THRESHOLD', 750);
        if (!empty($filterErrors)) {
            $targetDate = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
            $targetOrg  = isset($_GET['org'])  ? trim((string)$_GET['org'])  : '';
            if ($targetOrg === '') {
                $targetOrg = isset($_GET['org_name']) ? trim((string)$_GET['org_name']) : '';
            }

            if ($targetDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
                $targetDate = '';
            }

            $query = $_GET;
            unset($query['page']);

            return $this->view->render('media/list.php', [
                'rows'       => [],
                'targetDate' => $targetDate !== '' ? $targetDate : null,
                'targetOrg'  => $targetOrg  !== '' ? $targetOrg  : null,
                'pagination' => [
                    'enabled' => false,
                    'total' => 0,
                    'threshold' => $threshold,
                    'page' => 1,
                    'pageSize' => $threshold,
                    'pageCount' => 1,
                    'start' => 0,
                    'end' => 0,
                ],
                'query' => $query,
                'appFlavor' => $appFlavor,
                'searchErrors' => $filterErrors,
                'searchWarnings' => $filterWarnings,
            ]);
        }

        $totalRows = $this->repo->countMediaListRows($filters);

        $pageSize = $threshold;
        $page = self::getPageFromRequest();
        $pageCount = 1;
        $paginationEnabled = $totalRows >= $threshold;
        $offset = 0;

        if ($paginationEnabled) {
            $pageCount = (int)max(1, (int)ceil($totalRows / $pageSize));
            if ($page > $pageCount) {
                $page = $pageCount;
            }
        } else {
            $page = 1;
        }

        if ($paginationEnabled) {
            $offset = ($page - 1) * $pageSize;
            $rows = $this->repo->fetchMediaListPage($filters, $pageSize, $offset);
        } else {
            if (!empty($filters)) {
                $rows = $this->repo->fetchMediaListFiltered($filters);
            } else {
                $rows = $this->repo->fetchMediaList();
            }
        }

        $targetDate = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
        $targetOrg  = isset($_GET['org'])  ? trim((string)$_GET['org'])  : '';
        if ($targetOrg === '') {
            $targetOrg = isset($_GET['org_name']) ? trim((string)$_GET['org_name']) : '';
        }

        // Basic validation: expect YYYY-MM-DD for date; empty strings are treated as nulls
        if ($targetDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            $targetDate = '';
        }

        $counter = $paginationEnabled ? ($offset + 1) : 1;
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
            $sourceRelpath = (string)($row['source_relpath'] ?? '');
            $checksumSha256 = isset($row['checksum_sha256']) ? (string)$row['checksum_sha256'] : '';
            $mediaSummary = self::mediaInfoSummary(isset($row['media_info']) ? (string)$row['media_info'] : null);

            $servedFile = self::servedFileName($checksumSha256 !== '' ? $checksumSha256 : null, $sourceRelpath, $file);
            $dir = ($typeRaw === 'audio' || $typeRaw === 'video') ? ('/' . $typeRaw) : '';
            $url = ($dir && $servedFile) ? $dir . '/' . rawurlencode($servedFile) : '';

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
                'mediaSummary' => $mediaSummary,
                'sourceRelpath' => $sourceRelpath,
                'checksumSha256' => $checksumSha256,
            ];
        }

        $start = 0;
        $end = 0;
        if ($totalRows > 0) {
            if ($paginationEnabled) {
                $start = (($page - 1) * $pageSize) + 1;
                $end = min($totalRows, $page * $pageSize);
            } else {
                $start = 1;
                $end = $totalRows;
            }
        }

        $query = $_GET;
        unset($query['page']);

        return $this->view->render('media/list.php', [
            'rows'       => $viewRows,
            'targetDate' => $targetDate !== '' ? $targetDate : null,
            'targetOrg'  => $targetOrg  !== '' ? $targetOrg  : null,
            'pagination' => [
                'enabled' => $paginationEnabled,
                'total' => $totalRows,
                'threshold' => $threshold,
                'page' => $page,
                'pageSize' => $pageSize,
                'pageCount' => $pageCount,
                'start' => $start,
                'end' => $end,
            ],
            'query' => $query,
            'appFlavor' => $appFlavor,
            'searchErrors' => $filterErrors,
            'searchWarnings' => $filterWarnings,
        ]);
    }

    /**
     * Return media list as JSON instead of HTML
     */
    public function listJson(): Response
    {
        $filters = self::getFiltersFromRequest();

        $threshold = self::envInt('MEDIA_LIST_PAGINATION_THRESHOLD', 750);
        $totalRows = $this->repo->countMediaListRows($filters);

        $pageSize = $threshold;
        $page = self::getPageFromRequest();
        $pageCount = 1;
        $paginationEnabled = $totalRows >= $threshold;
        $offset = 0;

        if ($paginationEnabled) {
            $pageCount = (int)max(1, (int)ceil($totalRows / $pageSize));
            if ($page > $pageCount) {
                $page = $pageCount;
            }
        } else {
            $page = 1;
        }

        if ($paginationEnabled) {
            $offset = ($page - 1) * $pageSize;
            $rows = $this->repo->fetchMediaListPage($filters, $pageSize, $offset);
        } else {
            if (!empty($filters)) {
                $rows = $this->repo->fetchMediaListFiltered($filters);
            } else {
                $rows = $this->repo->fetchMediaList();
            }
        }

        $counter = $paginationEnabled ? ($offset + 1) : 1;
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
            $sourceRelpath = (string)($row['source_relpath'] ?? '');
            $checksumSha256 = isset($row['checksum_sha256']) ? (string)$row['checksum_sha256'] : '';
            $mediaSummary = self::mediaInfoSummary(isset($row['media_info']) ? (string)$row['media_info'] : null);

            $servedFile = self::servedFileName($checksumSha256 !== '' ? $checksumSha256 : null, $sourceRelpath, $file);
            $dir = ($typeRaw === 'audio' || $typeRaw === 'video') ? ('/' . $typeRaw) : '';
            $url = ($dir && $servedFile) ? $dir . '/' . rawurlencode($servedFile) : '';

            $entries[] = [
                'id'               => $id,
                'index'            => $counter++,
                'date'             => $date,
                'org_name'         => $orgName,
                'duration'         => $duration,
                'duration_seconds' => $durationSec,
                'song_title'       => $songTitle,
                'file_type'        => $typeRaw,
                'file_name'        => $servedFile,
                'url'              => $url,
                'media_summary'     => $mediaSummary,
            ];
        }

        $start = 0;
        $end = 0;
        if ($totalRows > 0) {
            if ($paginationEnabled) {
                $start = (($page - 1) * $pageSize) + 1;
                $end = min($totalRows, $page * $pageSize);
            } else {
                $start = 1;
                $end = $totalRows;
            }
        }

        $body = json_encode([
            'pagination' => [
                'enabled' => $paginationEnabled,
                'total' => $totalRows,
                'threshold' => $threshold,
                'page' => $page,
                'pageSize' => $pageSize,
                'pageCount' => $pageCount,
                'start' => $start,
                'end' => $end,
            ],
            'entries' => $entries,
        ], JSON_PRETTY_PRINT);
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }
}
