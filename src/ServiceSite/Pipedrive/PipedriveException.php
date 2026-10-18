<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Pipedrive;

use App\ServiceSite\Crm\CrmException;

/** Будь-яка невдача розмови з Pipedrive: заявка вже в БД, тож ловимо й логуємо. */
final class PipedriveException extends CrmException
{
}
