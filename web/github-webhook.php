<?php

require_once __DIR__.'../vendor/autoload.php';

use Composer\Satis\Service\Webhook;
use Composer\Satis\Console\Application;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();

$application = new Application();

$webhook = new Webhook($application);
$webhook->processRequest($request);

