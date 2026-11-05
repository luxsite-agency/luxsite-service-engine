<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class PartnerLoginSubscriber
{
    #[AsEventListener(event: LoginSuccessEvent::class, dispatcher: 'security.event_dispatcher.main')]
    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User && \in_array('ROLE_PARTNER', $user->getRoles(), true)
            && array_intersect(['ROLE_MODERATOR', 'ROLE_ADMIN'], $user->getRoles()) === []) {
            $event->getRequest()->getSession()->remove('_security.main.target_path');
            $event->setResponse(new RedirectResponse('/partner'));
        }
    }
}
