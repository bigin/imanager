<?php

declare(strict_types=1);

namespace Imanager;

use Imanager\Exception\ConfigException;

/**
 * Immutable runtime configuration.
 *
 * Construct via `Config::default()` and override individual values with
 * `merge([...])`, or build from scratch with `Config::fromArray([...])`.
 *
 * Both factory paths fail fast on unknown keys and on type mismatches,
 * so a config typo cannot silently take effect.
 *
 * @phpstan-type ThumbSize array{width: int, height: int}
 * @phpstan-type ConfigArray array{
 *     debug?: bool,
 *     dataPath?: string,
 *     databasePath?: string,
 *     maxFieldNameLength?: int,
 *     maxItemNameLength?: int,
 *     maxItemsPerPage?: int,
 *     backupCategories?: bool,
 *     backupFields?: bool,
 *     backupItems?: bool,
 *     minBackupTimePeriodDays?: int,
 *     filterByCategories?: string,
 *     filterByFields?: string,
 *     filterByItems?: string,
 *     chmodDir?: int,
 *     chmodFile?: int,
 *     pageNumbersUrlSegment?: string,
 *     systemDateFormat?: string,
 *     thumbSize?: ThumbSize,
 *     tmpFilesCleanPeriodDays?: int,
 * }
 */
final readonly class Config
{
    /**
     * Allowed keys for {@see fromArray()} and {@see merge()}. Anything outside
     * this set is rejected so that misspellings are caught immediately.
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'debug',
        'dataPath',
        'databasePath',
        'maxFieldNameLength',
        'maxItemNameLength',
        'maxItemsPerPage',
        'backupCategories',
        'backupFields',
        'backupItems',
        'minBackupTimePeriodDays',
        'filterByCategories',
        'filterByFields',
        'filterByItems',
        'chmodDir',
        'chmodFile',
        'pageNumbersUrlSegment',
        'systemDateFormat',
        'thumbSize',
        'tmpFilesCleanPeriodDays',
    ];

    /**
     * @param ThumbSize $thumbSize
     */
    public function __construct(
        public bool $debug,
        public string $dataPath,
        public string $databasePath,
        public int $maxFieldNameLength,
        public int $maxItemNameLength,
        public int $maxItemsPerPage,
        public bool $backupCategories,
        public bool $backupFields,
        public bool $backupItems,
        public int $minBackupTimePeriodDays,
        public string $filterByCategories,
        public string $filterByFields,
        public string $filterByItems,
        public int $chmodDir,
        public int $chmodFile,
        public string $pageNumbersUrlSegment,
        public string $systemDateFormat,
        public array $thumbSize,
        public int $tmpFilesCleanPeriodDays,
    ) {
        $this->validate();
    }

    public static function default(): self
    {
        $cwd = (string) getcwd();
        $dataPath = $cwd . '/data';

        return new self(
            debug: false,
            dataPath: $dataPath,
            databasePath: $dataPath . '/imanager.db',
            maxFieldNameLength: 30,
            maxItemNameLength: 255,
            maxItemsPerPage: 10,
            backupCategories: false,
            backupFields: false,
            backupItems: false,
            minBackupTimePeriodDays: 2,
            filterByCategories: 'position',
            filterByFields: 'position',
            filterByItems: 'position',
            chmodDir: 0o755,
            chmodFile: 0o644,
            pageNumbersUrlSegment: 'page',
            systemDateFormat: 'Y-m-d H:i:s',
            thumbSize: ['width' => 150, 'height' => 0],
            tmpFilesCleanPeriodDays: 1,
        );
    }

    /**
     * Build a Config from a partial array, filling unspecified keys with defaults.
     *
     * @param ConfigArray $data
     *
     * @throws ConfigException When `$data` contains unknown keys or wrong types.
     */
    public static function fromArray(array $data): self
    {
        return self::default()->merge($data);
    }

    /**
     * Return a new Config with the given overrides applied.
     *
     * @param ConfigArray $overrides
     *
     * @throws ConfigException When `$overrides` contains unknown keys or wrong types.
     */
    public function merge(array $overrides): self
    {
        if ($overrides === []) {
            return $this;
        }

        $unknown = array_values(array_diff(array_keys($overrides), self::ALLOWED_KEYS));
        if ($unknown !== []) {
            throw ConfigException::unknownKeys($unknown);
        }

        return new self(
            debug: self::pickBool($overrides, 'debug', $this->debug),
            dataPath: self::pickString($overrides, 'dataPath', $this->dataPath),
            databasePath: self::pickString($overrides, 'databasePath', $this->databasePath),
            maxFieldNameLength: self::pickInt($overrides, 'maxFieldNameLength', $this->maxFieldNameLength),
            maxItemNameLength: self::pickInt($overrides, 'maxItemNameLength', $this->maxItemNameLength),
            maxItemsPerPage: self::pickInt($overrides, 'maxItemsPerPage', $this->maxItemsPerPage),
            backupCategories: self::pickBool($overrides, 'backupCategories', $this->backupCategories),
            backupFields: self::pickBool($overrides, 'backupFields', $this->backupFields),
            backupItems: self::pickBool($overrides, 'backupItems', $this->backupItems),
            minBackupTimePeriodDays: self::pickInt($overrides, 'minBackupTimePeriodDays', $this->minBackupTimePeriodDays),
            filterByCategories: self::pickString($overrides, 'filterByCategories', $this->filterByCategories),
            filterByFields: self::pickString($overrides, 'filterByFields', $this->filterByFields),
            filterByItems: self::pickString($overrides, 'filterByItems', $this->filterByItems),
            chmodDir: self::pickInt($overrides, 'chmodDir', $this->chmodDir),
            chmodFile: self::pickInt($overrides, 'chmodFile', $this->chmodFile),
            pageNumbersUrlSegment: self::pickString($overrides, 'pageNumbersUrlSegment', $this->pageNumbersUrlSegment),
            systemDateFormat: self::pickString($overrides, 'systemDateFormat', $this->systemDateFormat),
            thumbSize: self::pickThumbSize($overrides, $this->thumbSize),
            tmpFilesCleanPeriodDays: self::pickInt($overrides, 'tmpFilesCleanPeriodDays', $this->tmpFilesCleanPeriodDays),
        );
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function pickBool(array $a, string $key, bool $default): bool
    {
        if (! \array_key_exists($key, $a)) {
            return $default;
        }
        $v = $a[$key];
        if (! \is_bool($v)) {
            throw ConfigException::invalidType($key, 'bool', get_debug_type($v));
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function pickInt(array $a, string $key, int $default): int
    {
        if (! \array_key_exists($key, $a)) {
            return $default;
        }
        $v = $a[$key];
        if (! \is_int($v)) {
            throw ConfigException::invalidType($key, 'int', get_debug_type($v));
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function pickString(array $a, string $key, string $default): string
    {
        if (! \array_key_exists($key, $a)) {
            return $default;
        }
        $v = $a[$key];
        if (! \is_string($v)) {
            throw ConfigException::invalidType($key, 'string', get_debug_type($v));
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $a
     * @param ThumbSize            $default
     *
     * @return ThumbSize
     */
    private static function pickThumbSize(array $a, array $default): array
    {
        if (! \array_key_exists('thumbSize', $a)) {
            return $default;
        }
        $v = $a['thumbSize'];
        if (! \is_array($v) || ! isset($v['width'], $v['height'])
            || ! \is_int($v['width']) || ! \is_int($v['height'])) {
            throw ConfigException::invalidType(
                'thumbSize',
                'array{width: int, height: int}',
                get_debug_type($v),
            );
        }
        return ['width' => $v['width'], 'height' => $v['height']];
    }

    private function validate(): void
    {
        if ($this->dataPath === '') {
            throw ConfigException::invalidValue('dataPath', 'must not be empty');
        }
        if ($this->databasePath === '') {
            throw ConfigException::invalidValue('databasePath', 'must not be empty');
        }
        if ($this->maxFieldNameLength < 1) {
            throw ConfigException::invalidValue('maxFieldNameLength', 'must be >= 1');
        }
        if ($this->maxItemNameLength < 1) {
            throw ConfigException::invalidValue('maxItemNameLength', 'must be >= 1');
        }
        if ($this->maxItemsPerPage < 1) {
            throw ConfigException::invalidValue('maxItemsPerPage', 'must be >= 1');
        }
        if ($this->minBackupTimePeriodDays < 0) {
            throw ConfigException::invalidValue('minBackupTimePeriodDays', 'must be >= 0');
        }
        if ($this->tmpFilesCleanPeriodDays < 0) {
            throw ConfigException::invalidValue('tmpFilesCleanPeriodDays', 'must be >= 0');
        }
        if ($this->thumbSize['width'] < 0) {
            throw ConfigException::invalidValue('thumbSize.width', 'must be >= 0');
        }
        if ($this->thumbSize['height'] < 0) {
            throw ConfigException::invalidValue('thumbSize.height', 'must be >= 0');
        }
    }
}
