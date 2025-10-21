<?php

namespace App\Extensions\Internals;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[BuildExtension(name: 'CheckSum Fixer', key: 'checksum-fixer')]
class CheckSumFixerExtension
{
    #[BuildHook(BuildHooks::BEFORE_UPLOAD_TO_S3)]
    public function saveChecksums(BuildState $buildState, Command $command): void
    {
        collect(Storage::disk('temp')->allFiles($buildState->getTempPrefix()))
            ->filter(fn (string $file) => str($file)->endsWith(['.tar', '.zip']))
            ->map(fn (string $file) => [
                'fs_path' => (string) str($file),
                'real_path' => (string) config('filesystems.disks.temp.root')->append($file),
                'relative_path' => (string) str($file)->after($buildState->getTempPrefix())->after('/'),
                'fs_path_checksum' => (string) str($file)
                    ->after($buildState->getTempPrefix())
                    ->start('.checksums')->start('/')->start($buildState->getTempPrefix())
                    ->append('.sha1'),
            ])
            ->each(function (array $file) use ($command, $buildState) {
                if (Storage::disk('temp')->size($file['fs_path']) === 0) {
                    if (!$buildState->isFixChecksums()) {
                        $command->line("File {$file['relative_path']} is a placeholder - skipping checksum.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                    } else {
                        $remotePath = str($file['relative_path'])->start('dist/')->toString();

                        if (Storage::disk('s3')->exists($remotePath)) {
                            try {
                                $stream = Storage::disk('s3')->readStream($remotePath);
                                $context = hash_init('sha1');
                                hash_update_stream($context, $stream);
                                $checksum = hash_final($context);
                                fclose($stream);

                                Storage::disk('temp')->put($file['fs_path_checksum'], $checksum);

                                $command->line("Checksum fetched from S3 for {$file['relative_path']} → {$checksum}", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                            } catch (Throwable $e) {
                                $command->line("Error reading {$remotePath} from S3: {$e->getMessage()}", verbosity: OutputInterface::VERBOSITY_DEBUG);
                            }
                        } else {
                            $command->line("S3 file {$remotePath} not found — skipping.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                        }
                    }

                    return;
                }

                $command->line("Generating checksum for {$file['relative_path']}.", verbosity: OutputInterface::VERBOSITY_DEBUG);
                try {
                    Storage::disk('temp')->put($file['fs_path_checksum'], sha1_file($file['real_path']));
                } catch (Throwable $e) {
                    $command->line("Failed generating checksum for {$file['relative_path']}: {$e->getMessage()}", verbosity: OutputInterface::VERBOSITY_DEBUG);
                }
            });
    }

    #[BuildHook(BuildHooks::BEFORE_UPLOAD_TO_S3)]
    public function fixPackagesFiles(PluginConfig $config, BuildState $buildState, Command $command, JsonFileModifier $modifier): void
    {
        $url_host = $buildState->getConfig()
            ->get('archive', collect())
            ->get('prefix-url', $buildState->getConfig()->get('homepage'));

        $modifier->modifyVersions(function (Collection $version, string $package_name, Stringable $json_path) use ($url_host, $buildState, $command) {
            if ($version->has('dist') === false) {
                $command->line("Version {$package_name}:{$version['version']} does not have a dist - skipping.", verbosity: OutputInterface::VERBOSITY_DEBUG);

                return;
            }

            $dist = $version->get('dist', collect());

            if ($dist->has('shasum') === false || $dist->has('url') === false) {
                $command->line("Version {$package_name}:{$version['version']} does not have a shasum or url - skipping.", verbosity: OutputInterface::VERBOSITY_DEBUG);

                return;
            }

            $url = $dist->get('url');
            $path = str($url)->replace($url_host, '')->ltrim('/')->toString();

            if (str($path)->startsWith(['http://', 'https://'])) {
                $command->line("Version {$package_name}:{$version['version']} has a remote url - skipping.", verbosity: OutputInterface::VERBOSITY_DEBUG);

                return;
            }

            $fs_checksum_file = str($path)->start('/')->start('.checksums')->start('/')->start($buildState->getTempPrefix())->append('.sha1');

            if (Storage::disk('temp')->exists($fs_checksum_file) === false) {
                $command->line("Checksum file {$fs_checksum_file} does not exist - skipping.", verbosity: OutputInterface::VERBOSITY_DEBUG);

                return;
            }

            $checksum = Storage::disk('temp')->get($fs_checksum_file);

            if ($dist['shasum'] === $checksum) {
                $command->line("Version {$package_name}:{$version['version']} has a local url and the checksum is correct - skipping.", verbosity: OutputInterface::VERBOSITY_DEBUG);

                return;
            }

            $dist['shasum'] = $checksum;
            $command->line("Version {$package_name}:{$version['version']} has a local url - fixing checksum.", verbosity: OutputInterface::VERBOSITY_VERBOSE);
        });
    }
}
