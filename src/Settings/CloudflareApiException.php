<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Settings;

/** Відмова чи збій Cloudflare API; повідомлення вже без токена (GoogleDnsVerification::clean()). */
final class CloudflareApiException extends \RuntimeException
{
}
