<?php

namespace A17\Blast\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use A17\Blast\Traits\Helpers;

class GenerateUIDocs extends Command
{
    use Helpers;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blast:generate-docs {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically generate stories for documenting your Tailwind config';

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        parent::__construct();

        $this->config = [];
        $this->storiesToGenerate = config('blast.auto_documentation', []);
        $this->vendorPath = $this->getVendorPath();
        $this->configPath = config(
            'blast.tailwind_config_path',
            base_path('tailwind.config.js'),
        );
        $this->parsedConfig = $this->vendorPath . '/tmp/tailwind.config.php';
        $this->filesystem = $filesystem;

        if ($this->filesystem->exists($this->configPath)) {
            $this->getConfigData();
        }
    }

    /*
     * Executes the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $force = $this->option('force');

        $this->getConfigData();

        $copied = $this->copyFiles($force);

        if ($copied) {
            $this->info('Generating stories');
            usleep(250000);
            $this->call('blast:generate-stories', ['--ui-docs']);
        }
    }

    private function get($key = null)
    {
        if ($key) {
            return Arr::get($this->config, $key);
        }
    }

    private function getConfigData()
    {
        if (!$this->filesystem->exists($this->configPath)) {
            return 1;
        }

        $this->runProcessInBlast(
            ['node', './src/resolveTailwindConfig.js'],
            false,
            [
                'CONFIGPATH' => $this->configPath,
            ],
        );

        if ($this->filesystem->exists($this->parsedConfig)) {
            $this->config = include $this->parsedConfig;
        }
    }

    /**
     * @return boolean
     */
    private function copyFiles($force = false)
    {
        $pathname = $this->ask(
            'What do you want to name the documentation section?',
            'UI Documentation',
        );

        $localStoriesPath = base_path('resources/views/stories/' . $pathname);
        $localDataPath = base_path('resources/views/stories/data');

        $packageStoriesPath = $this->vendorPath . '/resources/ui-docs/stories';
        $packageDataPath = $this->vendorPath . '/resources/ui-docs/data';

        if (!$force && $this->filesystem->exists($localStoriesPath)) {
            if (
                $this->confirm(
                    $pathname .
                        ' exists. This will overwrite the existing files. Do you wish to continue?',
                )
            ) {
                $this->info('Overwriting UI Docs stories');
            } else {
                $this->error('Aborting');

                return false;
            }
        }

        $this->filesystem->ensureDirectoryExists($localStoriesPath);
        $this->filesystem->ensureDirectoryExists($localDataPath);

        if (
            !is_array($this->storiesToGenerate) ||
            empty($this->storiesToGenerate)
        ) {
            if ($this->filesystem->exists($packageStoriesPath)) {
                $this->filesystem->copyDirectory(
                    $packageStoriesPath,
                    $localStoriesPath,
                );
            }

            // copy data
            if ($this->filesystem->exists($packageDataPath)) {
                $this->filesystem->copyDirectory(
                    $packageDataPath,
                    $localDataPath,
                );
            }
        } else {
            foreach ($this->storiesToGenerate as $name) {
                $filepath =
                    $this->vendorPath .
                    '/resources/ui-docs/stories/' .
                    $name .
                    '.blade.php';

                if ($this->filesystem->exists($filepath)) {
                    if ($this->filesystem->exists($packageStoriesPath)) {
                        $this->info('Copying stories for `' . $name . '`.');

                        // transitions also require the data file
                        if ($name === 'transition') {
                            $dataFilepath = $packageDataPath . '/ui-docs.php';

                            if ($this->filesystem->exists($dataFilepath)) {
                                $this->filesystem->copy(
                                    $dataFilepath,
                                    $localDataPath . '/ui-docs.php',
                                );
                            }
                        }

                        // copy documentation story
                        $this->filesystem->copy(
                            $filepath,
                            $localStoriesPath . '/' . $name . '.blade.php',
                        );
                    }
                } else {
                    $this->error('`' . $name . '` not recognized. Ignoring.');
                }
            }
        }

        return true;
    }
}
