<?php

namespace App\Enums;

/**
 * What a disk snapshot measures (plan 37, DM-22, DM-24): the project total,
 * its top-level buckets and the storage detail. A fixed list in code; no
 * path or name is ever read from the database, a client or the disk.
 * Unknown stored values are ignored (tryFrom).
 */
enum DiskTarget: string
{
    case ProjectTotal = 'project_total';

    case Storage = 'storage';
    case Public = 'public';
    case Other = 'other';
    case Vendor = 'vendor';
    case NodeModules = 'node_modules';
    case Git = 'git';

    case StorageLogs = 'storage_logs';
    case StorageAppPublic = 'storage_app_public';
    case StorageAppPrivate = 'storage_app_private';
    case StorageMediaLibrary = 'storage_media_library';
    case StorageFrameworkCache = 'storage_framework_cache';
    case StorageFrameworkViews = 'storage_framework_views';
    case StorageFrameworkSessions = 'storage_framework_sessions';

    public function label(): string
    {
        return __("enums.disk_target.{$this->value}");
    }

    /**
     * Top-level buckets in the fixed order they are walked.
     *
     * @return list<self>
     */
    public static function buckets(): array
    {
        return [self::Storage, self::Public, self::Other, self::Vendor, self::NodeModules, self::Git];
    }

    /**
     * The storage detail rows.
     *
     * @return list<self>
     */
    public static function storageDetails(): array
    {
        return [
            self::StorageLogs,
            self::StorageAppPublic,
            self::StorageAppPrivate,
            self::StorageMediaLibrary,
            self::StorageFrameworkCache,
            self::StorageFrameworkViews,
            self::StorageFrameworkSessions,
        ];
    }

    /**
     * Folder inside storage/ of a detail row (with "/" as separator).
     */
    public function storageFolder(): ?string
    {
        return match ($this) {
            self::StorageLogs => 'logs',
            self::StorageAppPublic => 'app/public',
            self::StorageAppPrivate => 'app/private',
            self::StorageMediaLibrary => 'media-library',
            self::StorageFrameworkCache => 'framework/cache',
            self::StorageFrameworkViews => 'framework/views',
            self::StorageFrameworkSessions => 'framework/sessions',
            default => null,
        };
    }

    /**
     * The exact top-level folder name of a named bucket (anything else is "other").
     */
    public function folderName(): ?string
    {
        return match ($this) {
            self::Storage => 'storage',
            self::Public => 'public',
            self::Vendor => 'vendor',
            self::NodeModules => 'node_modules',
            self::Git => '.git',
            default => null,
        };
    }
}
