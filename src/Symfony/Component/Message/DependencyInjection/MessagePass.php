<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Message\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Message\Handler\ChainHandler;

/**
 * @author Samuel Roze <samuel.roze@gmail.com>
 */
class MessagePass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    private $messageBusService;
    private $messageHandlerResolverService;
    private $handlerTag;

    public function __construct(string $messageBusService = 'message_bus', string $messageHandlerResolverService = 'message.handler_resolver', string $handlerTag = 'message_handler')
    {
        $this->messageBusService = $messageBusService;
        $this->messageHandlerResolverService = $messageHandlerResolverService;
        $this->handlerTag = $handlerTag;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition($this->messageBusService)) {
            return;
        }

        $this->registerHandlers($container);
    }

    private function registerHandlers(ContainerBuilder $container)
    {
        $handlersByMessage = array();

        foreach ($container->findTaggedServiceIds($this->handlerTag, true) as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $handles = isset($tag['handles']) ? $tag['handles'] : $this->guessHandledClass($container, $serviceId);

                if (!class_exists($handles)) {
                    throw new RuntimeException(sprintf('The message class "%s" declared in `__invoke` function of service "%s" does not exist', $handles, $serviceId));
                }

                $priority = isset($tag['priority']) ? $tag['priority'] : 0;
                $handlersByMessage[$handles][$priority][] = new Reference($serviceId);
            }
        }

        foreach ($handlersByMessage as $message => $handlers) {
            krsort($handlersByMessage[$message]);
            $handlersByMessage[$message] = call_user_func_array('array_merge', $handlersByMessage[$message]);
        }

        $definitions = array();
        foreach ($handlersByMessage as $message => $handlers) {
            if (1 === count($handlers)) {
                $handlersByMessage[$message] = current($handlers);
            } else {
                $d = new Definition(ChainHandler::class, array($handlers));
                $d->setPrivate(true);
                $serviceId = hash('sha1', $message);
                $definitions[$serviceId] = $d;
                $handlersByMessage[$message] = new Reference($serviceId);
            }
        }
        $container->addDefinitions($definitions);

        $handlerResolver = $container->getDefinition($this->messageHandlerResolverService);
        $handlerResolver->replaceArgument(0, ServiceLocatorTagPass::register($container, $handlersByMessage));
    }

    private function guessHandledClass(ContainerBuilder $container, string $serviceId): string
    {
        $reflection = new \ReflectionClass($container->getDefinition($serviceId)->getClass());
        try {
            $method = $reflection->getMethod('__invoke');
        } catch (\ReflectionException $e) {
            throw new RuntimeException(sprintf('Service "%s" should have an `__invoke` function', $serviceId));
        }

        $parameters = $method->getParameters();
        if (1 !== count($parameters)) {
            throw new RuntimeException(sprintf('`__invoke` function of service "%s" must have exactly one parameter', $serviceId));
        }

        $parameter = $parameters[0];
        if (null === $parameter->getClass()) {
            throw new RuntimeException(sprintf('The parameter of `__invoke` function of service "%s" must type hint the Message class it handles', $serviceId));
        }

        return $parameter->getClass()->getName();
    }
}
