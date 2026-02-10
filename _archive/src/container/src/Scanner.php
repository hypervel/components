<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Hyperf\Di\Annotation\AspectCollector;
use Hyperf\Di\Annotation\AspectLoader;
use Hyperf\Di\Annotation\Scanner as HyperfScanner;
use Hypervel\Config\ProviderConfig;

class Scanner extends HyperfScanner
{
    /**
     * Load aspects to AspectCollector by configuration files and ConfigProvider.
     */
    protected function loadAspects(int $lastCacheModified): void
    {
        $configDir = $this->scanConfig->getConfigDir();
        if (! $configDir) {
            return;
        }

        $aspectsPath = $configDir . '/autoload/aspects.php';
        $basePath = $configDir . '/config.php';
        $aspects = file_exists($aspectsPath) ? include $aspectsPath : [];
        $baseConfig = file_exists($basePath) ? include $basePath : [];
        $providerConfig = [];
        if (class_exists(ProviderConfig::class)) {
            $providerConfig = ProviderConfig::load();
        }
        if (! isset($aspects) || ! is_array($aspects)) {
            $aspects = [];
        }
        if (! isset($baseConfig['aspects']) || ! is_array($baseConfig['aspects'])) {
            $baseConfig['aspects'] = [];
        }
        if (! isset($providerConfig['aspects']) || ! is_array($providerConfig['aspects'])) {
            $providerConfig['aspects'] = [];
        }
        $aspects = array_merge($providerConfig['aspects'], $baseConfig['aspects'], $aspects);

        [$removed, $changed] = $this->getChangedAspects($aspects, $lastCacheModified);
        // When the aspect removed from config, it should be removed from AspectCollector.
        foreach ($removed as $aspect) {
            AspectCollector::clear($aspect);
        }

        foreach ($aspects as $key => $value) {
            if (is_numeric($key)) {
                $aspect = $value;
                $priority = null;
            } else {
                $aspect = $key;
                $priority = (int) $value;
            }

            if (! in_array($aspect, $changed)) {
                continue;
            }

            [$instanceClasses, $instanceAnnotations, $instancePriority] = AspectLoader::load($aspect);

            $classes = $instanceClasses ?: [];
            // Annotations
            $annotations = $instanceAnnotations ?: [];
            // Priority
            $priority = $priority ?: ($instancePriority ?? null);
            // Save the metadata to AspectCollector
            AspectCollector::setAround($aspect, $classes, $annotations, $priority);
        }
    }
}
