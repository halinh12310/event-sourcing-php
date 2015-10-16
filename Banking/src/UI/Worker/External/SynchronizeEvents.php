<?php

namespace Madkom\ES\Banking\UI\Worker\External;

use Madkom\EventStore\Client\Application\Api\EventStore;
use Madkom\EventStore\Client\Domain\Socket\Data\StreamEventAppeared;
use Madkom\EventStore\Client\Domain\Socket\Data\SubscribeToStream;
use Madkom\EventStore\Client\Domain\Socket\Message\Credentials;
use Madkom\EventStore\Client\Domain\Socket\Message\MessageType;
use Madkom\EventStore\Client\Domain\Socket\Message\SocketMessage;
use Madkom\EventStore\Client\Infrastructure\InMemoryLogger;
use Madkom\EventStore\Client\Infrastructure\ReactStream;
use React\EventLoop\Factory;
use React\SocketClient\Connector;
use React\Stream\Stream;

/**
 * Class SynchronizeEvents
 * @package Madkom\ES\Banking\Command
 * @author  Dariusz Gafka <d.gafka@madkom.pl>
 */
class SynchronizeEvents
{

    /**
     * Runs synchronization with eventstore
     */
    public function run()
    {
        preg_match('#nameserver ([^\s]*)$#',file_get_contents('/etc/resolv.conf'), $matches);

        if(!array_key_exists(1, $matches)) {
            throw new \Exception("Can't find DNS server");
        }
        $dnsServerIP = $matches[1];

        $loop = Factory::create();
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $dns  = $dnsResolverFactory->create($dnsServerIP, $loop);
        $dns->resolve('eventstore')->then(function($ip) use ($loop, $dns){

            $connector = new Connector($loop, $dns);
            $resolvedConnection = $connector->create($ip, 1113);

            $resolvedConnection->then(function(Stream $stream){
                $eventStore = new EventStore(new ReactStream($stream), new InMemoryLogger());

                $eventStore->addAction(MessageType::STREAM_EVENT_APPEARED, function(SocketMessage $message){
                    /** @var StreamEventAppeared $streamEventAppeared */
                    $streamEventAppeared = $message->getData();
                    $eventRecord = $streamEventAppeared->getEvent()->getEvent();

                    if(!($eventRecord->getEventStreamId() === '$stats-0.0.0.0:2113')) {
                        $data = json_decode($eventRecord->getData());
                        $aggregateID = $data['uuid'];


                    }
                });

                $eventStore->run();

                $socketData = new SubscribeToStream();
                $socketData->setResolveLinkTos(false);
                $socketData->setEventStreamId('');

                $eventStore->sendMessage(new SocketMessage(
                    new MessageType(MessageType::SUBSCRIBE_TO_STREAM),
                    null,
                    $socketData,
                    new Credentials('admin', 'changeit')
                ));

            });

        });

        $loop->run();
    }

}