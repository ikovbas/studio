<?php

namespace Studio\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Illuminate\Support\Collection;
use Studio\Config\Config;
use Studio\Config\FileStorage;
use Symfony\Component\Finder\Finder;

class StudioPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        // ...
    }

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => 'dumpAutoload',
        ];
    }

    public function dumpAutoload(Event $event)
    {
        $path = $event->getComposer()->getPackage()->getTargetDir();
        $studioFile = "{$path}studio.json";

        $config = $this->getConfig($studioFile);
        if ($config->hasPackages()) {
            $packages = $config->getPackages();
            //$this->autoloadFrom($packages);
            $this->mergeAutoloaderFiles($packages);
        }
    }

    /**
     * Instantiate and return the config object.
     *
     * @param string $file
     * @return Config
     */
    protected function getConfig($file)
    {
        return new Config(new FileStorage($file));
    }

    protected function autoloadFrom(array $directories)
    {
        $finder = new Finder();

        // Find all Composer autoloader files in the supervised packages' directories
        // so that we can include and setup all of their dependencies.
        $autoloaders = $finder->in($directories)
                              ->files()
                              ->name('autoload.php')
                              ->depth('<= 2')
                              ->followLinks();

        $includes = [];
        foreach ($autoloaders as $file) {
            $includes[] = "require_once __DIR__ . '/../$file';";
        }

        $this->appendIncludes($includes);
    }

    protected function appendIncludes(array $includes)
    {
        $code = '// @generated by Composer Studio (https://github.com/franzliedke/studio)' . "\n";
        $code .= "\n" . implode("\n", $includes) . "\n";

        $autoloadFile = 'vendor/autoload.php';
        $contents = file_get_contents($autoloadFile);

        $contents = str_replace(
            'return ComposerAutoloader',
            "$code\nreturn ComposerAutoloader",
            $contents
        );

        file_put_contents($autoloadFile, $contents);
    }

    protected function mergeAutoloaderFiles(array $directories)
    {
        // TODO: Handle "files"
        foreach (['classmap', 'namespaces', 'psr4'] as $type) {
            $projectFile = "vendor/composer/autoload_$type.php";
            $projectAutoloads = file_exists($projectFile) ? require $projectFile : [];

            $toMerge = $this->getAutoloadersForType($directories, $type)
                ->reduce('array_merge', []);

            $toMerge = array_diff_key($toMerge, $projectAutoloads);

            $this->mergeToEnd($projectFile, $toMerge);
        }
    }

    /**
     * @param array $directories
     * @param string $type
     * @return Collection
     */
    protected function getAutoloadersForType(array $directories, $type)
    {
        return (new Collection($directories))->map(function ($directory) use ($type) {
            return "$directory/vendor/composer/autoload_$type.php";
        })->filter('file_exists')->map(function ($file) {
            return require $file;
        });
    }

    protected function mergeToEnd($autoloadFile, array $newRules)
    {
        $contents = preg_replace_callback('/\),\s\);/', function () use ($newRules) {
            $start = "),\n\n    // @generated by Composer Studio\n\n";
            $end = "\n);\n";

            $lines = array_map(function ($value, $key) {
                return '    ' . var_export($key, true) . ' => ' . var_export($value, true) . ',';
            }, $newRules, array_keys($newRules));

            return $start . implode("\n", $lines) . $end;
        }, file_get_contents($autoloadFile));

        file_put_contents($autoloadFile, $contents);
    }
}
