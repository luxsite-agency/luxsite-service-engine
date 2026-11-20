<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Settings;

/** Стан TXT підтвердження Google на одному домені бренду (GoogleDnsVerification). */
final readonly class DnsStatus
{
    /** @param list<string> $lines рядки TXT, які мають стояти (для ручного додавання — точно ці) */
    public function __construct(
        public string $domain,
        public string $state,
        public array $lines,
        public ?string $error = null,
        public int $created = 0,
        public int $deleted = 0,
    ) {
    }
}
