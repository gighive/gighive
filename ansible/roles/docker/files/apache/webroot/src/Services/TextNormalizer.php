<?php declare(strict_types=1);
namespace Production\Api\Services;

use Normalizer;

/**
 * Centralized UTF-8 validation and text normalization policy.
 *
 * Exposes four operations for distinct jobs:
 *
 *   normalizeForStorage()      — display-preserving persistence value
 *   canonicalizeForComparison() — stable identity key for dedupe/matching
 *   slugifyForFilename()        — filesystem-safe slug (never used as identity)
 *   assertValidUtf8()           — hard reject of non-UTF-8 input
 *
 * See docs/refactor_database_utf8_enforcement.md for the full policy table.
 */
final class TextNormalizer
{
    /**
     * Normalize text for display-preserving storage.
     *
     * - Converts invalid byte sequences to valid UTF-8 (cp1252 fallback, then strip)
     * - Unicode NFC normalization
     * - Normalizes line endings to \n
     * - Trims leading/trailing whitespace
     * - Collapses repeated internal whitespace (spaces/tabs) to a single space
     */
    public function normalizeForStorage(string $s): string
    {
        $s = $this->toValidUtf8($s);
        $normalized = Normalizer::normalize($s, Normalizer::NFC);
        if (is_string($normalized)) {
            $s = $normalized;
        }
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = trim($s);
        $s = (string)preg_replace('/[ \t]+/', ' ', $s);
        return $s;
    }

    /**
     * Produce a stable canonical comparison key for deduplication and matching.
     *
     * Resolved policy (see docs/refactor_database_utf8_enforcement.md):
     *   - NFC + trim + collapse whitespace (via normalizeForStorage)
     *   - Lowercase via mb_strtolower
     *   - Strip all apostrophe variants: U+0027 ' U+2019 ' U+2018 ' U+0060 ` U+2032 ′
     *   - Normalize all dash variants (en dash – U+2013, em dash — U+2014) to ASCII hyphen
     *   - Transliterate accent-bearing characters to ASCII (é → e, etc.)
     *   - Collapse any whitespace remaining after punctuation transforms
     */
    public function canonicalizeForComparison(string $s): string
    {
        $s = $this->normalizeForStorage($s);
        $s = mb_strtolower($s, 'UTF-8');
        // Strip all apostrophe/quote variants
        $s = str_replace(["\u{2019}", "\u{2018}", "\u{0060}", "\u{2032}", "'"], '', $s);
        // Normalize en dash and em dash to ASCII hyphen
        $s = str_replace(["\u{2013}", "\u{2014}"], '-', $s);
        // Transliterate accent-bearing characters to ASCII
        $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        if (is_string($transliterated)) {
            $s = $transliterated;
        }
        // Collapse any whitespace remaining after punctuation transforms
        $s = trim((string)preg_replace('/\s+/', ' ', $s));
        return $s;
    }

    /**
     * Produce a filesystem-safe slug for filename construction only.
     *
     * Never use this output as a business identity or dedupe key.
     * Starts from the canonical comparison form, then restricts to [a-z0-9-].
     */
    public function slugifyForFilename(string $s): string
    {
        $s = $this->canonicalizeForComparison($s);
        $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        return $s !== '' ? $s : 'item';
    }

    /**
     * Assert that a string is valid UTF-8, throw \InvalidArgumentException if not.
     *
     * Use this at ingestion boundaries where silent repair is not acceptable.
     *
     * @throws \InvalidArgumentException
     */
    public function assertValidUtf8(string $s, string $fieldName = 'field'): void
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            throw new \InvalidArgumentException(
                sprintf('Invalid UTF-8 encoding in %s', $fieldName)
            );
        }
    }

    /**
     * Convert any string to valid UTF-8, replacing invalid byte sequences.
     *
     * Strategy:
     *   1. If already valid UTF-8, return as-is.
     *   2. Attempt cp1252 (Windows-1252) conversion — covers the most common
     *      case of smart quotes and dashes stored in legacy latin1 columns.
     *   3. Fall back to stripping invalid bytes via a UTF-8→UTF-8 conversion.
     */
    private function toValidUtf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $converted = @mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
        return (string)mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
}
