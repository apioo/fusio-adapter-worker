<?php
/*
 * Fusio is an open source API management platform which helps to create innovative API solutions.
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
use Fusio\Engine\ConfigurableInterface;
use Fusio\Engine\ContextInterface;
use Fusio\Engine\Exception\ConfigurationException;
use Fusio\Engine\Form\BuilderInterface;
use Fusio\Engine\Form\ElementFactoryInterface;
use Fusio\Engine\ParametersInterface;
use Fusio\Engine\RequestInterface;
use Fusio\Engine\Worker\ExecuteBuilderInterface;
use PSX\Http\Environment\HttpResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * WorkerPHPLocal
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @link    https://www.fusio-project.org
 */
class WorkerPHPLocal extends ActionAbstract implements LifecycleInterface, ConfigurableInterface
{
    private ExecuteBuilderInterface $executeBuilder;
    private string $basePath;

    public function __construct(RuntimeInterface $runtime, ExecuteBuilderInterface $executeBuilder, #[Autowire(param: 'psx_path_cache')] string $basePath)
    {
        parent::__construct($runtime);

        $this->executeBuilder = $executeBuilder;
        $this->basePath = $basePath;
    }

    public function getName(): string
    {
        return 'Worker-PHP-Local';
    }

    public function handle(RequestInterface $request, ParametersInterface $configuration, ContextInterface $context): HttpResponseInterface
    {
        $action = $context->getAction()?->getName();
        if ($action === null || $action === '') {
            throw new \RuntimeException('No action name available');
        }

        $actionFile = $this->getActionFile($action);

        // it could be that the file is not longer available since we store the source code in the cache. I.e. if we
        // update a docker container the cache files are not transferred to the new container, because of this we check
        // here the file and write it again to the cache
        if (!is_file($actionFile)) {
            $this->onCreate($action, $configuration);
        }

        $handler = require $actionFile;
        if (!is_callable($handler)) {
            throw new \RuntimeException('Provided action does not return a callable');
        }

        $execute = $this->executeBuilder->build($request, $context);

        return call_user_func_array($handler, [
            $execute->getRequest(),
            $execute->getContext(),
            $this->connector,
            $this->response,
            $this->dispatcher,
            $this->logger
        ]);
    }

    public function configure(BuilderInterface $builder, ElementFactoryInterface $elementFactory): void
    {
        $builder->add($elementFactory->newTextArea('code', 'Code', 'php', 'The PHP code of this action'));
    }

    public function onCreate(string $name, ParametersInterface $config): void
    {
        $file = $this->getActionFile($name);
        $code = $config->get('code') ?? throw new ConfigurationException('No code provided');

        file_put_contents($file, $code);
    }

    public function onUpdate(string $name, ParametersInterface $config): void
    {
        $file = $this->getActionFile($name);
        $code = $config->get('code') ?? throw new ConfigurationException('No code provided');

        file_put_contents($file, $code);
    }

    public function onDelete(string $name, ParametersInterface $config): void
    {
        $file = $this->getActionFile($name);

        if (is_file($file)) {
            unlink($file);
        }
    }

    private function getActionFile(string $name): string
    {
        if (empty($this->basePath)) {
            throw new \RuntimeException('No base path provided');
        }

        return $this->basePath . '/php_local_' . substr(md5($name), 0, 8) . '.php';
    }
}
