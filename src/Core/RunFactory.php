<?php

declare(strict_types=1);

/**
 * Copyright (c) 2024 Kai Sassnowski
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/roach-php/roach
 */

namespace RoachPHP\Core;

use Psr\Container\ContainerInterface;
use RoachPHP\Downloader\DownloaderMiddlewareInterface;
use RoachPHP\Downloader\Middleware\DownloaderMiddlewareAdapter;
use RoachPHP\Extensions\ExtensionInterface;
use RoachPHP\ItemPipeline\Processors\ItemProcessorInterface;
use RoachPHP\Spider\Configuration\Overrides;
use RoachPHP\Spider\Middleware\SpiderMiddlewareAdapter;
use RoachPHP\Spider\SpiderInterface;
use RoachPHP\Spider\SpiderMiddlewareInterface;

final class RunFactory
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function fromSpider(SpiderInterface $spider, ?Overrides $overrides = null): Run
    {
        $configuration = $spider->loadConfiguration();

        if (null !== $overrides) {
            $configuration = $configuration->withOverrides($overrides);
            $spider->withConfiguration($configuration);
        }

        return new Run(
            $spider->getInitialRequests(),
            $spider::class,
            $this->buildDownloaderMiddleware($configuration->downloaderMiddleware),
            $this->buildItemPipeline($configuration->itemProcessors),
            $this->buildResponseMiddleware($configuration->spiderMiddleware),
            $this->buildExtensions($configuration->extensions),
            $configuration->concurrency,
            $configuration->requestDelay,
        );
    }

    /**
     * @psalm-param class-string<DownloaderMiddlewareInterface>[] $downloaderMiddleware
     *
     * @return list<DownloaderMiddlewareInterface>
     */
    private function buildDownloaderMiddleware(array $downloaderMiddleware): array
    {
        return \array_map(function (array|string $middleware) {
            return DownloaderMiddlewareAdapter::fromMiddleware($this->buildConfigurable($middleware));
        }, $downloaderMiddleware);
    }

    /**
     * @psalm-param array<class-string<ItemProcessorInterface>> $processors
     *
     * @return list<ItemProcessorInterface>
     */
    private function buildItemPipeline(array $processors): array
    {
        return \array_map([$this, 'buildConfigurable'], $processors);
    }

    /**
     * @psalm-param array<class-string<SpiderMiddlewareInterface>> $handlers
     *
     * @return list<SpiderMiddlewareInterface>
     */
    private function buildResponseMiddleware(array $handlers): array
    {
        return \array_map(function (array|string $handler) {
            return SpiderMiddlewareAdapter::fromMiddleware($this->buildConfigurable($handler));
        }, $handlers);
    }

    /**
     * @param list<class-string<ExtensionInterface>> $extensions
     *
     * @return list<ExtensionInterface>
     */
    private function buildExtensions(array $extensions): array
    {
        return \array_map(function (array|string $extension) {
            return $this->buildConfigurable($extension);
        }, $extensions);
    }

    /**
     * @template T of \RoachPHP\Support\ConfigurableInterface
     *
     * @psalm-param class-string<T>|array{0: class-string<T>, 1: array<string, mixed>} $configurable
     *
     * @return T
     */
    private function buildConfigurable(array|string $configurable): mixed
    {
        if (!\is_array($configurable)) {
            $configurable = [$configurable, []];
        }

        [$class, $options] = $configurable;

        /** @psalm-var T $instance */
        $instance = $this->container->get($class);
        $instance->configure($options);

        return $instance;
    }
}
