<?php
/**
 * Async sockets
 *
 * @copyright Copyright (c) 2015, Efimov Evgenij <edefimov.it@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Demo;

use AsyncSockets\Event\Event;
use AsyncSockets\Event\EventType;
use AsyncSockets\Event\IoEvent;
use AsyncSockets\Event\SocketExceptionEvent;
use AsyncSockets\RequestExecutor\RequestExecutorInterface;
use AsyncSockets\Socket\AsyncSocketFactory;
use AsyncSockets\Socket\SocketInterface;

/**
 * Class RequestExecutorClient
 */
class RequestExecutorClient
{
    /**
     * Main
     *
     * @return void
     */
    public function main()
    {
        $factory = new AsyncSocketFactory();

        $client        = $factory->createSocket(AsyncSocketFactory::SOCKET_CLIENT);
        $anotherClient = $factory->createSocket(AsyncSocketFactory::SOCKET_CLIENT);

        $executor = $factory->createRequestExecutor();
        $this->registerPackagistSocket($executor, $client, 60, 0.001, 2);

        $executor->addSocket($anotherClient, RequestExecutorInterface::OPERATION_WRITE, [
            RequestExecutorInterface::META_ADDRESS => 'tls://github.com:443',
            RequestExecutorInterface::META_USER_CONTEXT => [
                'data' => "GET / HTTP/1.1\nHost: github.com\n\n",
            ]
        ]);

        $executor->addHandler([
            EventType::DISCONNECTED => [$this, 'onGitHubDisconnect'],
            EventType::CONNECTED    => [$this, 'onGitHubConnected'],
        ], $anotherClient);

        $executor->addHandler([
            EventType::CONNECTED => function () {
                echo "Some socket connected\n";
            },
            EventType::DISCONNECTED => function () {
                echo "Some socket disconnected\n";
            },
            EventType::INITIALIZE => [$this, 'onInitialize'],
            EventType::WRITE      => [$this, 'onWrite'],
            EventType::READ       => [$this, 'onRead'],
            EventType::EXCEPTION  => [$this, 'onException'],
            EventType::TIMEOUT    => [$this, 'onTimeout'],
        ]);

        $executor->execute();
    }

    /**
     * Socket initialize event
     *
     * @param Event $event Event object
     *
     * @return void
     */
    public function onInitialize(Event $event)
    {
        $context = $event->getContext();
        $socket  = $event->getSocket();

        $context['customArgument'] = md5(spl_object_hash($socket));
        $event->getExecutor()->setSocketMetaData($socket, RequestExecutorInterface::META_USER_CONTEXT, $context);
    }

    /**
     * Write event
     *
     * @param IoEvent $event Event object
     *
     * @return void
     */
    public function onWrite(IoEvent $event)
    {
        $context = $event->getContext();
        $socket  = $event->getSocket();

        $socket->write($context['data']);
        $event->nextIsRead();
    }

    /**
     * Read event
     *
     * @param IoEvent $event Event object
     *
     * @return void
     */
    public function onRead(IoEvent $event)
    {
        $context = $event->getContext();
        $socket  = $event->getSocket();

        $context['response'] = $socket->read();

        echo $context['response'];

        $event->getExecutor()->setSocketMetaData($socket, RequestExecutorInterface::META_USER_CONTEXT, $context);
        $event->nextOperationNotRequired();
    }

    /**
     * Disconnect event
     *
     * @param Event $event Event object
     *
     * @return void
     */
    public function onPackagistDisconnect(Event $event)
    {
        echo "Packagist socket has disconnected\n";

        $context  = $event->getContext();
        $socket   = $event->getSocket();
        $executor = $event->getExecutor();
        $meta     = $executor->getSocketMetaData($socket);

        $isTryingOneMoreTime = isset($context[ 'attempts' ]) &&
            $context[ 'attempts' ] - 1 > 0 &&
            $meta[ RequestExecutorInterface::META_REQUEST_COMPLETE ];
        if ($isTryingOneMoreTime) {
            echo "Trying to get data one more time\n";

            $context['attempts'] -= 1;

            // automatically try one more time
            $executor->removeSocket($socket);
            $this->registerPackagistSocket($executor, $socket, 30, 30, 1);
        }
    }

    /**
     * Disconnect event
     *
     * @param Event $event Event object
     *
     * @return void
     */
    public function onGitHubDisconnect(Event $event)
    {
        echo "GitHub socket has disconnected\n";
    }

    /**
     * Disconnect event
     *
     * @param Event $event Event object
     *
     * @return void
     */
    public function onPackagistConnected(Event $event)
    {
        echo "Connected to Packagist\n";
    }

    /**
     * Disconnect event
     *
     * @param Event $event Event object
     *
     * @return void
     */
    public function onGitHubConnected(Event $event)
    {
        echo "Connected to GitHub\n";
    }

    /**
     * Exception event
     *
     * @param SocketExceptionEvent $event Event object
     *
     * @return void
     */
    public function onException(SocketExceptionEvent $event)
    {
        echo 'Exception during processing ' . $event->getOriginalEvent()->getType() . ': ' .
            $event->getException()->getMessage() . "\n";
    }

    /**
     * Timeout event
     *
     * @param Event $event Event object
     *
     * @return void
     */
    public function onTimeout(Event $event)
    {
        echo "Timeout happened on some socket\n";
    }

    /**
     * Register packagist socket in request executor
     *
     * @param RequestExecutorInterface $executor          Executor
     * @param SocketInterface          $client            Client
     * @param int                      $connectionTimeout Connection timeout
     * @param double                   $ioTimeout         Read/Write timeout
     * @param int                      $attempts          Attempt count
     *
     * @return void
     */
    private function registerPackagistSocket(
        RequestExecutorInterface $executor,
        SocketInterface $client,
        $connectionTimeout,
        $ioTimeout,
        $attempts
    ) {
        $executor->addSocket(
            $client,
            RequestExecutorInterface::OPERATION_WRITE,
            [ RequestExecutorInterface::META_ADDRESS => 'tls://packagist.org:443',
              RequestExecutorInterface::META_USER_CONTEXT => [
                  'data'     => "GET / HTTP/1.1\nHost: packagist.org\n\n",
                  'attempts' => $attempts
              ],

                RequestExecutorInterface::META_CONNECTION_TIMEOUT => $connectionTimeout,
                RequestExecutorInterface::META_IO_TIMEOUT         => $ioTimeout
            ]
        );

        $executor->addHandler([
            EventType::DISCONNECTED => [$this, 'onPackagistDisconnect'],
            EventType::CONNECTED    => [$this, 'onPackagistConnected'],
        ], $client);
    }
}