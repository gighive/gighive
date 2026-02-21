<?php declare(strict_types=1);

$rawFlavor = getenv('APP_FLAVOR');
$appFlavor = $rawFlavor !== false ? strtolower(trim((string)$rawFlavor)) : '';

$measurementId = '';
if ($appFlavor === 'defaultcodebase') {
    $raw = getenv('GA4_MEASUREMENT_ID_DEFAULTCODEBASE');
    $measurementId = $raw !== false ? trim((string)$raw) : '';
    if ($measurementId === '') {
        $measurementId = 'G-MX1FQZ3H0W';
    }
} elseif ($appFlavor === 'gighive') {
    $raw = getenv('GA4_MEASUREMENT_ID_GIGHIVE');
    $measurementId = $raw !== false ? trim((string)$raw) : '';
}

if ($measurementId === '') {
    return;
}
?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($measurementId, ENT_QUOTES) ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '<?= htmlspecialchars($measurementId, ENT_QUOTES) ?>');
</script>
