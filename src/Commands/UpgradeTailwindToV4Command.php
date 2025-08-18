<?php

namespace Filament\Upgrade\Commands;

use Filament\Support\Commands\Concerns\CanManipulateFiles;
use Filament\Support\Commands\Exceptions\FailureCommandOutput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

#[AsCommand(name: 'filament:upgrade-tailwind-to-v4')]
class UpgradeTailwindToV4Command extends Command
{
    use CanManipulateFiles;

    protected $description = 'Upgrade custom Filament themes to Tailwind CSS v4';

    protected $name = 'filament:upgrade-tailwind-to-v4';

    protected string $pm = 'npm';

    /**
     * @return array<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption(
                name: 'pm',
                mode: InputOption::VALUE_REQUIRED,
                description: 'The package manager to use (npm, yarn)'
            ),
            new InputOption(
                name: 'dry-run',
                shortcut: 'D',
                mode: InputOption::VALUE_NONE,
                description: 'Preview changes without executing them',
            ),
        ];
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->configurePackageManager();

        $this->components->info('Upgrading custom Filament themes to Tailwind CSS v4...');

        $themeCssFiles = glob(resource_path('css/filament/*/theme.css')) ?: [];

        if (empty($themeCssFiles)) {
            $this->components->warn('No custom Filament theme files were found at resources/css/filament/*/theme.css');
        }

        foreach ($themeCssFiles as $cssFile) {
            $this->upgradeThemeCss($cssFile, $isDryRun);
        }

        // Before running Tailwind upgrade tool, remove Filament preset reference from per-theme tailwind.config.js files
        $themeConfigFiles = glob(resource_path('css/filament/*/tailwind.config.js')) ?: [];
        foreach ($themeConfigFiles as $configFile) {
            $this->sanitizeThemeTailwindConfig($configFile, $isDryRun);
        }

        // Also process the root Tailwind config file if present
        $rootConfigFile = base_path('tailwind.config.js');
        if (File::exists($rootConfigFile)) {
            // Sanitize root config as well: remove Filament preset and vendor Blade content path
            $this->sanitizeThemeTailwindConfig($rootConfigFile, $isDryRun);
        }

        if (! $isDryRun) {
            if (! $this->components->confirm('The Tailwind CSS v4 upgrade tool is experimental and will make changes to your project. Please make sure you have committed your work. Do you want to continue?', default: true)) {
                $this->components->info('Tailwind upgrade cancelled. You can run the tool later by executing the command again or running the upgrade tool manually.');

                return self::SUCCESS;
            }

            $this->clearPublicFilamentAssets();

            // Clear Laravel caches before running the Tailwind upgrade
            $this->components->info('Clearing application caches (optimize:clear)...');

            try {
                $this->call('optimize:clear');
            } catch (Throwable $exception) {
                $this->components->warn('Unable to run optimize:clear automatically. You can run it manually if needed.');
            }

            $this->runTailwindUpgradeTool();
        } else {
            $this->components->info('Dry run complete. Tailwind upgrade tool was not executed.');
        }

        $this->newLine();
        $this->components->warn('Tailwind CSS v4 defines configuration in CSS. The per-theme tailwind.config.js file is no longer used. Move any customizations from that file into your CSS using the @theme and other Tailwind v4 directives.');

        if (! $isDryRun) {
            $this->newLine();
            $this->components->info('Publishing Filament assets...');

            try {
                $this->call('filament:assets');
            } catch (Throwable $exception) {
                $this->components->warn('Unable to run filament:assets automatically. You can run it manually if needed.');
            }
        }

        $this->components->info('Upgrade finished.');

