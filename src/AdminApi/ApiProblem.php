<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

/** Помилка операції контенту з HTTP-статусом: REST віддає її як {"error": …}, MCP — як isError-результат інструмента. */
final class ApiProblem extends \RuntimeException
{
    /** @param array<string, string> $headers додаткові заголовки REST-відповіді (Retry-After для 429) */
    public function __construct(string $message, public readonly int $status = 400, public readonly array $headers = [])
    {
        parent::__construct($message);
    }
}
