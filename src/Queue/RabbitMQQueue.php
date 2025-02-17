<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue;

use RuntimeException;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;
use Interop\Amqp\AmqpQueue;
use Interop\Amqp\AmqpTopic;
use Psr\Log\LoggerInterface;
use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpMessage;
use Interop\Amqp\Impl\AmqpBind;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;

class RabbitMQQueue extends Queue implements QueueContract
{
    protected $sleepOnError;

    protected $queueName;
    protected $queueOptions;
    protected $exchangeOptions;

    protected $declaredExchanges = [];
    protected $declaredQueues = [];

    protected $message;

    /**
     * @var AmqpContext
     */
    protected $context;
    protected $correlationId;

    public function __construct(AmqpContext $context, array $config)
    {
        $this->context = $context;

        $this->queueName = $config['queue'] ?? $config['options']['queue']['name'];
        $this->queueOptions = $config['options']['queue'];
        $this->queueOptions['arguments'] = isset($this->queueOptions['arguments']) ?
            json_decode($this->queueOptions['arguments'], true) : [];

        $this->exchangeOptions = $config['options']['exchange'];
        $this->exchangeOptions['arguments'] = isset($this->exchangeOptions['arguments']) ?
            json_decode($this->exchangeOptions['arguments'], true) : [];

        $this->sleepOnError = $config['sleep_on_error'] ?? 5;
    }

    /** {@inheritdoc} */
    public function size($queueName = null): int
    {
        /** @var AmqpQueue $queue */
        [$queue] = $this->declareEverything($queueName);

        return $this->context->declareQueue($queue);
    }

