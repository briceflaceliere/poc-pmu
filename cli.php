#!/usr/bin/env php
<?php
// application.php

date_default_timezone_set('Europe/Paris');

require __DIR__.'/vendor/autoload.php';

use Pmu\Command\CrawlerCommand;
use Symfony\Component\Console\Application;

$application = new Application('PMU POC', '0.1');
$application->add(new CrawlerCommand());
$application->run();
