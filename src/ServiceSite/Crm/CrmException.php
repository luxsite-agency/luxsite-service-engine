<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Crm;

/**
 * Будь-яка невдача розмови з CRM: заявка вже в БД, тож AbstractLeadCrm ловить, пише в crm_error і лог.
 * Текст потрапляє в адмінку й лог - адрес вебхуків і токенів у ньому бути не має.
 */
class CrmException extends \RuntimeException
{
}
