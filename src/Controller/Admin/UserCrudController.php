<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Користувачі адмінки: створення з формою (email+пароль+ролі), редагування ролей,
 * деактивація. Пароль хешується у persistEntity/updateEntity; порожній пароль
 * при редагуванні = «не міняти». Видалення вимкнено — деактивуй перемикачем.
 */
#[IsGranted('ROLE_ADMIN')]
class UserCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly \App\AdminApi\AdminApiTokens $tokens,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Користувач')
            ->setEntityLabelInPlural('Користувачі')
            ->setEntityPermission('ROLE_ADMIN');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions->disable(Action::DELETE));
    }

    public function configureFields(string $pageName): iterable
    {
        // підказки в шапці таблиці беруться з setHelp() самих полів
        yield from $this->withHints($this->fieldList($pageName));
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function fieldList(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield EmailField::new('email', 'Email')
            ->setHelp('Логін для входу');
        yield TextField::new('plainPassword', 'Пароль')
            ->onlyOnForms()
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('required', $pageName === Crud::PAGE_NEW)
            ->setHelp($pageName === Crud::PAGE_EDIT ? 'Порожнє поле = пароль не змінюється' : 'Мінімум 12 символів');
        yield ChoiceField::new('roles', 'Ролі')
            ->setChoices([
                'Адмін (все)' => 'ROLE_ADMIN',
                'Модератор (контент + заявки)' => 'ROLE_MODERATOR',
                'Користувач' => 'ROLE_USER',
            ])
            ->allowMultipleChoices();
        yield BooleanField::new('active', 'Активний');
    }

    /** @param User $entityInstance */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    /** @param User $entityInstance */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
        // вимкнений користувач → усі його API-токени відкликано (чинний токен вимкненого й так не проходить, але слід лишаємо)
        if (!$entityInstance->isActive()) {
            $by = $this->getUser();
            if ($by instanceof User) {
                $this->tokens->revokeAllOf($entityInstance, $by);
            }
        }
    }

    private function hashPassword(User $user): void
    {
        $plain = $user->getPlainPassword();
        if ($plain !== null && $plain !== '') {
            $user->setPassword($this->passwordHasher->hashPassword($user, $plain));
            $user->eraseCredentials();
        }
    }
}
