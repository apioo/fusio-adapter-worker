<?php

use Fusio\Adapter\Worker\Action\WorkerJava;
use Fusio\Adapter\Worker\Action\WorkerJavascript;
use Fusio\Adapter\Worker\Action\WorkerPHP;
use Fusio\Adapter\Worker\Action\WorkerPHPLocal;
use Fusio\Adapter\Worker\Action\WorkerPython;
use Fusio\Adapter\Worker\Connection\Worker;
use Fusio\Engine\Adapter\ServiceBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container) {
    $services = ServiceBuilder::build($container);
    $services->set(Worker::class);
    $services->set(WorkerJava::class);
    $services->set(WorkerJavascript::class);
    $services->set(WorkerPHP::class);
    $services->set(WorkerPHPLocal::class);
    $services->set(WorkerPython::class);
};
