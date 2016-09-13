<?php declare(strict_types=1);

namespace ProophTest\EventStore\Adapter\Predis;

use Prooph\EventStore\Adapter\Predis\PredisEventStoreAdapter;
use Predis\Client;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\NoOpMessageConverter;
use Prooph\EventStore\Adapter\PayloadSerializer\JsonPayloadSerializer;
use Prooph\EventStore\Stream\Stream;
use Prooph\EventStore\Stream\StreamName;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Mock\UsernameChanged;

/**
 * @author Eric Braun <eb@oqq.be>
 */
class PredisEventStoreAdapterTest extends \PHPUnit_Framework_TestCase
{
    /** @var Client */
    protected $client;

    /** @var PredisEventStoreAdapter */
    protected $adapter;

    /**
     * @inheritdoc
     */
    protected function setUp()
    {
        $this->client = new Client(null, ['prefix' => str_replace('\\', ':', __CLASS__)]);

        $this->adapter = new PredisEventStoreAdapter(
            $this->client,
            new FQCNMessageFactory(),
            new NoOpMessageConverter(),
            new JsonPayloadSerializer()
        );
    }

    /**
     * @inheritdoc
     */
    protected function tearDown()
    {
        if (null === $this->client) {
            return;
        }

        $prefix = $this->client->getOptions()->__get('prefix')->getPrefix();
        $prefixLen = strlen($prefix);

        // we have to use array_filter, since keys($prefix . '*') does not work how expected :(
        $keys = array_filter($this->client->keys('*'), function ($value) use ($prefix) {
            return 0 === strpos($value, $prefix);
        });

        if ($keys) {
            $this->client->del(array_map(function ($value) use ($prefixLen) {
                return substr($value, $prefixLen);
            }, $keys));
        }
    }

    /**
     * @test
     */
    public function it_creates_a_stream()
    {
        $testStream = $this->getTestStream();

        $this->adapter->beginTransaction();

        $this->adapter->create($testStream);

        $this->adapter->commit();

        $streamEvents = $this->adapter->loadEvents(new StreamName('Prooph\Model\User'), ['tag' => 'person']);

        $count = 0;
        foreach ($streamEvents as $event) {
            $count++;
        }
        $this->assertEquals(1, $count);

        $testStream->streamEvents()->rewind();

        $testEvent = $testStream->streamEvents()->current();

        $this->assertEquals($testEvent->uuid()->toString(), $event->uuid()->toString());
        $this->assertEquals(
            $testEvent->createdAt()->format('Y-m-d\TH:i:s.uO'),
            $event->createdAt()->format('Y-m-d\TH:i:s.uO')
        );
        $this->assertEquals('ProophTest\EventStore\Mock\UserCreated', $event->messageName());
        $this->assertEquals('contact@prooph.de', $event->payload()['email']);
        $this->assertEquals(1, $event->version());
        $this->assertEquals(['tag' => 'person'], $event->metadata());
    }

    /**
     * @test
     */
    public function it_appends_events_to_a_stream()
    {
        $this->adapter->create($this->getTestStream());

        $streamEvent = UsernameChanged::with(
            ['name' => 'John Doe'],
            2
        );

        $streamEvent = $streamEvent->withAddedMetadata('tag', 'person');

        $this->adapter->appendTo(new StreamName('Prooph\Model\User'), new \ArrayIterator([$streamEvent]));

        $stream = $this->adapter->load(new StreamName('Prooph\Model\User'));

        $this->assertEquals('Prooph\Model\User', $stream->streamName()->toString());

        $count = 0;
        $lastEvent = null;
        foreach ($stream->streamEvents() as $event) {
            $count++;
            $lastEvent = $event;
        }
        $this->assertEquals(2, $count);
        $this->assertInstanceOf(UsernameChanged::class, $lastEvent);
        $messageConverter = new NoOpMessageConverter();

        $streamEventData = $messageConverter->convertToArray($streamEvent);
        $lastEventData = $messageConverter->convertToArray($lastEvent);

        $this->assertEquals($streamEventData, $lastEventData);
    }

