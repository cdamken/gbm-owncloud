<?php
/**
 * Register occ console commands with full DI (the ownCloud 10 pattern — info.xml
 * <commands> instantiates without the container, which our IngestService needs).
 * `$application` is provided by ownCloud's console loader.
 */

$app = new \OCA\GbmNext\Application();
$container = $app->getContainer();

/** @var \Symfony\Component\Console\Application $application */
$application->add($container->query(\OCA\GbmNext\Command\Ingest::class));
$application->add($container->query(\OCA\GbmNext\Command\Analyze::class));
