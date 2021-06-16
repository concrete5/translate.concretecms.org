<?php

namespace CommunityTranslation\Service;

use CommunityTranslation\Entity\Locale as LocaleEntity;
use CommunityTranslation\Repository\Locale as LocaleRepository;
use Concrete\Core\Application\Application;
use Concrete\Core\Entity\User\User as UserEntity;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\User;
use Concrete\Core\User\User as UserService;
use Doctrine\ORM\EntityManager;
use PortlandLabs\CommunityBadgesClient\Models\Achievements;

class Access
{
    /**
     * User is not logged in.
     *
     * @var int
     */
    const NOT_LOGGED_IN = 0;

    /**
     * No access.
     *
     * @var int
     */
    const NONE = 1;

    /**
     * Aspiring to translate a specific locale.
     *
     * @var int
     */
    const ASPRIRING = 2;

    /**
     * Translate a specific locale.
     *
     * @var int
     */
    const TRANSLATE = 3;

    /**
     * Manage a specific locale.
     *
     * @var int
     */
    const ADMIN = 4;

    /**
     * Manage all the locales.
     *
     * @var int
     */
    const GLOBAL_ADMIN = 5;

    /**
     * The application object.
     *
     * @var Application
     */
    protected $app;

    /**
     * The groups service.
     *
     * @var Groups
     */
    protected $groups;

    /**
     * @param Application $application
     */
    public function __construct(Application $app, Groups $groups)
    {
        $this->app = $app;
        $this->groups = $groups;
    }

    /**
     * Parse the $user parameter of the functions.
     *
     * @param int|UserService|UserEntity|'current' $user
     *
     * @return \User|null
     */
    public function getUser($user)
    {
        $result = null;
        if ($user === 'current') {
            $u = new \User();
            if ($u->isRegistered()) {
                $result = $u;
            }
        } elseif (is_int($user) || (is_string($user) && is_numeric($user))) {
            $result = \User::getByUserID($user);
        } elseif ($user instanceof UserService) {
            $result = $user;
        } elseif ($user instanceof UserEntity) {
            $result = \User::getByUserID($user->getUserID());
        }

        return $result;
    }

    /**
     * Parse the $user parameter of the functions.
     *
     * @param int|UserService|UserEntity|'current' $user
     *
     * @return UserEntity|null
     */
    public function getUserEntity($user)
    {
        $result = null;
        if ($user instanceof UserEntity) {
            $result = $user;
        } else {
            $u = $this->getUser($user);
            if ($u !== null) {
                $result = $this->app->make(EntityManager::class)->find(UserEntity::class, $u->getUserID());
            }
        }

        return $result;
    }

    /**
     * Parse the $locale parameter of the functions.
     *
     * @param mixed $locale
     *
     * @return LocaleEntity|null
     */
    protected function getLocale($locale)
    {
        $result = null;
        if ($locale instanceof LocaleEntity) {
            $result = $locale;
        } elseif (is_string($locale) && $locale !== '') {
            $result = $this->app->make(LocaleRepository::class)->findApproved($locale);
        }

        return $result;
    }

    /**
     * @return bool
     */
    public function isLoggedIn()
    {
        return $this->getUser('current') !== null;
    }