    /**
     * @test
     */
    public function it_loads_events_from_min_version_on()
    {
        $this->adapter->create($this->getTestStream());

        $streamEvent1 = UsernameChanged::with(
            ['name' => 'John Doe'],
            2
        );

        $streamEvent1 = $streamEvent1->withAddedMetadata('tag', 'person');

        $streamEvent2 = UsernameChanged::with(
            ['name' => 'Jane Doe'],
            3
        );

        $streamEvent2 = $streamEvent2->withAddedMetadata('tag', 'person');

        $this->adapter->appendTo(
            new StreamName('Prooph\Model\User'),
            new \ArrayIterator([$streamEvent1, $streamEvent2])
        );

        $stream = $this->adapter->load(new StreamName('Prooph\Model\User'), 2);

        $this->assertEquals('Prooph\Model\User', $stream->streamName()->toString());

        $event1 = $stream->streamEvents()->current();
        $stream->streamEvents()->next();
        $event2 = $stream->streamEvents()->current();
        $stream->streamEvents()->next();
        $this->assertFalse($stream->streamEvents()->valid());

        $this->assertEquals('John Doe', $event1->payload()['name']);
        $this->assertEquals('Jane Doe', $event2->payload()['name']);
    }

    /**
     * @test
     */
    public function it_replays()
    {
        $testStream = $this->getTestStream();

        $this->adapter->beginTransaction();

        $this->adapter->create($testStream);

        $this->adapter->commit();

        $streamEvent = UsernameChanged::with(
            ['name' => 'John Doe'],
            2
        );

        $streamEvent = $streamEvent->withAddedMetadata('tag', 'person');

        $this->adapter->appendTo(new StreamName('Prooph\Model\User'), new \ArrayIterator([$streamEvent]));

        $streamEvents = $this->adapter->replay(new StreamName('Prooph\Model\User'), null, ['tag' => 'person']);

        $count = 0;
        foreach ($streamEvents as $event) {
            $count++;
        }
        $this->assertEquals(2, $count);

        $testStream->streamEvents()->rewind();
        $streamEvents->rewind();

        $testEvent = $testStream->streamEvents()->current();
        $event = $streamEvents->current();

        $this->assertEquals($testEvent->uuid()->toString(), $event->uuid()->toString());
        $this->assertEquals(
            $testEvent->createdAt()->format('Y-m-d\TH:i:s.uO'),
            $event->createdAt()->format('Y-m-d\TH:i:s.uO')
        );
        $this->assertEquals('ProophTest\EventStore\Mock\UserCreated', $event->messageName());
        $this->assertEquals('contact@prooph.de', $event->payload()['email']);
        $this->assertEquals(1, $event->version());

        $streamEvents->next();
        $event = $streamEvents->current();

        $this->assertEquals($streamEvent->uuid()->toString(), $event->uuid()->toString());
        $this->assertEquals(
            $streamEvent->createdAt()->format('Y-m-d\TH:i:s.uO'),
            $event->createdAt()->format('Y-m-d\TH:i:s.uO')
        );
        $this->assertEquals('ProophTest\EventStore\Mock\UsernameChanged', $event->messageName());
        $this->assertEquals('John Doe', $event->payload()['name']);
        $this->assertEquals(2, $event->version());
    }

    /**
     * @test
     */
    public function it_replays_from_specific_date()
    {
        $testStream = $this->getTestStream();

        $this->adapter->beginTransaction();

        $this->adapter->create($testStream);

        $this->adapter->commit();

        sleep(1);

        $since = new \DateTime('now', new \DateTimeZone('UTC'));

        $streamEvent = UsernameChanged::with(
            ['name' => 'John Doe'],
            2
        );

        $streamEvent = $streamEvent->withAddedMetadata('tag', 'person');

        $this->adapter->appendTo(new StreamName('Prooph\Model\User'), new \ArrayIterator([$streamEvent]));

        $streamEvents = $this->adapter->replay(new StreamName('Prooph\Model\User'), $since, ['tag' => 'person']);

        $count = 0;
        foreach ($streamEvents as $event) {
            $count++;
        }
        $this->assertEquals(1, $count);

        $testStream->streamEvents()->rewind();
        $streamEvents->rewind();

        $event = $streamEvents->current();

        $this->assertEquals($streamEvent->uuid()->toString(), $event->uuid()->toString());
        $this->assertEquals(
            $streamEvent->createdAt()->format('Y-m-d\TH:i:s.uO'),
            $event->createdAt()->format('Y-m-d\TH:i:s.uO')
        );
        $this->assertEquals('ProophTest\EventStore\Mock\UsernameChanged', $event->messageName());
        $this->assertEquals('John Doe', $event->payload()['name']);
        $this->assertEquals(2, $event->version());
    }