    /** {@inheritdoc} */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue, []);
    }

    /** {@inheritdoc} */
    public function pushRaw($payload, $queueName = null, array $options = [])
    {
        try {
            /**
             * @var AmqpTopic
             * @var AmqpQueue $queue
             */
            [$queue, $topic] = $this->declareEverything($queueName);

            /** @var AmqpMessage $message */
            $this->message = $this->context->createMessage($payload);

            $this->message->setCorrelationId($this->getCorrelationId());
            $this->message->setContentType('application/json');
            $this->message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);

            if (isset($options['routing_key'])) {
                $this->message->setRoutingKey($options['routing_key']);
            } else {
                $this->message->setRoutingKey($queue->getQueueName());
            }

            if (isset($options['priority'])) {
                $this->message->setPriority($options['priority']);
            }

            if (isset($options['expiration'])) {
                $this->message->setExpiration($options['expiration']);
            }

            if (isset($options['headers'])) {
                $this->message->setHeaders($options['headers']);
            }

            if (isset($options['properties'])) {
                $this->message->setProperties($options['properties']);
            }

            if (isset($options['attempts'])) {
                $this->message->setProperty(RabbitMQJob::ATTEMPT_COUNT_HEADERS_KEY, $options['attempts']);
            }

            $producer = $this->context->createProducer();
            if (isset($options['delay']) && $options['delay'] > 0) {
                $producer->setDeliveryDelay($options['delay'] * 1000);
            }

            $producer->send($topic, $this->message);

            return $this->message->getCorrelationId();
        } catch (\Exception $exception) {
            $this->reportConnectionError('pushRaw', $exception);

            return;
        }
    }

    /** {@inheritdoc} */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue, ['delay' => $this->secondsUntil($delay)]);
    }

    /**
     * Release a reserved job back onto the queue.
     *
     * @param  \DateTimeInterface|\DateInterval|int $delay
     * @param  string|object $job
     * @param  mixed $data
     * @param  string $queue
     * @param  int $attempts
     * @return mixed
     */
    public function release($delay, $job, $data, $queue, $attempts = 0)
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue, [
            'delay' => $this->secondsUntil($delay),
            'attempts' => $attempts,
        ]);
    }

    /** {@inheritdoc} */
    public function pop($queueName = null)
    {
        try {
            /** @var AmqpQueue $queue */
            [$queue] = $this->declareEverything($queueName);

            $consumer = $this->context->createConsumer($queue);

            if ($this->message = $consumer->receiveNoWait()) {
                return new RabbitMQJob($this->container, $this, $consumer, $this->message);
            }
        } catch (\Throwable $exception) {
            $this->reportConnectionError('pop', $exception);

            return;
        }
    }

    /**
     * Retrieves the correlation id, or a unique id.
     *
     * @return string
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId ?: uniqid('', true);
    }

    /**
     * Sets the correlation id for a message to be published.
     *
     * @param string $id
     *
     * @return void
     */
    public function setCorrelationId(string $id): void
    {
        $this->correlationId = $id;
    }

    /**
     * @return AmqpContext
     */
    public function getContext(): AmqpContext
    {
        return $this->context;
    }

    /**
     * @param string $queueName
     *
     * @return array [Interop\Amqp\AmqpQueue, Interop\Amqp\AmqpTopic]
     */
    public function declareEverything(string $queueName = null): array
    {
        $queueName = $this->getQueueName($queueName);
        $exchangeName = $this->exchangeOptions['name'] ?: $queueName;

        $topic = $this->context->createTopic($exchangeName);
        $topic->setType($this->exchangeOptions['type']);
        $topic->setArguments($this->exchangeOptions['arguments']);
        if ($this->exchangeOptions['passive']) {
            $topic->addFlag(AmqpTopic::FLAG_PASSIVE);
        }
        if ($this->exchangeOptions['durable']) {
            $topic->addFlag(AmqpTopic::FLAG_DURABLE);
        }
        if ($this->exchangeOptions['auto_delete']) {
            $topic->addFlag(AmqpTopic::FLAG_AUTODELETE);
        }

        if ($this->exchangeOptions['declare'] && ! in_array($exchangeName, $this->declaredExchanges, true)) {
            $this->context->declareTopic($topic);

            $this->declaredExchanges[] = $exchangeName;
        }

        $queue = $this->context->createQueue($queueName);
        $queue->setArguments($this->queueOptions['arguments']);
        if ($this->queueOptions['passive']) {
            $queue->addFlag(AmqpQueue::FLAG_PASSIVE);
        }
        if ($this->queueOptions['durable']) {
            $queue->addFlag(AmqpQueue::FLAG_DURABLE);
        }
        if ($this->queueOptions['exclusive']) {
            $queue->addFlag(AmqpQueue::FLAG_EXCLUSIVE);
        }
        if ($this->queueOptions['auto_delete']) {
            $queue->addFlag(AmqpQueue::FLAG_AUTODELETE);
        }

        if ($this->queueOptions['declare'] && ! in_array($queueName, $this->declaredQueues, true)) {
            $this->context->declareQueue($queue);

            $this->declaredQueues[] = $queueName;
        }

        if ($this->queueOptions['bind']) {
            $this->context->bind(new AmqpBind($queue, $topic, $queue->getQueueName()));
        }

        return [$queue, $topic];
    }

    protected function getQueueName($queueName = null)
    {
        return $queueName ?: $this->queueName;
    }

    protected function createPayloadArray($job, $queue, $data = '')
    {
        return array_merge(parent::createPayloadArray($job, $queue, $data), [
            'id' => $this->getRandomId(),
        ]);
    }

    /**
     * Get a random ID string.
     *
     * @return string
     */
    protected function getRandomId(): string
    {
        return Str::random(32);
    }

    /**
     * @param string $action
     * @param \Throwable $e
     * @throws \Exception
     */
    protected function reportConnectionError($action, \Throwable $e)
    {
        /** @var LoggerInterface $logger */
        $logger = $this->container['log'];

        $logger->error('AMQP error while attempting '.$action.': '.$e->getMessage());

        // If it's set to false, throw an error rather than waiting
        if ($this->sleepOnError === false) {
            throw new RuntimeException('Error writing data to the connection with RabbitMQ', null, $e);
        }

        // Sleep so that we don't flood the log file
        sleep($this->sleepOnError);
    }
}
