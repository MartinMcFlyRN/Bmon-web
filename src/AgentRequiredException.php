<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Some required checks could not be created; $added lists those that were. */
final class AgentRequiredException extends \RuntimeException
{
    public function __construct(public readonly array $added, string $message)
    {
        parent::__construct($message);
    }
}
