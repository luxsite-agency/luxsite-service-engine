<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\EventSubscriber\PartnerLoginSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class PartnerLoginSubscriberTest extends TestCase
{
    public function testSharedSuccessEventRedirectsPartnerAndPreservesStaffTarget(): void
    {
        foreach (['/login', '/connect/google/check'] as $path) {
            foreach ([['ROLE_PARTNER'], ['ROLE_MODERATOR'], ['ROLE_PARTNER', 'ROLE_ADMIN'], ['ROLE_USER']] as $roles) {
                $user = (new User())->setEmail('login@example.test')->setRoles($roles);
                $request = Request::create($path);
                $request->setSession(new Session(new MockArraySessionStorage()));
                $request->getSession()->set('_security.main.target_path', '/admin/service-lead');
                $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user));
                $original = new RedirectResponse('/admin/service-lead');
                $event = new LoginSuccessEvent($this->createStub(AuthenticatorInterface::class), $passport,
                    new PostAuthenticationToken($user, 'main', $user->getRoles()), $request, $original, 'main');
                (new PartnerLoginSubscriber())->onSuccess($event);
                if ($roles === ['ROLE_PARTNER']) {
                    self::assertSame('/partner', $event->getResponse()->headers->get('Location'));
                    self::assertFalse($request->getSession()->has('_security.main.target_path'));
                } else {
                    self::assertSame($original, $event->getResponse());
                    self::assertSame('/admin/service-lead', $request->getSession()->get('_security.main.target_path'));
                }
            }
        }
    }
}
