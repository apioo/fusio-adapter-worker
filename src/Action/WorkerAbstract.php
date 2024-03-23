<?php
/*
 * Fusio is an open source API management platform which helps to create innovative API solutions.
 * For the current version and information visit <https://www.fusio-project.org/>
 *
 * Copyright 2015-2023 Christoph Kappestein <christoph.kappestein@gmail.com>
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

use Doctrine\DBAL\Connection;
use Fusio\Engine\Action\RuntimeInterface;
use Fusio\Engine\ActionAbstract;
use Fusio\Engine\Connection\LifecycleInterface;
use Fusio\Engine\Connection\PingableInterface;
use Fusio\Engine\ContextInterface;
use Fusio\Engine\Exception\ConfigurationException;
use Fusio\Engine\Form\BuilderInterface;
use Fusio\Engine\Form\ElementFactoryInterface;
use Fusio\Engine\Inflection\ClassName;
use Fusio\Engine\Model\AppInterface;
use Fusio\Engine\Model\UserInterface;
use Fusio\Engine\ParametersInterface;
use Fusio\Engine\Request\HttpRequestContext;
use Fusio\Engine\Request\RequestContextInterface;
use Fusio\Engine\RequestInterface;
use Fusio\Worker\Client;
use Fusio\Worker\Execute;
use Fusio\Worker\ExecuteContext;
use Fusio\Worker\ExecuteContextApp;
use Fusio\Worker\ExecuteContextUser;
use Fusio\Worker\ExecuteRequest;
use Fusio\Worker\ExecuteRequestContext;
use Fusio\Worker\Update;
use PSX\Record\Record;

/**
 * WorkerAbstract
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @link    https://www.fusio-project.org
 */
abstract class WorkerAbstract extends ActionAbstract implements LifecycleInterface, PingableInterface
{
    private Connection $connection;

    public function __construct(RuntimeInterface $runtime, Connection $connection)
    {
        parent::__construct($runtime);

        $this->connection = $connection;
    }

    public function handle(RequestInterface $request, ParametersInterface $configuration, ContextInterface $context): mixed
    {
        $execute = new Execute();
        $execute->setConnections(Record::fromArray($this->getConnections()));
        $execute->setRequest($this->getRequest($request));
        $execute->setContext($this->getContext($context));

        $client = $this->connector->getConnection($configuration->get('worker'));
        if (!$client instanceof Client) {
            throw new ConfigurationException('Provided an invalid worker connection');
        }

        $response = $client->execute($context->getAction()->getName(), $execute);

        $events = $response->getEvents();
        if (!empty($events)) {
            foreach ($events as $event) {
                $this->dispatcher->dispatch($event->getEventName(), $event->getData());
            }
        }

        $logs = $response->getLogs();
        if (!empty($logs)) {
            foreach ($logs as $log) {
                $this->logger->log($log->getLevel(), $log->getMessage());
            }
        }

        return $this->response->build(
            $response->getStatusCode() ?? 200,
                $response->getHeaders()?->getAll() ?? [],
                $response->getBody()
        );
    }

    public function configure(BuilderInterface $builder, ElementFactoryInterface $elementFactory): void
    {
        $builder->add($elementFactory->newConnection('worker', 'Worker', 'The worker connection'));
        $builder->add($elementFactory->newTextArea('code', 'Code', $this->getLanguage(), ''));
    }

    public function onCreate(string $name, ParametersInterface $config, mixed $connection): void
    {
        if (!$connection instanceof Client) {
            return;
        }

        $update = new Update();
        $update->setCode($config->get('code'));

        $connection->put($name, $update);
    }

    public function onUpdate(string $name, ParametersInterface $config, mixed $connection): void
    {
        if (!$connection instanceof Client) {
            return;
        }

        $update = new Update();
        $update->setCode($config->get('code'));

        $connection->put($name, $update);
    }

    public function onDelete(string $name, ParametersInterface $config, mixed $connection): void
    {
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
        if (empty($apiVersion)) {
            return false;
        }

        return true;
    }

    abstract protected function getLanguage(): string;

    private function getConnections(): array
    {
        $connections = [];
        $result = $this->connection->fetchAllAssociative('SELECT name, config FROM fusio_connection WHERE status = 0');
        foreach ($result as $row) {
            $connections[$row['name']] = $row['config'];
        }

        return $connections;
    }

    private function getRequest(RequestInterface $request): ExecuteRequest
    {
        $return = new ExecuteRequest();
        $return->setArguments(Record::fromArray($request->getArguments()));
        $return->setPayload($request->getPayload());
        $return->setContext($this->getRequestContext($request->getContext()));

        return $return;
    }

    private function getRequestContext(RequestContextInterface $requestContext): ExecuteRequestContext
    {
        $return = new ExecuteRequestContext();
        $return->setType(ClassName::serialize($requestContext::class));
        if ($requestContext instanceof HttpRequestContext) {
            $return->setUriFragments($requestContext->getParameters());
            $return->setMethod($requestContext->getRequest()->getMethod());
            $return->setPath($requestContext->getRequest()->getUri()->getPath());
            $return->setQueryParameters(Record::fromArray($requestContext->getRequest()->getUri()->getParameters()));
            $return->setHeaders($this->getRequestHeaders($requestContext->getRequest()));
        }

        return $return;
    }

    private function getRequestHeaders(\PSX\Http\RequestInterface $request): Record
    {
        $headers = new Record();
        foreach ($request->getHeaders() as $key => $values) {
            $headers->put($key, implode(', ', $values));
        }

        return $headers;
    }

    private function getContext(ContextInterface $context): ExecuteContext
    {
        $return = new ExecuteContext();
        $return->setOperationId($context->getOperationId());
        $return->setBaseUrl($context->getBaseUrl());
        $return->setTenantId($context->getTenantId());
        $return->setAction($context->getAction());
        $return->setApp($this->getApp($context->getApp()));
        $return->setUser($this->getUser($context->getUser()));

        return $return;
    }

    private function getApp(AppInterface $app): ExecuteContextApp
    {
        $return = new ExecuteContextApp();
        $return->setAnonymous($app->isAnonymous());
        $return->setId($app->getId());
        $return->setName($app->getName());

        return $return;
    }

    private function getUser(UserInterface $user): ExecuteContextUser
    {
        $return = new ExecuteContextUser();
        $return->setAnonymous($user->isAnonymous());
        $return->setId($user->getId());
        $return->setPlanId($user->getPlanId());
        $return->setName($user->getName());
        $return->setEmail($user->getEmail());
        $return->setPoints($user->getPoints());

        return $return;
    }
}
