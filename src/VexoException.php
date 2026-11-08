<?php

declare(strict_types=1);

namespace Vexo;

/**
 * A failure that the client passes to your onError callback. The client never throws it.
 */
final class VexoException extends \RuntimeException
{
    /**
     * @param ?int $status the HTTP status of a rejected request; null for a network error or a message dropped locally
     * @param list<array<string, mixed>> $batch the messages lost with a failed request, or still queued at the deadline
     * @param list<array{message: ?array<string, mixed>, reason: string}> $dropped messages the API dropped or couldn't store
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $batch = [],
        public readonly array $dropped = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
