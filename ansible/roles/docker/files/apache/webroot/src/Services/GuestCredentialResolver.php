<?php declare(strict_types=1);

namespace Production\Api\Services;

/**
 * Shared guest credential resolver.
 *
 * Centralises the two-path nonce/token authentication used across all guest
 * API endpoints (gallery, report, stream, delete). Callers own nonce format
 * validation and all HTTP response codes; this class performs only the DB
 * lookups and returns typed results.
 *
 * Modelled on UploadTokenValidator in the same namespace.
 * PHP 8.0+ required (constructor promotion, union return types).
 */
class GuestCredentialResolver
{
    public function __construct(private \PDO $pdo) {}

    /**
     * Resolve a guest nonce using the two-path auth strategy:
     *   1. Nonce → approved anon_upload_attributions → event_upload_tokens
     *   2. hash('sha256', $nonce) → active, unexpired event_upload_tokens
     *
     * Used by guest-gallery.php, guest-report.php, and guest-stream.php.
     *
     * Callers must validate nonce format before calling this method.
     * The helper does not re-validate format — this preserves the caller's
     * ability to distinguish 400 (bad format) from 403 (no matching auth).
     *
     * @param  string                                         $nonce Raw nonce string.
     * @return array{event_id: int, expires_at: string}|false Array on success; false if no match.
     * @throws \PDOException On database error — caller wraps in try/catch and sends 500.
     */
    public function resolveNonceOrToken(string $nonce): array|false
    {
        // Path 1: nonce → approved upload attribution → event token
        $stmt = $this->pdo->prepare(
            'SELECT t.event_id, t.expires_at
             FROM anon_upload_attributions a
             JOIN upload_jobs j ON j.job_id = a.upload_job_id
             JOIN event_upload_tokens t ON t.token_id = a.token_id
             WHERE a.status_nonce = ? AND j.moderation_status = \'approved\''
        );
        $stmt->execute([$nonce]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return ['event_id' => (int)$row['event_id'], 'expires_at' => (string)$row['expires_at']];
        }

        // Path 2: raw token hash → active, unexpired event_upload_tokens
        $tokenHash = hash('sha256', $nonce);
        $stmt = $this->pdo->prepare(
            'SELECT t.event_id, t.expires_at
             FROM event_upload_tokens t
             WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()'
        );
        $stmt->execute([$tokenHash]);
        $tokenRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($tokenRow !== false) {
            return ['event_id' => (int)$tokenRow['event_id'], 'expires_at' => (string)$tokenRow['expires_at']];
        }

        error_log('[GUEST_AUTH] resolveNonceOrToken: no credential found for nonce_prefix=' . substr($nonce, 0, 8));
        return false;
    }

    /**
     * Resolve a guest nonce using the nonce-only path (no raw-token fallback).
     *
     * Used by guest-delete.php. Guests may only delete a video using a nonce
     * tied to an approved upload — a raw viewer token is insufficient.
     *
     * Callers must validate nonce format before calling this method.
     *
     * @param  string   $nonce Raw nonce string.
     * @return int|false event_id on success; false if no matching approved upload found.
     * @throws \PDOException On database error — caller wraps in try/catch and sends 500.
     */
    public function resolveNonceOnly(string $nonce): int|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.event_id
             FROM anon_upload_attributions a
             JOIN upload_jobs j ON j.job_id = a.upload_job_id
             JOIN event_upload_tokens t ON t.token_id = a.token_id
             WHERE a.status_nonce = ? AND j.moderation_status = \'approved\''
        );
        $stmt->execute([$nonce]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return (int)$row['event_id'];
        }

        error_log('[GUEST_AUTH] resolveNonceOnly: no approved upload found for nonce_prefix=' . substr($nonce, 0, 8));
        return false;
    }
}
