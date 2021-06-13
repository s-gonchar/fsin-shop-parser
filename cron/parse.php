<?php

use Services\FsinShopParser;

require_once __DIR__ . '/../bootstrap.php';

$container = App::getContainerInstence();
/** @var FsinShopParser $service */
$service = $container->get(FsinShopParser::class);

$service->parse();