    /**
     * @test
     */
    public function it_replays_events_of_two_aggregates_in_a_single_stream_in_correct_order()
    {
        $testStream = $this->getTestStream();

        $this->adapter->beginTransaction();

        $this->adapter->create($testStream);

        $this->adapter->commit();

        $streamEvent = UsernameChanged::with(
            ['name' => 'John Doe'],
            2
        );

        $streamEvent = $streamEvent->withAddedMetadata('tag', 'person');

        $this->adapter->appendTo(new StreamName('Prooph\Model\User'), new \ArrayIterator([$streamEvent]));

        sleep(1);

        $secondUserEvent = UserCreated::with(
            ['name' => 'Jane Doe', 'email' => 'jane@acme.com'],
            1
        );

        $secondUserEvent = $secondUserEvent->withAddedMetadata('tag', 'person');

        $this->adapter->appendTo(new StreamName('Prooph\Model\User'), new \ArrayIterator([$secondUserEvent]));

        $streamEvents = $this->adapter->replay(new StreamName('Prooph\Model\User'), null, ['tag' => 'person']);


        $replayedPayloads = [];
        foreach ($streamEvents as $event) {
            $replayedPayloads[] = $event->payload();
        }

        $expectedPayloads = [
            ['name' => 'Max Mustermann', 'email' => 'contact@prooph.de'],
            ['name' => 'John Doe'],
            ['name' => 'Jane Doe', 'email' => 'jane@acme.com'],
        ];

        $this->assertEquals($expectedPayloads, $replayedPayloads);
    }

    /**
     * @test
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Transaction already started
     */
    public function it_throws_exception_when_second_transaction_started()
    {
        $this->adapter->beginTransaction();
        $this->adapter->beginTransaction();
    }

    /**
     * @test
     * @expectedException Prooph\EventStore\Exception\RuntimeException
     * @expectedExceptionMessage Cannot create empty stream Prooph\Model\User.
     */
    public function it_throws_exception_when_empty_stream_created()
    {
        $this->adapter->create(new Stream(new StreamName('Prooph\Model\User'), new \ArrayIterator([])));
    }

    /**
     * @test
     */
    public function it_can_rollback_transaction()
    {
        $testStream = $this->getTestStream();

        $this->adapter->beginTransaction();

        $this->adapter->create($testStream);

        $this->adapter->commit();

        $this->adapter->beginTransaction();

        $streamEvent = UserCreated::with(
            ['name' => 'Max Mustermann', 'email' => 'contact@prooph.de'],
            1
        );

        $streamEvent = $streamEvent->withAddedMetadata('tag', 'person');

        $this->adapter->appendTo(new StreamName('Prooph\Model\User'), new \ArrayIterator([$streamEvent]));

        $this->adapter->rollback();

        $result = $this->adapter->loadEvents(new StreamName('Prooph\Model\User'), ['tag' => 'person']);

        $this->assertNotNull($result->current());
        $this->assertEquals(0, $result->key());
        $result->next();
        $this->assertNull($result->current());
    }

    /**
     * @test
     * @expectedException \Prooph\EventStore\Exception\ConcurrencyException
     */
    public function it_fails_to_write_with_duplicate_aggregate_id_and_version()
    {
        $streamEvent = UserCreated::with(
            ['name' => 'Max Mustermann', 'email' => 'contact@prooph.de'],
            1
        );

        $streamEvent = $streamEvent->withAddedMetadata('aggregate_id', 'one');
        $streamEvent = $streamEvent->withAddedMetadata('aggregate_type', 'user');

        $this->adapter->create(new Stream(new StreamName('Prooph\Model\User'), new \ArrayIterator([$streamEvent])));

        $streamEvent = UsernameChanged::with(
            ['name' => 'John Doe'],
            1
        );

        $streamEvent = $streamEvent->withAddedMetadata('aggregate_id', 'one');
        $streamEvent = $streamEvent->withAddedMetadata('aggregate_type', 'user');

        $this->adapter->appendTo(new StreamName('Prooph\Model\User'), new \ArrayIterator([$streamEvent]));
    }

    /**
     * @test
     */
    public function it_can_commit_empty_transaction()
    {
        $this->adapter->beginTransaction();
        $this->adapter->commit();
    }

    /**
     * @test
     */
    public function it_can_rollback_empty_transaction()
    {
        $this->adapter->beginTransaction();
        $this->adapter->rollback();
    }

    /**
     * @return Stream
     */
    private function getTestStream()
    {
        $streamEvent = UserCreated::with(
            ['name' => 'Max Mustermann', 'email' => 'contact@prooph.de'],
            1
        );

        $streamEvent = $streamEvent->withAddedMetadata('tag', 'person');

        return new Stream(new StreamName('Prooph\Model\User'), new \ArrayIterator([$streamEvent]));
    }
}