        return self::SUCCESS;
    }

    protected function configurePackageManager(): void
    {
        $this->pm = $this->option('pm') ?? 'npm';

        exec("{$this->pm} -v", $pmVersion, $pmVersionExistCode);

        if ($pmVersionExistCode !== 0) {
            $this->error('Node.js is not installed. Please install before continuing.');

            throw new FailureCommandOutput;
        }

        $this->info("Using {$this->pm} v{$pmVersion[0]}");
    }

    protected function upgradeThemeCss(string $cssFile, bool $isDryRun = false): void
    {
        $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $cssFile);
        $this->components->task("Updating {$relative}", function () use ($cssFile, $isDryRun) {
            $contents = File::get($cssFile);

            $importLine = "@import '../../../../vendor/filament/filament/resources/css/theme.css';";

            $updated = $contents;

            // Normalize line endings
            $updated = str_replace(["\r\n", "\r"], "\n", $updated);

            // Ensure the import line is present (keep it as-is if already there)
            if (! str_contains($updated, $importLine)) {
                // Insert at the top with a trailing blank line
                $updated = $importLine . "\n\n" . ltrim($updated);
            }

            // Do not alter @config or any @source lines. The Tailwind upgrade tool will handle them.

            if ($updated !== $contents) {
                if ($isDryRun) {
                    return true;
                }

                File::put($cssFile, $updated);
            }

            return true;
        });
    }

    protected function removeFilamentPresetFromConfig(string $configFile, bool $isDryRun = false): void
    {
        $relative = $this->relativePath($configFile);
        $this->components->task("Preparing {$relative} for Tailwind upgrade", function () use ($configFile, $isDryRun) {
            $contents = File::get($configFile);

            $original = $contents;

            // Remove the preset import/require line for both ESM and CommonJS, and for both filament/filament and filament/support variants
            $patterns = [
                // ESM import
                "/^\s*import\s+preset\s+from\s+['\"][^'\"]*vendor\/filament\/(?:filament|support)\/tailwind\\.config\\.preset['\"];?\s*$/m",
                // CommonJS require
                "/^\s*(?:const|var|let)\s+preset\s*=\s*require\(\s*['\"][^'\"]*vendor\/filament\/(?:filament|support)\/tailwind\\.config\\.preset['\"]\s*\)\s*;?\s*$/m",
            ];

            foreach ($patterns as $pattern) {
                $contents = preg_replace($pattern, '', $contents) ?? $contents;
            }

            // Remove presets: [preset], allowing for trailing commas and various whitespace
            $contents = preg_replace(
                "/^\s*presets\s*:\s*\[\s*preset\s*]\s*,?\s*$/m",
                '',
                $contents,
            ) ?? $contents;

            if ($contents !== $original) {
                if ($isDryRun) {
                    return true;
                }

                File::put($configFile, $contents);
            }

            return true;
        });
    }

    protected function sanitizeThemeTailwindConfig(string $configFile, bool $isDryRun = false): void
    {
        // Remove Filament preset first (existing sanitization)
        $this->removeFilamentPresetFromConfig($configFile, $isDryRun);

        // Remove './vendor/filament/**/*.blade.php' from the content array lines
        $relative = $this->relativePath($configFile);
        $this->components->task("Removing vendor Blade content path from {$relative}", function () use ($configFile, $isDryRun) {
            $contents = File::get($configFile);
            $original = $contents;

            // Remove a line that contains exactly the vendor blade path item, with optional trailing comma
            $contents = preg_replace(
                "/^\s*['\"]\.\/vendor\/filament\/\*\*\/\*\.blade\.php['\"]\s*,?\s*$/m",
                '',
                $contents,
            ) ?? $contents;

            // Also handle inline arrays, removing the entry and any adjacent comma cleanly
            $inlinePatterns = [
                // , './vendor/filament/**/*.blade.php'
                "/,\s*['\"]\.\/vendor\/filament\/\*\*\/\*\.blade\.php['\"]/",
                // './vendor/filament/**/*.blade.php',
                "/['\"]\.\/vendor\/filament\/\*\*\/\*\.blade\.php['\"]\s*,/",
            ];
            foreach ($inlinePatterns as $p) {
                $contents = preg_replace($p, '', $contents) ?? $contents;
            }

            if ($contents !== $original) {
                if ($isDryRun) {
                    return true;
                }

                File::put($configFile, $contents);
            }

            return true;
        });
    }

    protected function clearPublicFilamentAssets(): void
    {
        $paths = [
            public_path('css/filament'),
            public_path('js/filament'),
        ];

        foreach ($paths as $path) {
            $relative = $this->relativePath($path);
            $this->components->task("Clearing {$relative}", function () use ($path) {
                if (! File::exists($path)) {
                    return true;
                }

                try {
                    File::deleteDirectory($path);
                } catch (Throwable $exception) {
                    // Best-effort fallback cleanup
                    foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $item) {
                        if (is_dir($item)) {
                            @rmdir($item);
                        } else {
                            @unlink($item);
                        }
                    }
                    @rmdir($path);
                }

                return true;
            });
        }
    }

    protected function runTailwindUpgradeTool(): void
    {
        $command = match ($this->pm) {
            'yarn' => 'yarn dlx @tailwindcss/upgrade --force',
            default => 'npx @tailwindcss/upgrade --force',
        };

        $this->newLine();
        $this->components->info('Running Tailwind CSS upgrade tool:');
        $this->line("  {$command}");
        $this->newLine();

        // Stream command output directly to the console so the user can see progress
        if (function_exists('passthru')) {
            passthru($command, $exitCode);
            if ((int) $exitCode !== 0) {
                $this->error('Tailwind upgrade tool failed with exit code ' . (int) $exitCode . '. You may need to run the command manually.');
            }
        } else {
            // Fallback to exec without streaming
            exec($command, $output, $exitCode);
            foreach ($output as $line) {
                $this->line($line);
            }
            if ((int) $exitCode !== 0) {
                $this->error('Tailwind upgrade tool failed with exit code ' . (int) $exitCode . '. You may need to run the command manually.');
            }
        }
    }

    protected function relativePath(string $path): string
    {
        return str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
    }
}
