<?php declare(strict_types=1);
namespace Production\Api\Services;

/**
 * String constants for GIGHIVE_MEDIA_STORAGE_BACKEND values.
 *
 * Shared by MediaStorageService::make() and TusUploadConfig::fromEnv()
 * so backend comparisons are never raw string literals that could silently
 * drift out of sync between the two factory methods.
 */
final class MediaBackend
{
    /** Bind-mounted VM filesystem (VirtualBox, bare-metal). Default. */
    public const LOCAL = 'local';

    /** Azure Blob Storage via Managed Identity (Tranche 2 and beyond). */
    public const AZURE_BLOB = 'azure_blob';

    /**
     * Phase 11 transition only — tries Azure first, falls back to local for
     * assets not yet backfilled. Remove after Phase 11 step 9 is verified.
     */
    public const AZURE_FALLBACK = 'azure_blob_with_local_fallback';

    /** Not instantiable. */
    private function __construct() {}
}
