<?php

use Services\FsinShopParser\Filter;
use Services\FsinShopParser\FsinShopParser;

require_once __DIR__ . '/../bootstrap.php';
$filter = (new Filter())->setShopExternalId($argv[1] ?? null);

$container = App::getContainerInstance();
/** @var FsinShopParser $service */
$service = $container->get(FsinShopParser::class);

$service->parse($filter);
