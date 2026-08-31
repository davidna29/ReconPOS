<?php

namespace App\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * Dilempar ketika operasi akan membuat stok minus (edit/hapus pembelian, dsb).
 */
class StokException extends InvalidArgumentException
{
    public array $detail = [];

    public function __construct(string $message = '', array $detail = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->detail = $detail;
    }

    public function getDetail(): array
    {
        return $this->detail;
    }
}
