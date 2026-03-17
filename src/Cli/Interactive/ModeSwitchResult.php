<?php

namespace FQL\Cli\Interactive;

use FQL\Cli\Query\QueryExecutorInterface;

/**
 * Result of a mode switch attempt in the REPL.
 */
class ModeSwitchResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?QueryExecutorInterface $executor = null,
        public readonly ?HistoryManager $historyManager = null,
        public readonly ?string $error = null
    ) {
    }

    public static function ok(QueryExecutorInterface $executor, HistoryManager $historyManager): self
    {
        return new self(true, $executor, $historyManager);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, null, $error);
    }
}
