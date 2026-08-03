<?php

namespace ByJG\Gluo\Laravel\Console;

use ByJG\Gluo\Laravel\Connector\Connector;
use ByJG\Gluo\Laravel\GluoServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Drops the Gluo recipe into the host application: the configuration file plus
 * the starter files of the active connectors.
 *
 * Nothing here knows about any particular connector — each one declares its own
 * files through {@see Connector::publishables()}.
 */
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'gluo:install
                            {--connector=* : Install only these connectors, by name (default: every active one)}
                            {--force : Overwrite files that already exist}';

    /** @var string */
    protected $description = 'Publish the Gluo configuration and the starter files of the active connectors';

    public function handle(Filesystem $files): int
    {
        $force = (bool)$this->option('force');

        $this->publishConfig($files, $force);

        /** @var string[] $only */
        $only = (array)$this->option('connector');
        $connectors = $this->selectedConnectors($only);

        if ($connectors === []) {
            $this->components->warn(
                'No connectors are active. Install a component — for example `composer require byjg/swagger-test`.'
            );

            return self::SUCCESS;
        }

        $notes = [];

        foreach ($connectors as $connectorClass) {
            $connector = new $connectorClass($this->laravel);

            foreach ($connector->publishables() as $source => $target) {
                $this->publishFile($files, $source, $target, $force);
            }

            foreach ($connector->postInstallNotes() as $note) {
                $notes[] = $note;
            }
        }

        $this->printNextSteps($notes);

        return self::SUCCESS;
    }

    /**
     * Active connectors, optionally narrowed to the names given on the command
     * line. A name that is not active is reported rather than ignored — a
     * silent no-op would look like a successful install.
     *
     * @param string[] $only
     * @return array<int, class-string<Connector>>
     */
    protected function selectedConnectors(array $only): array
    {
        /** @var array<int, class-string<Connector>> $active */
        $active = $this->laravel->make(GluoServiceProvider::ACTIVE_CONNECTORS);

        if ($only === []) {
            return $active;
        }

        $byName = [];
        foreach ($active as $connectorClass) {
            $byName[$connectorClass::name()] = $connectorClass;
        }

        $selected = [];
        foreach ($only as $name) {
            if (!isset($byName[$name])) {
                $this->components->warn("Connector '$name' is not active; skipping it.");
                continue;
            }

            $selected[] = $byName[$name];
        }

        return $selected;
    }

    protected function publishConfig(Filesystem $files, bool $force): void
    {
        $target = config_path('gluo.php');

        if ($files->exists($target) && !$force) {
            $this->components->warn('Skipped config/gluo.php (already exists, use --force to overwrite)');

            return;
        }

        $this->callSilent('vendor:publish', [
            '--tag' => GluoServiceProvider::CONFIG_TAG,
            '--force' => true,
        ]);

        $this->components->info('Published config/gluo.php');
    }

    protected function publishFile(Filesystem $files, string $source, string $target, bool $force): void
    {
        $label = $this->relativePath($target);

        if ($files->exists($target) && !$force) {
            $this->components->warn("Skipped $label (already exists, use --force to overwrite)");

            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->put($target, $files->get($source));

        $this->components->info("Published $label");
    }

    /**
     * @param string[] $notes
     */
    protected function printNextSteps(array $notes): void
    {
        if ($notes === []) {
            return;
        }

        $this->newLine();
        $this->line('Next steps:');

        $step = 1;
        foreach ($notes as $note) {
            $this->line("  $step. $note");
            $step++;
        }
    }

    protected function relativePath(string $path): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
