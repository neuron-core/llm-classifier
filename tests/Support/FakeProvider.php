<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Tests\Support;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use LogicException;

/**
 * Minimal AIProviderInterface double for tests. Resolves a prompt to a canned
 * response via a callable, so tests can drive panel pass/fail deterministically.
 */
final class FakeProvider implements AIProviderInterface
{
    /** @var callable(string): string */
    private $responder;

    /**
     * @param callable(string): string $responder maps the prompt to a response string
     */
    public function __construct(
        private readonly string $name,
        callable $responder,
    ) {
        $this->responder = $responder;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        throw new LogicException('Not used by the classifier.');
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        throw new LogicException('Not used by the classifier.');
    }

    public function chat(Message ...$messages): \NeuronAI\Chat\Messages\UserMessage
    {
        $prompt = '';
        foreach ($messages as $message) {
            if ($message instanceof UserMessage && $message->getContent() !== null) {
                $prompt = $message->getContent();
                break;
            }
        }

        return new UserMessage(($this->responder)($prompt));
    }

    public function stream(Message ...$messages): Generator
    {
        yield from [];

        return new UserMessage('');
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        throw new LogicException('Not used by the classifier.');
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }
}
