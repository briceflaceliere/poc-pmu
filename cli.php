#!/usr/bin/env php
<?php
// application.php

date_default_timezone_set('Europe/Paris');
define('ROOT', dirname(__FILE__));

require __DIR__.'/vendor/autoload.php';

use Pmu\Command\CrawlerCommand;
use Pmu\Command\TestAlgoCommand;
use Symfony\Component\Console\Application;

$application = new Application('PMU POC', '0.1');
$application->add(new CrawlerCommand(new \Pmu\Crawler\PronosoftCrawler()));
/*$application->add(new TestAlgoCommand(new \Pmu\Algo\CoteAlgo()));
$application->add(new TestAlgoCommand(new \Pmu\Algo\CoteAlgoV2()));
$application->add(new TestAlgoCommand(new \Pmu\Algo\MusiqueAlgo()));
$application->add(new TestAlgoCommand(new \Pmu\Algo\MusiqueV2Algo()));*/
$application->run();
