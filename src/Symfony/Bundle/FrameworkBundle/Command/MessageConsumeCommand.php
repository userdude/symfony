<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Message\Asynchronous\ConsumedMessage;
use Symfony\Component\Message\MessageBusInterface;
use Symfony\Component\Message\MessageConsumerInterface;

/**
 * @author Samuel Roze <samuel.roze@gmail.com>
 */
class MessageConsumeCommand extends Command
{
    protected static $defaultName = 'message:consume';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDefinition(array(
                new InputArgument('consumer', InputArgument::REQUIRED, 'Name of the consumer'),
                new InputOption('bus', 'b', InputOption::VALUE_REQUIRED, 'Name of the bus to dispatch the messages to', 'message_bus'),
            ))
            ->setDescription('Consume a message')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command consume a message and dispatch it to the message bus.

    %command.full_name% <consumer-service-name>

EOF
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ContainerInterface $container */
        $container = $this->getApplication()->getKernel()->getContainer();

        if (!$container->has($consumerName = $input->getArgument('consumer'))) {
            throw new \RuntimeException(sprintf('Consumer "%s" do not exists', $consumerName));
        } elseif (!($consumer = $container->get($consumerName)) instanceof MessageConsumerInterface) {
            throw new \RuntimeException(sprintf('Consumer "%s" is not a valid message consumer. It should implement the interface "%s"', $consumerName, MessageConsumerInterface::class));
        }

        if (!$container->has($busName = $input->getOption('bus'))) {
            throw new \RuntimeException(sprintf('Bus "%s" do not exists', $busName));
        } elseif (!($messageBus = $container->get($busName)) instanceof MessageBusInterface) {
            throw new \RuntimeException(sprintf('Bus "%s" is not a valid message bus. It should implement the interface "%s"', $busName, MessageBusInterface::class));
        }

        foreach ($consumer->consume() as $message) {
            if (!$message instanceof ConsumedMessage) {
                $message = new ConsumedMessage($message);
            }

            $messageBus->handle($message);
        }
    }
}
