<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use RuntimeException;

final class TransferException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
