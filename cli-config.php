<?php

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\ConsoleRunner;

require_once __DIR__ . "/bootstrap.php";

$container = App::getContainerInstance();
$entityManager = $container->get(EntityManagerInterface::class);
return ConsoleRunner::createHelperSet($entityManager);