    /**
     * Get the access level to a specific locale.
     *
     * @param LocaleEntity|string $locale
     * @param UserService|int|'current' $user
     *
     * @return int One of the Access constants
     */
    public function getLocaleAccess($locale, $user = 'current')
    {
        $result = self::NONE;
        $user = $this->getUser($user);
        if ($user === null) {
            $result = self::NOT_LOGGED_IN;
        } else {
            $result = self::NONE;
            if ($user->getUserID() == USER_SUPER_ID) {
                $result = self::GLOBAL_ADMIN;
            } elseif ($user->inGroup($this->groups->getGlobalAdministrators())) {
                $result = self::GLOBAL_ADMIN;
            } else {
                $locale = $this->getLocale($locale);
                if ($locale !== null) {
                    if ($user->inGroup($this->groups->getAdministrators($locale))) {
                        $result = self::ADMIN;
                    } elseif ($user->inGroup($this->groups->getTranslators($locale))) {
                        $result = self::TRANSLATE;
                    } elseif ($user->inGroup($this->groups->getAspiringTranslators($locale))) {
                        $result = self::ASPRIRING;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get the access level to a specific locale.
     *
     * @param LocaleEntity|string $wantedLocale
     * @param int $access One of the Access constants
     * @param UserService|int|'current' $wantedUser
     *
     * @return int One of the Access constants
     */
    public function setLocaleAccess($wantedLocale, $access, $wantedUser = 'current')
    {
        $user = $this->getUser($wantedUser);
        if ($user === null) {
            throw new UserMessageException(t('Invalid user'));
        }
        $locale = $this->getLocale($wantedLocale);
        if ($locale === null) {
            throw new UserMessageException(t("The locale identifier '%s' is not valid", $locale));
        }
        if ($user->getUserID() === USER_SUPER_ID) {
            return;
        }
        $access = (int)$access;
        if ($access === self::GLOBAL_ADMIN) {
            $this->setGlobalAccess(true, $user);
        } else {
            $oldAccess = $this->getLocaleAccess($locale, $user);
            if ($oldAccess === self::GLOBAL_ADMIN && $access !== self::NONE) {
                throw new UserMessageException(t('User is a global locale administrator'));
            }
            switch ($access) {
                case self::ADMIN:
                    $newGroup = $this->groups->getAdministrators($locale);
                    /** @var Achievements $achievements */
                    $achievements = $this->app->make(Achievements::class, ["user" => $wantedUser]);
                    $achievements->assign("coordinator");
                    break;
                case self::TRANSLATE:
                    $newGroup = $this->groups->getTranslators($locale);
                    break;
                case self::ASPRIRING:
                    $newGroup = $this->groups->getAspiringTranslators($locale);
                    break;
                case self::NONE:
                    $newGroup = null;
                    break;
                default:
                    throw new UserMessageException(t('Invalid access level specified'));
            }
            if ($newGroup !== null) {
                $user->enterGroup($newGroup);
            }
            switch ($oldAccess) {
                case self::GLOBAL_ADMIN:
                    /* @noinspection PhpMissingBreakStatementInspection */
                case self::ADMIN:
                    $g = $this->groups->getAdministrators($locale);
                    if ($g !== $newGroup && $user->inGroup($g)) {
                        $user->exitGroup($g);
                    }
                /* @noinspection PhpMissingBreakStatementInspection */
                case self::TRANSLATE:
                    $g = $this->groups->getTranslators($locale);
                    if ($g !== $newGroup && $user->inGroup($g)) {
                        $user->exitGroup($g);
                    }
                /* @noinspection PhpMissingBreakStatementInspection */
                case self::ASPRIRING:
                    $g = $this->groups->getAspiringTranslators($locale);
                    if ($g !== $newGroup && $user->inGroup($g)) {
                        $user->exitGroup($g);
                    }
                    break;
            }
        }
    }

    /**
     * Set or unset global administration access.
     *
     * @param bool $enable
     * @param UserService|int|'current' $user
     */
    public function setGlobalAccess($enable, $user = 'current')
    {
        $user = $this->getUser($user);
        if ($user === null) {
            throw new UserMessageException(t('Invalid user'));
        }
        if ($user->getUserID() === USER_SUPER_ID) {
            return;
        }
        $group = $this->groups->getGlobalAdministrators();
        if ($enable) {
            foreach ($this->app->make(LocaleRepository::class)->getApprovedLocales() as $locale) {
                $this->setLocaleAccess($locale, self::NONE, $user);
            }
            if (!$user->inGroup($group)) {
                $user->enterGroup($group);
            }
        } else {
            if ($user->inGroup($group)) {
                $user->exitGroup($group);
            }
        }
    }
}
