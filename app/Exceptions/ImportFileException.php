<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Dilempar saat import Excel gagal validasi: seluruh file dibatalkan, tidak ada data berubah.
 */
class ImportFileException extends RuntimeException
{
    public array $errors = [];

    public function __construct(array $errors = [], string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : 'File gagal divalidasi. Tidak ada data yang diubah.', $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
