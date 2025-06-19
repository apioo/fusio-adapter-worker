<?php
/*
 * Fusio - Self-Hosted API Management for Builders.
 * For the current version and information visit <https://www.fusio-project.org/>
 *
 * Copyright 2015-2024 Christoph Kappestein <christoph.kappestein@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Fusio\Adapter\Worker\Action;

use Fusio\Engine\Action\LifecycleInterface;
use Fusio\Engine\Action\RuntimeInterface;
use Fusio\Engine\ActionAbstract;
use Fusio\Engine\Connection\PingableInterface;
use Fusio\Engine\ContextInterface;
use Fusio\Engine\Exception\ConfigurationException;
use Fusio\Engine\Form\BuilderInterface;
use Fusio\Engine\Form\ElementFactoryInterface;
use Fusio\Engine\ParametersInterface;
use Fusio\Engine\RequestInterface;
use Fusio\Engine\Worker\ExecuteBuilderInterface;
use Fusio\Worker\Client;
use Fusio\Worker\Update;

/**
 * WorkerAbstract
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @link    https://www.fusio-project.org
 */
abstract class WorkerAbstract extends ActionAbstract implements LifecycleInterface, PingableInterface
{
    private ExecuteBuilderInterface $executeBuilder;

    public function __construct(RuntimeInterface $runtime, ExecuteBuilderInterface $executeBuilder)
    {
        parent::__construct($runtime);

        $this->executeBuilder = $executeBuilder;
    }

    public function handle(RequestInterface $request, ParametersInterface $configuration, ContextInterface $context): mixed
    {
        $client = $this->connector->getConnection($configuration->get('worker'));
        if (!$client instanceof Client) {
            throw new ConfigurationException('Provided an invalid worker connection');
        }

        $response = $client->execute($context->getAction()?->getName() ?? '', $this->executeBuilder->build($request, $context));

        $events = $response->getEvents();
        if ($events !== null) {
            foreach ($events as $event) {
                $eventName = $event->getEventName();
                $data = $event->getData();
                if ($eventName !== null && $data !== null) {
                    $this->dispatcher->dispatch($eventName, $data);
                }
            }
        }


        $logs = $response->getLogs();
        if ($logs !== null) {
            foreach ($logs as $log) {
                $level = $log->getLevel();
                $message = $log->getMessage();
                if ($level !== null && $message !== null) {
                    $this->logger->log($level, $message);
                }
            }
        }

        $httpResponse = $response->getResponse();

        return $this->response->build(
            $httpResponse?->getStatusCode() ?? 200,
            $httpResponse?->getHeaders()?->getAll() ?? [],
            $httpResponse?->getBody()
        );
    }

    public function configure(BuilderInterface $builder, ElementFactoryInterface $elementFactory): void
    {
        $builder->add($elementFactory->newConnection('worker', 'Worker', 'The worker connection'));
        $builder->add($elementFactory->newTextArea('code', 'Code', $this->getLanguage(), ''));
    }

    public function onCreate(string $name, ParametersInterface $config): void
    {
        $connection = $this->connector->getConnection($config->get('worker'));
        if (!$connection instanceof Client) {
            return;
        }

        $update = new Update();
        $update->setCode($config->get('code'));

        $connection->put($name, $update);
    }

    public function onUpdate(string $name, ParametersInterface $config): void
    {
        $connection = $this->connector->getConnection($config->get('worker'));
        if (!$connection instanceof Client) {
            return;
        }

        $update = new Update();
        $update->setCode($config->get('code'));

        $connection->put($name, $update);
    }

    public function onDelete(string $name, ParametersInterface $config): void
    {
        $connection = $this->connector->getConnection($config->get('worker'));
        if (!$connection instanceof Client) {
            return;
        }

        $connection->delete($name);
    }

    public function ping(mixed $connection): bool
    {
        if (!$connection instanceof Client) {
            return false;
        }

        $apiVersion = $connection->get()->getApiVersion();
        if ($apiVersion === null) {
            return false;
        }

        return true;
    }

    abstract protected function getLanguage(): string;
}
