<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require '/var/www/html/rconfig/vendor/autoload.php';

$app = require '/var/www/html/rconfig/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$retentionDays = 90;
$cutoff = now()->subDays($retentionDays);

function firstExisting(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function safeStoragePath(?string $storedPath): ?string
{
    if ($storedPath === null || trim($storedPath) === '') {
        return null;
    }

    $storageRoot = realpath(storage_path());

    if ($storageRoot === false) {
        return null;
    }

    $candidates = [];

    if (str_starts_with($storedPath, '/')) {
        $candidates[] = $storedPath;
    } else {
        $candidates[] = storage_path($storedPath);
        $candidates[] = storage_path('app/' . ltrim($storedPath, '/'));
        $candidates[] = storage_path('app/public/' . ltrim($storedPath, '/'));
    }

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);

        if (
            $real !== false &&
            is_file($real) &&
            str_starts_with($real, $storageRoot . DIRECTORY_SEPARATOR)
        ) {
            return $real;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Validate configs table
|--------------------------------------------------------------------------
*/

if (!Schema::hasTable('configs')) {
    fwrite(STDERR, "ERROR: The configs table does not exist.\n");
    exit(1);
}

$columns = Schema::getColumnListing('configs');

$idColumn = firstExisting($columns, [
    'id',
    'config_id'
]);

$dateColumn = firstExisting($columns, [
    'created_at',
    'config_date',
    'downloaded_at',
    'updated_at'
]);

$deviceColumn = firstExisting($columns, [
    'device_id',
    'deviceId',
    'devices_id'
]);

$commandColumn = firstExisting($columns, [
    'command',
    'command_id',
    'commandId',
    'commands_id'
]);

$pathColumn = firstExisting($columns, [
    'config_path',
    'file_path',
    'config_location',
    'config_filename',
    'filename',
    'path'
]);

$required = [
    'ID' => $idColumn,
    'date' => $dateColumn,
    'device' => $deviceColumn,
    'command' => $commandColumn,
    'path' => $pathColumn,
];

foreach ($required as $description => $column) {
    if ($column === null) {
        fwrite(
            STDERR,
            "ERROR: Could not identify the {$description} column in configs.\n" .
            "Available columns: " . implode(', ', $columns) . "\n"
        );
        exit(1);
    }
}

/*
|--------------------------------------------------------------------------
| Build retention candidates
|--------------------------------------------------------------------------
*/

$groupColumns = [
    $deviceColumn,
    $commandColumn,
];

$query = DB::table('configs')
    ->where($dateColumn, '<', $cutoff)
    ->orderBy($dateColumn)
    ->orderBy($idColumn);

$candidates = $query->get();

/*
|--------------------------------------------------------------------------
| Protect newest config for every device + command
|--------------------------------------------------------------------------
*/

$protectedIds = [];

$groups = DB::table('configs')
    ->select($groupColumns)
    ->distinct()
    ->get();

foreach ($groups as $group) {
    $latestQuery = DB::table('configs');

    foreach ($groupColumns as $groupColumn) {
        $value = $group->{$groupColumn};

        if ($value === null) {
            $latestQuery->whereNull($groupColumn);
        } else {
            $latestQuery->where($groupColumn, $value);
        }
    }

    $latest = $latestQuery
        ->orderByDesc($dateColumn)
        ->orderByDesc($idColumn)
        ->first();

    if ($latest !== null) {
        $protectedIds[(string) $latest->{$idColumn}] = true;
    }
}

/*
|--------------------------------------------------------------------------
| Protection rules
|--------------------------------------------------------------------------
|
| An old configuration can only be deleted when:
|
| 1. It is older than the retention period.
| 2. latest_version is NOT 1.
| 3. It is NOT the newest record for device + command.
|
*/

$hasLatestVersion = in_array('latest_version', $columns, true);

$deletable = $candidates->filter(function ($row) use (
    $protectedIds,
    $idColumn,
    $hasLatestVersion
) {
    if ($hasLatestVersion && (int) $row->latest_version === 1) {
        return false;
    }

    if (isset($protectedIds[(string) $row->{$idColumn}])) {
        return false;
    }

    return true;
})->values();

$deletableIds = $deletable
    ->pluck($idColumn)
    ->map(fn ($id) => (int) $id)
    ->all();

/*
|--------------------------------------------------------------------------
| Determine which physical files may safely be deleted
|--------------------------------------------------------------------------
|
| Multiple config DB records can reference the same physical file.
|
| Therefore the physical file is deleted ONLY if every DB reference to
| that config_location is also scheduled for deletion.
|
*/

$files = [];
$missingOrUnknownFiles = [];
$sharedReferencedFiles = [];

foreach ($deletable as $row) {
    $storedPath = $row->{$pathColumn} ?? null;

    $safePath = safeStoragePath(
        is_string($storedPath) ? $storedPath : null
    );

    if ($safePath !== null) {
        $stillReferenced = DB::table('configs')
            ->where($pathColumn, $storedPath)
            ->whereNotIn($idColumn, $deletableIds)
            ->exists();

        if (!$stillReferenced) {
            $files[$safePath] = true;
        } else {
            $sharedReferencedFiles[$safePath] = true;
        }
    } elseif (!empty($storedPath)) {
        $missingOrUnknownFiles[] = [
            'id' => $row->{$idColumn},
            'path' => $storedPath,
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Report
|--------------------------------------------------------------------------
*/

echo "rConfig configuration retention\n";
echo "================================\n";
echo "Mode: " . ($apply ? 'APPLY' : 'DRY RUN') . "\n";
echo "Retention: {$retentionDays} days\n";
echo "Cut-off: {$cutoff->toDateTimeString()}\n";
echo "Date column: {$dateColumn}\n";
echo "Grouping: " . implode(', ', $groupColumns) . "\n";
echo "Path column: {$pathColumn}\n";
echo "Latest-version protection: " .
    ($hasLatestVersion ? 'enabled' : 'unavailable') . "\n";
echo "\n";

echo "Old records found: {$candidates->count()}\n";
echo "Protected old records: " .
    ($candidates->count() - $deletable->count()) . "\n";
echo "Database records selected: {$deletable->count()}\n";
echo "Verified files selected: " . count($files) . "\n";
echo "Files retained due to surviving DB references: " .
    count($sharedReferencedFiles) . "\n";
echo "Unknown/missing file paths: " .
    count($missingOrUnknownFiles) . "\n";

if ($deletable->isNotEmpty()) {
    echo "\nRecords selected:\n";
    echo "-----------------\n";

    foreach ($deletable->take(100) as $row) {
        $path = (string) ($row->{$pathColumn} ?? '');

        echo sprintf(
            "ID=%s DATE=%s DEVICE=%s COMMAND=%s LATEST=%s PATH=%s\n",
            $row->{$idColumn},
            $row->{$dateColumn},
            $row->{$deviceColumn},
            $row->{$commandColumn},
            $hasLatestVersion
                ? (string) $row->latest_version
                : 'N/A',
            $path
        );
    }

    if ($deletable->count() > 100) {
        echo "\nOnly the first 100 records are displayed.\n";
    }
}

if (!$apply) {
    echo "\nDRY RUN ONLY: No database records or files were deleted.\n";
    exit(0);
}

if ($deletable->isEmpty()) {
    echo "\nNothing to delete.\n";
    exit(0);
}

DB::transaction(function () use ($deletableIds, $idColumn): void {
    if (Schema::hasTable('config_changes')) {
        $changeColumns = Schema::getColumnListing('config_changes');

        $foreignKey = firstExisting(
            $changeColumns,
            [
                'config_id',
                'configs_id'
            ]
        );

        if ($foreignKey !== null) {
            foreach (array_chunk($deletableIds, 500) as $chunk) {
                DB::table('config_changes')
                    ->whereIn($foreignKey, $chunk)
                    ->delete();
            }
        }
    }

    foreach (array_chunk($deletableIds, 500) as $chunk) {
        DB::table('configs')
            ->whereIn($idColumn, $chunk)
            ->delete();
    }
});

$deletedFiles = 0;
$fileErrors = 0;

foreach (array_keys($files) as $file) {
    if (is_file($file) && unlink($file)) {
        $deletedFiles++;
    } else {
        $fileErrors++;

        fwrite(
            STDERR,
            "WARNING: Could not delete {$file}\n"
        );
    }
}

echo "\nRetention completed.\n";
echo "Database records deleted: " .
    count($deletableIds) . "\n";
echo "Files deleted: {$deletedFiles}\n";
echo "Files retained due to surviving references: " .
    count($sharedReferencedFiles) . "\n";
echo "File deletion errors: {$fileErrors}\n";
