<?php

namespace App\Commands;

use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\BuildState;
use App\Extensions\Internals\ExtensionRunner;
use Composer\Satis\Console\Application;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

class BuildCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'build
        {config-file? : The path to the satis config file.}
        {--repository-url=* : Only update the repository at given URL(s).}
        {--fresh : Force a rebuild of all packages.}
        {--set-extension=* : Configures the build to use the given extension(s).}
        {--skip-errors : Skip Download or Archive errors }
        {--no-interaction-s3 : Do not ask any interactive question }
    ';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Updates satis repository on S3 bucket';

    /**
     * Execute the console command.
     */
    public function handle(ExtensionRunner $extensionRunner): void
    {
        $config_file = str($this->argument('config-file') ?: str(getcwd())->finish(DIRECTORY_SEPARATOR)->append('satis.json'));

        if (! file_exists($config_file)) {
            $this->error("Config file {$config_file} does not exist");
            exit(1);
        }

        $buildConfig = new BuildState(
            temp_prefix: crc32($config_file),
            config_file_path: $config_file,
            repository_urls: $this->option('repository-url'),
            force_fresh_downloads: $this->option('fresh'),
            skip_errors: $this->option('skip-errors'),
            no_interaction: $this->option('no-interaction-s3')
        );

        $extensionRunner->enableExtensionFromBuildState($this, $buildConfig);

        if ($this->option('set-extension')) {
            $runtime_extensions = collect($this->option('set-extension'));

            if ($runtime_extensions->filter(fn ($name) => ! $name)->count()) {
                $this->error('Extension names cannot be empty');
                exit(1);
            }

            $extensionRunner->enableExtensionFromRunOptions($this, $runtime_extensions);
        }

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_INITIAL_CLEAR_TEMP_DIRECTORY, $buildConfig)) {
            $this->info('Clearing temp directory');
            $this->clearTempDirectory(
                prefix: $buildConfig->getTempPrefix()
            );
        } else {
            $this->info('Skipping clearing temp directory');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_INITIAL_CLEAR_TEMP_DIRECTORY, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_CREATE_TEMP_DIRECTORY, $buildConfig)) {
            $this->info('Creating temp directory');
            $this->createTempDirectory(
                prefix: $buildConfig->getTempPrefix()
            );
        } else {
            $this->info('Skipping creating temp directory');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_CREATE_TEMP_DIRECTORY, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_DOWNLOAD_FROM_S3, $buildConfig)) {
            if (! $buildConfig->isForceFreshDownloads()) {
                $this->info('Downloading from S3');
                [$placeholders, $crc] = $this->downloadFromS3(prefix: $buildConfig->getTempPrefix(), skipErrors: $buildConfig->isSkipErrors());
                $buildConfig->setPlaceholders(
                    placeholders: $placeholders
                );
                $buildConfig->setCrc(
                    crc: $crc
                );
            } else {
                $this->info('Skipping downloading from S3 because --fresh was passed');
            }
        } else {
            $this->info('Skipping downloading from S3');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_DOWNLOAD_FROM_S3, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_BUILD_SATIS_REPOSITORY, $buildConfig)) {
            $this->info('Building satis repository');
            $this->buildSatisRepository(
                config_file: $buildConfig->getConfigFilePath(),
                prefix: $buildConfig->getTempPrefix(),
                repository_url: $buildConfig->getRepositoryUrls(),
                skip_errors: $buildConfig->isSkipErrors(),
                no_interaction: $buildConfig->isNoInteraction()
            );
        } else {
            $this->info('Skipping building satis repository');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_BUILD_SATIS_REPOSITORY, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_UPLOAD_TO_S3, $buildConfig)) {
            $this->info('Uploading to S3');
            $this->uploadToS3(
                prefix: $buildConfig->getTempPrefix(),
                placeholders: $buildConfig->getPlaceholders(),
                crc: $buildConfig->getCrc(),
                skipErrors: $buildConfig->isSkipErrors()
            );
        } else {
            $this->info('Skipping uploading to S3');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_UPLOAD_TO_S3, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_REMOVE_MISSING_FILES_FROM_S3, $buildConfig)) {
            $this->info('Removing missing files from S3');
            $this->removeMissingFilesFromS3(
                prefix: $buildConfig->getTempPrefix()
            );
        } else {
            $this->info('Skipping removing missing files from S3');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_REMOVE_MISSING_FILES_FROM_S3, $buildConfig);

        if ($buildConfig->last_step_executed = $extensionRunner->execute($this, BuildHooks::BEFORE_FINAL_CLEAR_TEMP_DIRECTORY, $buildConfig)) {
            $this->info('Clearing temp directory');
            $this->clearTempDirectory(
                prefix: $buildConfig->getTempPrefix()
            );
        } else {
            $this->info('Skipping clearing temp directory');
        }
        $extensionRunner->execute($this, BuildHooks::AFTER_FINAL_CLEAR_TEMP_DIRECTORY, $buildConfig);
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    /**
     * Clear the temp directory / delete the generated files from the local filesystem
     */
    public function clearTempDirectory(string $prefix): void
    {
        Storage::disk('temp')->deleteDirectory($prefix);
    }

    /**
     * Create the temp directory
     */
    protected function createTempDirectory(string $prefix): void
    {
        Storage::disk('temp')->makeDirectory($prefix);
    }

    /**
     * Build a standalone S3Client from the same config as the 's3' disk.
     * Used instead of reaching into Flysystem internals so CommandPool can
     * issue concurrent requests directly against the AWS SDK.
     */
    protected function getS3Client(): \Aws\S3\S3Client
    {
        $config = config('filesystems.disks.s3');

        return new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => $config['region'],
            'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => $config['use_path_style_endpoint'] ?? false,
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
            'http' => $config['http'] ?? [],
            'retries' => $config['retries'] ?? 3,
        ]);
    }

    /**
     * Download (or make placeholders) the files from S3.
     *
     * zip/tar archives get an instant 0-byte placeholder (unchanged -- the
     * archive builder only checks local file existence, never content).
     * Everything else (checksums, p2 json, packages.json) is downloaded
     * concurrently via Aws\CommandPool instead of one-by-one: with a cold
     * cache this set is ~28k small objects, and a strictly sequential loop
     * with no per-request timeout is what turns a cold build into a 1.5-2h
     * run (or an indefinite hang on a single stalled connection).
     */
    protected function downloadFromS3(string $prefix, bool $skipErrors = false): array
    {
        $placeholders = collect();
        $crc = collect();

        $allFiles = collect(Storage::disk('s3')->allFiles())->map(fn ($file) => str($file));
        $this->info("Found {$allFiles->count()} files in S3");

        [$archiveFiles, $otherFiles] = $allFiles->partition(
            fn (Stringable $s3_path) => in_array($s3_path->afterLast('.'), ['tar', 'zip'])
        );

        $archiveFiles->each(function (Stringable $s3_path) use ($prefix, $placeholders) {
            $temp_path = $s3_path->start('/')->start($prefix);

            $this->line("Creating placeholder {$s3_path} in temp directory", verbosity: OutputInterface::VERBOSITY_VERBOSE);
            $placeholders->push($temp_path->toString());
            Storage::disk('temp')->put($temp_path, '');
        });

        $otherFiles = $otherFiles->values();
        $total = $otherFiles->count();
        if ($total === 0) {
            return [$placeholders, $crc];
        }

        $client = $this->getS3Client();
        $bucket = config('filesystems.disks.s3.bucket');
        $concurrency = (int) env('S3_DOWNLOAD_CONCURRENCY', 20);
        $heartbeat = max(50, (int) ($total / 20));

        $this->info("Downloading {$total} non-archive files from S3 (concurrency: {$concurrency})");

        $done = 0;
        $failed = collect();
        $commands = $otherFiles->map(fn (Stringable $s3_path) => $client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => (string) $s3_path,
        ]));

        (new \Aws\CommandPool($client, $commands->all(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($result, $index) use (&$done, $otherFiles, $prefix, $crc, $total, $heartbeat) {
                $s3_path = $otherFiles[$index];
                $temp_path = $s3_path->start('/')->start($prefix);
                $body = (string) $result['Body'];

                $this->line("Downloading {$s3_path} from S3", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                Storage::disk('temp')->put($temp_path, $body);
                $crc[$temp_path->toString()] = crc32($body);

                $done++;
                if ($done % $heartbeat === 0 || $done === $total) {
                    $this->info("Downloaded {$done}/{$total} files from S3");
                }
            },
            'rejected' => function ($reason, $index) use (&$failed, $otherFiles, $skipErrors) {
                $s3_path = $otherFiles[$index];
                $message = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;

                if (! $skipErrors) {
                    throw new \RuntimeException("Failed downloading {$s3_path} from S3: {$message}");
                }

                $this->warn("Skipping {$s3_path} after download failure: {$message}");
                $failed->push((string) $s3_path);
            },
        ]))->promise()->wait();

        if ($failed->isNotEmpty()) {
            $this->warn("{$failed->count()} file(s) failed to download from S3 and were skipped (--skip-errors).");
        }

        return [$placeholders, $crc];
    }

    /**
     * Generate satis repository
     */
    protected function buildSatisRepository(string $config_file, string $prefix, ?array $repository_url = null, ?bool $skip_errors = null, ?bool $no_interaction = null): void
    {
        $application = new Application();
        $application->setAutoExit(false); // prevent `$application->run` method from exitting the script

        $application->run(
            new ArrayInput(
                [
                    'command' => 'build',
                    'file' => $config_file,
                    'output-dir' => (string) config('filesystems.disks.temp.root')->append($prefix),
                ] + ($repository_url ? ['--repository-url' => $repository_url] : [])
                + ($skip_errors ? ['--skip-errors' => true] : [])
                + ($no_interaction ? ['--no-interaction' => true] : [])
            )
        );
    }

    /**
     * Delete the files from S3 that are missing from the temp directory
     */
    protected function removeMissingFilesFromS3(int $prefix): void
    {
        $all_local_files = collect(Storage::disk('temp')->allFiles($prefix))
            ->map(fn ($file) => str($file))
            ->map(fn (Stringable $temp_path) => $temp_path->after($prefix)->ltrim('/'));

        collect(Storage::disk('s3')->allFiles())
            ->map(fn ($file) => str($file))
            ->diff($all_local_files)
            ->each(function (Stringable $s3_path) {
                $this->line("Deleting {$s3_path} because it is missing", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                Storage::disk('s3')->delete($s3_path);
            });
    }

    /**
     * Upload the generated files to S3.
     *
     * Placeholder files (real content already lives at that key on S3
     * unchanged, or the placeholder is empty and nothing to upload) are
     * handled sequentially -- cheap, local-only checks. Everything that
     * actually needs a PutObject is batched through the same concurrent
     * CommandPool pattern as downloadFromS3, for the same reason: this loop
     * touches every file in the build (tens of thousands on a cold cache).
     */
    protected function uploadToS3(int $prefix, Collection $placeholders, Collection $crc, bool $skipErrors = false): void
    {
        $files = collect(Storage::disk('temp')->allFiles($prefix))->map(fn ($file) => str($file));
        [$placeholder_files, $normal_files] = $files->partition(fn (Stringable $file) => $placeholders->contains($file->toString()));

        $toUpload = collect();

        $placeholder_files->each(function (Stringable $temp_path) use ($prefix, $toUpload) {
            $s3_path = $temp_path->after($prefix)->ltrim('/');

            if (Storage::disk('temp')->size($temp_path) > 0) {
                $toUpload->push([$s3_path, $temp_path]);
            } else {
                $this->line("Skipping {$s3_path} because it is a placeholder", verbosity: OutputInterface::VERBOSITY_VERBOSE);
            }
        });

        $normal_files->each(function (Stringable $temp_path) use ($crc, $prefix, $toUpload) {
            $s3_path = $temp_path->after($prefix)->ltrim('/');

            if ($crc->has($temp_path->toString())) {
                $this->line("Checking {$s3_path} for changes", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                $local_crc = crc32(Storage::disk('temp')->get($temp_path));
                if ($crc[$temp_path->toString()] == $local_crc) {
                    $this->line("Skipping {$s3_path} because it has not changed", verbosity: OutputInterface::VERBOSITY_VERBOSE);

                    return;
                }
            }

            $toUpload->push([$s3_path, $temp_path]);
        });

        $toUpload = $toUpload->values();
        $total = $toUpload->count();
        if ($total === 0) {
            return;
        }

        $client = $this->getS3Client();
        $bucket = config('filesystems.disks.s3.bucket');
        $concurrency = (int) env('S3_UPLOAD_CONCURRENCY', 20);
        $heartbeat = max(50, (int) ($total / 20));

        $this->info("Uploading {$total} files to S3 (concurrency: {$concurrency})");

        $done = 0;
        $failed = collect();
        $commands = $toUpload->map(function (array $pair) use ($client, $bucket) {
            [$s3_path, $temp_path] = $pair;

            return $client->getCommand('PutObject', [
                'Bucket' => $bucket,
                'Key' => (string) $s3_path,
                'Body' => Storage::disk('temp')->readStream($temp_path),
            ]);
        });

        (new \Aws\CommandPool($client, $commands->all(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($result, $index) use (&$done, $toUpload, $total, $heartbeat) {
                [$s3_path] = $toUpload[$index];
                $this->line("Uploading {$s3_path} to S3", verbosity: OutputInterface::VERBOSITY_VERBOSE);

                $done++;
                if ($done % $heartbeat === 0 || $done === $total) {
                    $this->info("Uploaded {$done}/{$total} files to S3");
                }
            },
            'rejected' => function ($reason, $index) use (&$failed, $toUpload, $skipErrors) {
                [$s3_path] = $toUpload[$index];
                $message = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;

                if (! $skipErrors) {
                    throw new \RuntimeException("Failed uploading {$s3_path} to S3: {$message}");
                }

                $this->warn("Skipping {$s3_path} after upload failure: {$message}");
                $failed->push((string) $s3_path);
            },
        ]))->promise()->wait();

        if ($failed->isNotEmpty()) {
            $this->warn("{$failed->count()} file(s) failed to upload to S3 and were skipped (--skip-errors).");
        }
    }
}
