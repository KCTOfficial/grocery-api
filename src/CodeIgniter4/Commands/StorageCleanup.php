<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use GroceryRestable\Service\StorageJanitor;
use Throwable;

/**
 * php spark grocery-restable:cleanup                  report (dry run)
 * php spark grocery-restable:cleanup --run            clean orphans + housekeeping
 * php spark grocery-restable:cleanup --run --tasks=orphan_files,trash --min-age=48
 */
class StorageCleanup extends BaseCommand
{
    /** Tasks of --run without --tasks: everything except emptying the whole trash. */
    private const DEFAULT_TASKS = ['orphan_files', 'orphan_buckets', 'stale_index', 'untracked_objects', 'tmp_files', 'empty_folders', 'trash'];

    protected $group = 'Grocery Restable';
    protected $name = 'grocery-restable:cleanup';
    protected $description = 'Finds orphan upload files and bucket folders, and cleans the storage (dry run without --run).';
    protected $usage = 'grocery-restable:cleanup [--run] [--tasks=orphan_files,orphan_buckets,...] [--min-age=24]';
    protected $options = [
        '--run' => 'Clean instead of only reporting',
        '--tasks' => 'Comma separated: orphan_files, orphan_buckets, stale_index, untracked_objects, tmp_files, empty_folders, trash, trash_all',
        '--min-age' => 'Hours a file must be unused before it is an orphan (default: setting orphan_min_age_hours)',
    ];

    public function run(array $params)
    {
        /** @var \GroceryRestable\GroceryRestable $api */
        $api = service('groceryRestable');
        $janitor = $api->janitor();
        $minAge = CLI::getOption('min-age') !== null ? (int) CLI::getOption('min-age') : null;
        $option = CLI::getOption('tasks');
        $tasks = is_string($option) && $option !== '' ? array_map('trim', explode(',', $option)) : self::DEFAULT_TASKS;
        $unknown = array_diff($tasks, StorageJanitor::TASKS);
        if ($unknown !== []) {
            CLI::error('Unknown task(s): ' . implode(', ', $unknown));

            return EXIT_ERROR;
        }

        try {
            $report = $janitor->scan($minAge);
            $rows = [];
            foreach ($report['tasks'] as $task => $summary) {
                $rows[] = [$task, $summary['count'], self::bytes($summary['bytes']), in_array($task, $tasks, true) ? 'yes' : ''];
            }
            CLI::table($rows, ['Task', 'Items', 'Size', 'Selected']);
            foreach ($report['skipped'] as $skipped) {
                CLI::write('Skipped ' . $skipped['directory'] . ': ' . $skipped['reason'], 'yellow');
            }

            if (CLI::getOption('run') === null) {
                CLI::write('Dry run. Add --run to clean. Orphans go to ' . $report['trash_path'] . ' first.', 'light_gray');

                return EXIT_SUCCESS;
            }
            foreach ($janitor->clean($tasks, $minAge) as $task => $count) {
                CLI::write(sprintf('%-18s %d', $task, $count), 'green');
            }
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    private static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < 3) {
            $value /= 1024;
            $i++;
        }

        return round($value, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
