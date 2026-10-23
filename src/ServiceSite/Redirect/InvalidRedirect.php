<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

/** Правило не можна зберегти: невалідна адреса, нема цілі, редирект на себе, цикл, жива адреса. Повідомлення — для людини. */
final class InvalidRedirect extends \DomainException
{
}
