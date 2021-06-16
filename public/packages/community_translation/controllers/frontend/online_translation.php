<?php

namespace Concrete\Package\CommunityTranslation\Controller\Frontend;

use CommunityTranslation\Entity\Glossary\Entry as GlossaryEntryEntity;
use CommunityTranslation\Entity\Glossary\Entry\Localized as GlossaryEntryLocalizedEntity;
use CommunityTranslation\Entity\Locale as LocaleEntity;
use CommunityTranslation\Entity\Package as PackageEntity;
use CommunityTranslation\Entity\Package\Version as PackageVersionEntity;
use CommunityTranslation\Entity\PackageSubscription as PackageSubscriptionEntity;
use CommunityTranslation\Entity\PackageVersionSubscription as PackageVersionSubscriptionEntity;
use CommunityTranslation\Entity\Translatable as TranslatableEntity;
use CommunityTranslation\Entity\Translatable\Comment as TranslatableCommentEntity;
use CommunityTranslation\Entity\Translation;
use CommunityTranslation\Entity\Translation as TranslationEntity;
use CommunityTranslation\Repository\Glossary\Entry as GlossaryEntryRepository;
use CommunityTranslation\Repository\Glossary\Entry\Localized as GlossaryEntryLocalizedRepository;
use CommunityTranslation\Repository\Locale as LocaleRepository;
use CommunityTranslation\Repository\Notification as NotificationRepository;
use CommunityTranslation\Repository\Package as PackageRepository;
use CommunityTranslation\Repository\Package\Version as PackageVersionRepository;
use CommunityTranslation\Repository\PackageSubscription as PackageSubscriptionRepository;
use CommunityTranslation\Repository\PackageVersionSubscription as PackageVersionSubscriptionRepository;
use CommunityTranslation\Repository\Stats as StatsRepository;
use CommunityTranslation\Repository\Translatable as TranslatableRepository;
use CommunityTranslation\Repository\Translatable\Comment as TranslatableCommentRepository;
use CommunityTranslation\Repository\Translation as TranslationRepository;
use CommunityTranslation\Service\Access;
use CommunityTranslation\Service\Editor;
use CommunityTranslation\Service\TranslationsFileExporter;
use CommunityTranslation\Service\User as UserService;
use CommunityTranslation\Translation\Exporter;
use CommunityTranslation\Translation\Importer;
use CommunityTranslation\Translation\ImportOptions;
use CommunityTranslation\TranslationsConverter\Provider as TranslationsConverterProvider;
use Concrete\Core\Block\Block;
use Concrete\Core\Entity\User\User as UserEntity;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Http\ResponseAssetGroup;
use Concrete\Core\Http\ResponseFactoryInterface;
use Concrete\Core\User\User;
use Controller;
use Doctrine\ORM\EntityManager;
use Exception;
use Gettext\Translations as GettextTranslations;
use PortlandLabs\CommunityBadgesClient\Models\Achievements;
use Punic\Misc;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use View;

class OnlineTranslation extends Controller
{
    const PACKAGEVERSION_UNREVIEWED = 'unreviewed';

    /**
     * @var Access|null
     */
    private $accessHelper = null;

    /**
     * @return Access
     */
    private function getAccessHelper()
    {
        if ($this->accessHelper === null) {
            $this->accessHelper = $this->app->make(Access::class);
        }

        return $this->accessHelper;
    }

    /**
     * @var EntityManager|null
     */
    private $entityManager = null;

    /**
     * @return EntityManager
     */
    private function getEntityManager()
    {
        if ($this->entityManager === null) {
            $this->entityManager = $this->app->make(EntityManager::class);
        }

        return $this->entityManager;
    }

    public function on_start()
    {
        $config = $this->app->make('community_translation/config');
        $this->controllerActionPath = $config->get('options.onlineTranslationPath') . '/action';
    }

    public function getViewObject()
    {
        $v = new View('frontend/online_translation');
        $v->setPackageHandle('community_translation');
        $v->setViewTheme(null);

        return $v;
    }

    public function view($packageVersionID, $localeID)
    {
        $accessHelper = $this->getAccessHelper();
        if ($accessHelper->isLoggedIn() === false) {
            return $this->app->make(ResponseFactoryInterface::class)->forbidden(
                $this->request->getUri()
            );
        }
        $error = null;
        if ($error === null) {
            $locale = $this->app->make(LocaleRepository::class)->findApproved($localeID);
            if ($locale === null) {
                $error = t('Invalid language identifier received');
            }
        }
        if ($error === null) {
            $access = $accessHelper->getLocaleAccess($locale);
            if ($access < Access::TRANSLATE) {
                $error = t("You don't belong to the %s translation group", $locale->getDisplayName());
            }
        }
        if ($error === null) {
            $packageVersion = null;
            if ($packageVersionID === self::PACKAGEVERSION_UNREVIEWED) {
                if ($access >= Access::ADMIN) {
                    $packageVersion = self::PACKAGEVERSION_UNREVIEWED;
                }
            } else {
                $packageVersion = $this->app->make(PackageVersionRepository::class)->find($packageVersionID);
            }
            if ($packageVersion === null) {
                $error = t('Invalid translated package version identifier received');
            }
        }
        if ($error !== null) {
            return $this->app->make('helper/concrete/ui')->buildErrorResponse(
                t('An unexpected error occurred.'),
                h($error)
            );
        }
        $config = $this->app->make('community_translation/config');
        $onlineTranslationPath = $config->get('options.onlineTranslationPath');

        // Hack to avoid account menu stuff
        $r = ResponseAssetGroup::get();
        $r->markAssetAsIncluded('core/account');
        // /hack
        $this->requireAsset('css', 'font-awesome');
        $this->requireAsset('javascript', 'jquery');
        $this->requireAsset('javascript', 'picturefill');
        $this->requireAsset('javascript-conditional', 'html5-shiv');
        $this->requireAsset('javascript-conditional', 'respond');
        $this->requireAsset('core/cms');
        $this->requireAsset('core/translator');
        $this->requireAsset('community_translation/online_translation');
        $allVersions = null;
        if ($packageVersion === self::PACKAGEVERSION_UNREVIEWED) {
            $this->set('allVersions', null);
        } else {
            $allVersions = $this->getVersionsMenu($packageVersion, $locale);
        }
        $this->set('packageVersion', $packageVersion);
        $this->set('allVersions', $allVersions);
        $this->set('allLocales', $this->getLocalesMenu($packageVersionID, $locale));
        $this->set('onlineTranslationPath', $onlineTranslationPath);
        $this->set('token', $this->app->make('token'));
        $this->set('canApprove', $access >= Access::ADMIN);
        $this->set('locale', $locale);
        $this->set('canEditGlossary', $access >= Access::ADMIN);
        $pluralCases = [];
        foreach ($locale->getPluralForms() as $pluralForm) {
            list($pluralFormKey, $pluralFormExamples) = explode(':', $pluralForm);
            $pluralCases[$pluralFormKey] = $pluralFormExamples;
        }
        $this->set('pluralCases', $pluralCases);
        if ($packageVersion === static::PACKAGEVERSION_UNREVIEWED) {
            $this->set('translations', $this->app->make(Editor::class)->getUnreviewedInitialTranslations($locale));
            $this->set('pageTitle', t(/*i18n: %s is a language name*/'Strings awaiting review in %s', $locale->getDisplayName()));
            $this->set('pageTitleShort', tc(/*i18n: %s is a language name*/'Language', 'Reviewing %s', $locale->getDisplayName()));
        } else {
            $this->set('translations', $this->app->make(Editor::class)->getInitialTranslations($packageVersion, $locale));
            $this->set('pageTitle', t(/*i18n: %1$s is a package name, %2$s is a language name*/'Translating %1$s in %2$s', $packageVersion->getDisplayName(), $locale->getDisplayName()));
            $this->set('pageTitleShort', sprintf('%s %s @ %s', $packageVersion->getPackage()->getDisplayName(), $packageVersion->getVersion(), $locale->getID()));
            if ($access >= Access::ADMIN) {
                $this->set('showUnreviewedIcon', $this->app->make(Exporter::class)->localeHasPendingApprovals($locale));
            }
            $this->set('packageSubscription', $this->getPackageSubscription($packageVersion->getPackage()));
            $this->set('packageVersionSubscriptions', $this->getPackageVersionSubscriptions($packageVersion->getPackage()));
        }
        $translationFormats = [];
        foreach ($this->app->make(TranslationsConverterProvider::class)->getRegisteredConverters() as $tf) {
            if ($tf->supportLanguageHeader() && $tf->supportPlurals() && $tf->canSerializeTranslations() && $tf->canUnserializeTranslations()) {
                $translationFormats[] = $tf;
            }
        }
        $this->set('translationFormats', $translationFormats);
        $session = $this->app->make('session');
        /* @var \Symfony\Component\HttpFoundation\Session\Session $session */
        $showDialogAtStartup = null;
        if ($session->has('comtraShowDialogAtStartup')) {
            $showDialogAtStartup = $session->get('comtraShowDialogAtStartup');
            $session->remove('comtraShowDialogAtStartup');
        }
        $this->set('showDialogAtStartup', $showDialogAtStartup);
        $url = ['/'];
        $block = Block::getByName('CommunityTranslation Search Packages');
        if ($block && $block->getBlockID()) {
            $page = $block->getOriginalCollection();
            if ($page !== null) {
                $url = [$page];
                if ($packageVersion instanceof PackageVersionEntity) {
                    $url[] = 'package/' . $packageVersion->getPackage()->getHandle() . '/' . $packageVersion->getVersion();
                }
            }
        }
        $urlManager = $this->app->make('url/manager');
        $this->set('exitURL', $urlManager->resolve($url));
        $this->set('textDirection', Misc::getCharacterOrder($locale->getID()) === 'right-to-left' ? 'rtl' : 'ltr');
    }

    public function load_translation($localeID)
    {
        $rf = $this->app->make(ResponseFactoryInterface::class);
        try {
            $valt = $this->app->make('token');
            if (!$valt->validate('comtra-load-translation' . $localeID)) {
                throw new UserMessageException($valt->getErrorMessage());
            }
            $locale = $localeID ? $this->app->make(LocaleRepository::class)->findApproved($localeID) : null;
            if ($locale === null) {
                throw new UserMessageException(t('Invalid language identifier received'));
            }
            $access = $this->getAccessHelper()->getLocaleAccess($locale);
            if ($access <= Access::NOT_LOGGED_IN) {
                throw new UserMessageException(t('You need to log-in in order to translate'));
            }
            if ($access < Access::TRANSLATE) {
                throw new UserMessageException(t("You don't belong to the %s translation group", $locale->getDisplayName()));
            }
            $translatableID = $this->post('translatableID');
            $translatable = (is_string($translatableID) && $translatableID) ? $this->app->make(TranslatableRepository::class)->find($translatableID) : null;
            if ($translatable === null) {
                throw new UserMessageException(t('Invalid translatable string identifier received'));
            }
            $packageVersion = null;
            $packageVersionID = $this->post('packageVersionID');
            if ($packageVersionID) {
                $packageVersion = $this->app->make(PackageVersionRepository::class)->find($packageVersionID);
                if ($packageVersion === null) {
                    throw new UserMessageException(t('Invalid translated package version identifier received'));
                }
            }

            return $rf->json(
                $this->app->make(Editor::class)->getTranslatableData($locale, $translatable, $packageVersion, true)
            );
        } catch (UserMessageException $x) {
            return $rf->json(
                [
                    'error' => $x->getMessage(),
                ],
                400
            );
        }
    }

    public function save_comment($localeID)
    {
        $rf = $this->app->make(ResponseFactoryInterface::class);
        try {
            $valt = $this->app->make('token');
            if (!$valt->validate('comtra-save-comment' . $localeID)) {
                throw new UserMessageException($valt->getErrorMessage());
            }
            $locale = $localeID ? $this->app->make(LocaleRepository::class)->findApproved($localeID) : null;
            if ($locale === null) {
                throw new UserMessageException(t('Invalid language identifier received'));
            }
            $accessHelper = $this->getAccessHelper();
            $access = $accessHelper->getLocaleAccess($locale);
            if ($access <= Access::NOT_LOGGED_IN) {
                throw new UserMessageException(t('You need to log-in in order to translate'));
            }
            if ($access < Access::TRANSLATE) {
                throw new UserMessageException(t("You don't belong to the %s translation group", $locale->getDisplayName()));
            }
            $packageVersionID = $this->post('packageVersionID');
            $packageVersion = $packageVersionID ? $this->app->make(PackageVersionRepository::class)->find($packageVersionID) : null;
            if ($packageVersion === null) {
                throw new UserMessageException(t('Invalid translated package version identifier received'));
            }
            $postedBy = $accessHelper->getUserEntity('current');
            $id = $this->post('id');
            if ($id === 'new') {
                $parentID = $this->post('parent');
                if ($parentID === 'root') {
                    $parent = null;
                    $translatableID = $this->post('translatable');
                    $translatable = $translatableID ? $this->app->make(TranslatableRepository::class)->find($translatableID) : null;
                    if ($translatable === null) {
                        throw new UserMessageException(t('Unable to find the specified translatable string.'));
                    }
                } else {
                    $parent = $parentID ? $this->app->make(TranslatableCommentRepository::class)->find($parentID) : null;
                    if ($parent === null) {
                        throw new UserMessageException(t('Unable to find the specified parent comment.'));
                    }
                    $translatable = $parent->getTranslatable();
                }
                if ($parent === null) {
                    switch ($this->post('visibility')) {
                        case 'locale':
                            $commentLocale = $locale;
                            break;
                        case 'global':
                            $commentLocale = null;
                            break;
                        default:
                            throw new UserMessageException(t('Please specify the comment visibility.'));
                    }
                } else {
                    $commentLocale = null;
                }
                $comment = TranslatableCommentEntity::create($translatable, $postedBy, $commentLocale, $parent);
            } else {
                $comment = $id ? $this->app->make(TranslatableCommentRepository::class)->find($id) : null;
                if ($comment === null) {
                    throw new UserMessageException(t('Unable to find the specified comment.'));
                }
                if ($comment->getPostedBy() !== $postedBy) {
                    throw new UserMessageException(t('Access denied to this comment.'));
                }
                if ($comment->getParentComment() === null) {
                    switch ($this->post('visibility')) {
                        case 'locale':
                            $commentLocale = $locale;
                            break;
                        case 'global':
                            $commentLocale = null;
                            break;
                        default:
                            throw new UserMessageException(t('Please specify the comment visibility.'));
                    }
                    $comment->setLocale($commentLocale);
                }
            }
            $comment->setText($this->post('text'));
            if ($comment->getText() === '') {
                throw new UserMessageException(t('Please specify the comment text.'));
            }
            $em = $this->getEntityManager();
            $em->persist($comment);
            $em->flush();
            $this->app->make(NotificationRepository::class)->translatableCommentSubmitted($comment, $packageVersion, $locale);

            return $rf->json(
                [
                    'id' => $comment->getID(),
                    'date' => $this->app->make('helper/date')->formatPrettyDateTime($comment->getPostedOn(), true, true),
                    'mine' => true,
                    'by' => $this->app->make(UserService::class)->format($comment->getPostedBy()),
                    'text' => $comment->getText(),
                    'isGlobal' => $comment->getLocale() === null,
                ]
            );
        } catch (UserMessageException $x) {
            return $rf->json(
                [
                    'error' => $x->getMessage(),
                ],
                400
            );
        }
    }

    public function delete_comment($localeID)
    {
        $rf = $this->app->make(ResponseFactoryInterface::class);
        try {
            $valt = $this->app->make('token');
            if (!$valt->validate('comtra-delete-comment' . $localeID)) {
                throw new UserMessageException($valt->getErrorMessage());
            }
            $locale = $localeID ? $this->app->make(LocaleRepository::class)->findApproved($localeID) : null;
            if ($locale === null) {
                throw new UserMessageException(t('Invalid language identifier received'));
            }
            $access = $this->getAccessHelper()->getLocaleAccess($locale);
            if ($access <= Access::NOT_LOGGED_IN) {
                throw new UserMessageException(t('You need to log-in in order to translate'));
            }
            if ($access < Access::TRANSLATE) {
                throw new UserMessageException(t("You don't belong to the %s translation group", $locale->getDisplayName()));
            }
            $id = $this->post('id');
            $comment = $id ? $this->app->make(TranslatableCommentRepository::class)->find($id) : null;
            if ($comment === null) {
                throw new UserMessageException(t('Unable to find the specified comment.'));
            }
            if (count($comment->getChildComments()) > 0) {
                throw new UserMessageException(t("This comment has some replies, so it can't be deleted."));
            }
            $em = $this->getEntityManager();
            $em->remove($comment);
            $em->flush();

            return $rf->json(
                true
            );
        } catch (UserMessageException $x) {
            return $rf->json(
                [
                    'error' => $x->getMessage(),
                ],
                400
            );
        }
    }

    public function save_glossary_term($localeID)
    {
        $rf = $this->app->make(ResponseFactoryInterface::class);
        try {
            $valt = $this->app->make('token');
            if (!$valt->validate('comtra-save-glossary-term' . $localeID)) {
                throw new UserMessageException($valt->getErrorMessage());
            }
            $locale = $localeID ? $this->app->make(LocaleRepository::class)->findApproved($localeID) : null;
            if ($locale === null) {
                throw new UserMessageException(t('Invalid language identifier received'));
            }
            $access = $this->getAccessHelper()->getLocaleAccess($locale);
            if ($access <= Access::NOT_LOGGED_IN) {
                throw new UserMessageException(t('You need to log-in in order to translate'));
            }
            if ($access < Access::TRANSLATE) {
                throw new UserMessageException(t("You don't belong to the %s translation group", $locale->getDisplayName()));
            } elseif ($access < Access::ADMIN) {
                throw new UserMessageException(t('Access denied.'));
            }
            $id = $this->post('id');
            if ($id === 'new') {
                $editing = GlossaryEntryEntity::create();
            } else {
                $editing = $id ? $this->app->make(GlossaryEntryRepository::class)->find($id) : null;
                if ($editing === null) {
                    throw new UserMessageException(t('Unable to find the specified gossary entry.'));
                }
            }
            $editing->setTerm($this->post('term'));
            if ($editing->getTerm() === '') {
                throw new UserMessageException(t('Please specify the term.'));
            }
            $editing->setType($this->post('type'));
            $existing = $this->app->make(GlossaryEntryRepository::class)->findOneBy(['term' => $editing->getTerm(), 'type' => $editing->getType()]);
            if ($existing !== null && $existing->getID() !== $editing->getID()) {
                throw new UserMessageException(t('The term "%1$s" already exists for the type "%2$s"', $editing->getTerm(), $editing->getType()));
            }
            $editing->setComments($this->post('termComments'));
            $em = $this->getEntityManager();
            $em->beginTransaction();
            try {
                $em->persist($editing);
                $em->flush();
                $translation = trim((string) $this->post('translation'));
                $localized = $editing->getID() ? $this->app->make(GlossaryEntryLocalizedRepository::class)->find(['entry' => $editing->getID(), 'locale' => $locale->getID()]) : null;
                if ($translation === '') {
                    if ($localized !== null) {
                        $em->remove($localized);
                        $localized = null;
                    }
                } else {
                    if ($localized === null) {
                        $localized = GlossaryEntryLocalizedEntity::create($editing, $locale);
                    }
                    $localized
                        ->setTranslation($translation)
                        ->setComments($this->post('translationComments'));
                    $em->persist($localized);
                }
                $em->flush();
                $em->commit();

                return $rf->json(
                    [
                        'id' => $editing->getID(),
                        'term' => $editing->getTerm(),
                        'type' => $editing->getType(),
                        'termComments' => $editing->getComments(),
                        'translation' => ($localized === null) ? '' : $localized->getTranslation(),
                        'translationComments' => ($localized === null) ? '' : $localized->getComments(),
                    ]
                );
            } catch (Exception $x) {
                try {
                    $em->rollback();
                } catch (Exception $foo) {
                }
                throw $x;
            }
        } catch (UserMessageException $x) {
            return $rf->json(
                [
                    'error' => $x->getMessage(),
                ],
                400
            );
        }
    }

    public function delete_glossary_term($localeID)
    {
        $rf = $this->app->make(ResponseFactoryInterface::class);
        try {
            $valt = $this->app->make('token');
            if (!$valt->validate('comtra-delete-glossary-term' . $localeID)) {
                throw new UserMessageException($valt->getErrorMessage());
            }
            $locale = $localeID ? $this->app->make(LocaleRepository::class)->findApproved($localeID) : null;
            if ($locale === null) {
                throw new UserMessageException(t('Invalid language identifier received'));
            }
            $access = $this->getAccessHelper()->getLocaleAccess($locale);
            if ($access <= Access::NOT_LOGGED_IN) {
                throw new UserMessageException(t('You need to log-in in order to translate'));
            }
            if ($access < Access::TRANSLATE) {
                throw new UserMessageException(t("You don't belong to the %s translation group", $locale->getDisplayName()));
            } elseif ($access < Access::ADMIN) {
                throw new UserMessageException(t('Access denied.'));
            }
            $id = $this->post('id');
            $term = $id ? $this->app->make(GlossaryEntryRepository::class)->find($id) : null;
            if ($term === null) {
                throw new UserMessageException(t('Unable to find the specified gossary entry.'));
            }
            $otherLocaleNames = [];
            foreach ($term->getTranslations() as $translation) {
                if ($translation->getLocale() !== $locale) {
                    $otherLocaleNames[] = $translation->getLocale()->getDisplayName();
                }
            }
            if (!empty($otherLocaleNames)) {
                if (count($otherLocaleNames) < 5) {
                    throw new UserMessageException(t("It's not possible to delete this entry since it's translated in these languages too:", "\n- %s" . implode("\n- ", $otherLocaleNames)));
                } else {
                    throw new UserMessageException(t("It's not possible to delete this entry since it's translated in %d other languages too.", count($otherLocaleNames)));
                }
            }
            $em = $this->getEntityManager();
            $em->remove($term);
            $em->flush();

            return $rf->json(
                true
            );
        } catch (UserMessageException $x) {
            return $rf->json(
                [
                    'error' => $x->getMessage(),
                ],
                400
            );
        }
    }

    public function load_all_places($localeID)
    {
        $rf = $this->app->make(ResponseFactoryInterface::class);
        try {
            $valt = $this->app->make('token');
            if (!$valt->validate('comtra-load-all-places' . $localeID)) {
                throw new UserMessageException($valt->getErrorMessage());
            }
            $locale = $localeID ? $this->app->make(LocaleRepository::class)->findApproved($localeID) : null;
            if ($locale === null) {
                throw new UserMessageException(t('Invalid language identifier received'));
            }
            $access = $this->getAccessHelper()->getLocaleAccess($locale);
            if ($access <= Access::NOT_LOGGED_IN) {
                throw new UserMessageException(t('You need to log-in in order to translate'));
            }
            if ($access < Access::TRANSLATE) {
                throw new UserMessageException(t("You don't belong to the %s translation group", $locale->getDisplayName()));
            }
            $id = $this->post('id');
            $translatable = $id ? $this->app->make(TranslatableRepository::class)->find($id) : null;
            if ($translatable === null) {
                throw new UserMessageException(t('Unable to find the specified translatable string.'));
            }
            $editorService = $this->app->make(Editor::class);
            $result = [];
            foreach ($translatable->getPlaces() as $place) {
                $result[] = [
                    'packageVersion' => $place->getPackageVersion(),
                    'packageVersionDisplayName' => $place->getPackageVersion()->getDisplayName(),
                    'comments' => $place->getComments(),
                    'references' => $editorService->expandReferences($place->getLocations(), $place->getPackageVersion()),
                ];
            }
            usort($result, function (array $a, array $b) {
                $packageVersionA = $a['packageVersion'];
                $packageVersionB = $b['packageVersion'];
                $cmp = strcasecmp($packageVersionA->getPackage()->getDisplayName(), $packageVersionA->getPackage()->getDisplayName());
                if ($cmp === 0) {
                    $isDevA = strpos($packageVersionA->getVersion(), PackageVersionEntity::DEV_PREFIX) === 0;
                    $isDevB = strpos($packageVersionB->getVersion(), PackageVersionEntity::DEV_PREFIX) === 0;
                    if ($isDevA === $isDevB) {
                        $cmp = version_compare($packageVersionB->getVersion(), $packageVersionA->getVersion());
                    } else {
                        $cmp = $isDevA ? -1 : 1;
                    }
                }

                return $cmp;
            });
            foreach (array_keys($result) as $i) {
                unset($result[$i]['packageVersion']);
            }

            return $rf->json(
                $result
            );
        } catch (UserMessageException $x) {
            return $rf->json(
                [
                    'error' => $x->getMessage(),
                ],
                400
            );
        }
    }

    public function process_translation($localeID)
    {
        $rf = $this->app->make(ResponseFactoryInterface::class);
        try {
            $valt = $this->app->make('token');
            if (!$valt->validate('comtra-process-translation' . $localeID)) {
                throw new UserMessageException($valt->getErrorMessage());
            }
            $locale = $localeID ? $this->app->make(LocaleRepository::class)->findApproved($localeID) : null;
            if ($locale === null) {
                throw new UserMessageException(t('Invalid language identifier received'));
            }
            $accessHelper = $this->getAccessHelper();
            $access = $accessHelper->getLocaleAccess($locale);
            if ($access <= Access::NOT_LOGGED_IN) {
                throw new UserMessageException(t('You need to log-in in order to translate'));
            }
            if ($access < Access::TRANSLATE) {
                throw new UserMessageException(t("You don't belong to the %s translation group", $locale->getDisplayName()));
            }
            $translatableID = $this->post('id');
            $translatable = $translatableID ? $this->app->make(TranslatableRepository::class)->find($translatableID) : null;
            if ($translatable === null) {
                throw new UserMessageException(t('Unable to find the specified translatable string.'));
            }
            $packageVersion = null;
            $packageVersionID = $this->post('packageVersionID');
            if ($packageVersionID) {
                $packageVersion = $this->app->make(PackageVersionRepository::class)->find($packageVersionID);
                if ($packageVersion === null) {
                    throw new UserMessageException(t('Invalid translated package version identifier received'));
                }
            }
            $operation = $this->post('operation');
            if (!is_string($operation) || $operation === '') {
                throw new UserMessageException(t('Missing operation identifier'));
            }
            $processTranslationID = $this->post('translationID');
            if ($processTranslationID === null) {
                $processTranslation = null;
            } else {
                $processTranslation = $processTranslationID ? $this->app->make(TranslationRepository::class)->find($processTranslationID) : null;
                if ($processTranslation === null) {
                    throw new UserMessageException(t('Unable to find the specified translation.'));
                }
                if ($processTranslation->getTranslatable() !== $translatable) {
                    throw new UserMessageException(t('The specified translation is not for the correct string.'));
                }
                if ($processTranslation->getLocale() !== $locale) {
                    throw new UserMessageException(t('The specified translation is not for the correct language.'));
                }
            }
            switch ($operation) {
                case 'approve':
                    return $this->approveTranslation($access, $processTranslation, $accessHelper->getUserEntity('current'), $packageVersionID);
                case 'deny':
                    return $this->denyTranslation($access, $processTranslation, $accessHelper->getUserEntity('current'));
                case 'reuse':
                    return $this->reuseTranslation($access, $processTranslation, $accessHelper->getUserEntity('current'), $packageVersionID);
                case 'save-current':
                    if ($this->post('clear') !== '1') {
                        return $this->setTranslationFromEditor($access, $locale, $translatable, $accessHelper->getUserEntity('current'), $packageVersion);
                    } else {
                        return $this->unsetTranslationFromEditor($access, $locale, $translatable);
                    }
                default:
                    throw new UserMessageException(t('Invalid operation identifier received: %s', $operation));
            }
        } catch (UserMessageException $x) {
            return $rf->json(
                [
                    'error' => $x->getMessage(),
                ],
                400
            );
        }
    }

    public function download($localeID)
    {
        $rf = $this->app->make(ResponseFactoryInterface::class);
        try {
            $valt = $this->app->make('token');
            if (!$valt->validate('comtra-download-translations' . $localeID)) {
                throw new UserMessageException($valt->getErrorMessage());
            }
            $accessHelper = $this->getAccessHelper();
            if ($accessHelper->isLoggedIn() === false) {
                throw new UserMessageException(t('You need to be logged in'));
            }
            $locale = $this->app->make(LocaleRepository::class)->findApproved($localeID);
            if ($locale === null) {
                throw new UserMessageException(t('Invalid language identifier received'));
            }
            $access = $accessHelper->getLocaleAccess($locale);
            if ($access < Access::TRANSLATE) {
                throw new UserMessageException(t("You don't belong to the %s translation group", $locale->getDisplayName()));
            }
            $format = $this->app->make(TranslationsConverterProvider::class)->getByHandle((string) $this->post('download-format'));
            if ($format === null) {
                throw new UserMessageException(t('Invalid format identifier received'));
            }

            $packageVersionID = (string) $this->post('packageVersion');
            if ($packageVersionID === self::PACKAGEVERSION_UNREVIEWED) {
                if ($access < Access::ADMIN) {
                    throw new UserMessageException(t('Invalid translated package version identifier received'));
                }
                $translations = $this->app->make(Exporter::class)->unreviewed($locale);
                $serializedTranslations = $format->convertTranslationsToString($translations);
                unset($translations);

                return $rf->create(
                    $serializedTranslations,
                    200,
                    [
                        'Content-Type' => 'application/octet-stream',
                        'Content-Disposition' => 'attachment; filename=translations-' . $locale->getID() . '.' . $format->getFileExtension(),
                        'Content-Transfer-Encoding' => 'binary',
                        'Content-Length' => strlen($serializedTranslations),
                        'Expires' => '0',
                    ]
                );
            } else {
                $packageVersion = $this->app->make(PackageVersionRepository::class)->find($packageVersionID);
                if ($packageVersion === null) {
                    throw new UserMessageException(t('Invalid translated package version identifier received'));
                }
                $serializedTranslationsFile = $this->app->make(TranslationsFileExporter::class)->getSerializedTranslationsFile($packageVersion, $locale, $format);

                return BinaryFileResponse::create(
                    // $file
                    $serializedTranslationsFile,
                    // $status
                    Response::HTTP_OK,
                    // $headers
                    [
                        'Content-Type' => 'application/octet-stream',
                        'Content-Transfer-Encoding' => 'binary',
                    ]
                )->setContentDisposition(
                    'attachment',
                    'translations-' . $locale->getID() . '.' . $format->getFileExtension()
                );
            }
        } catch (UserMessageException $x) {
            return $rf->error($x->getMessage());
        }
    }

    public function upload($localeID)
    {
        try {
            $valt = $this->app->make('token');
            if (!$valt->validate('comtra-upload-translations' . $localeID)) {
                throw new UserMessageException($valt->getErrorMessage());
            }
            $accessHelper = $this->getAccessHelper();
            if ($accessHelper->isLoggedIn() === false) {
                throw new UserMessageException(t('You need to be logged in'));
            }
            $locale = $this->app->make(LocaleRepository::class)->findApproved($localeID);
            if ($locale === null) {
                throw new UserMessageException(t('Invalid language identifier received'));
            }
            $access = $accessHelper->getLocaleAccess($locale);
            if ($access < Access::TRANSLATE) {
                throw new UserMessageException(t("You don't belong to the %s translation group", $locale->getDisplayName()));
            }
            $packageVersionID = (string) $this->post('packageVersion');
        } catch (UserMessageException $x) {
            return $this->app->make('helper/concrete/ui')->buildErrorResponse(
                t('An unexpected error occurred.'),
                h($x->getMessage())
            );
        }
        $session = $this->app->make('session');
        /* @var \Symfony\Component\HttpFoundation\Session\Session $session */
        try {
            $file = $this->request->files->get('file');
            if ($file === null) {
                throw new UserMessageException(t('Please specify the file to be analyzed'));
            }
            /* @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
            if (!$file->isValid()) {
                throw new UserMessageException($file->getErrorMessage());
            }
            $converters = [];
            foreach ($this->app->make(TranslationsConverterProvider::class)->getByFileExtension($file->getClientOriginalExtension()) as $converter) {
                if ($converter->supportLanguageHeader() && $converter->supportPlurals() && $converter->canSerializeTranslations() && $converter->canUnserializeTranslations()) {
                    $converters[] = $converter;
                }
            }
            $err = null;
            $translations = null;
            foreach ($converters as $converter) {
                $t = $converter->loadTranslationsFromFile($file->getPathname());
                if (count($t) < 1) {
                    if ($err === null) {
                        $err = new UserMessageException(t('No translations found in uploaded file'));
                    }
                    continue;
                } elseif (!$t->getLanguage()) {
                    $err = new UserMessageException(t('The translation file does not contain a language header'));
                } elseif (strcasecmp($t->getLanguage(), $locale->getID()) !== 0) {
                    $err = new UserMessageException(t("The translation file is for the '%1\$s' language, not for '%2\$s'", $t->getLanguage(), $locale->getID()));
                } else {
                    $pf = $t->getPluralForms();
                    if ($pf === null) {
                        $err = new UserMessageException(t('The translation file does not define the plural rules'));
                    } elseif ($pf[0] !== $locale->getPluralCount()) {
                        $err = new UserMessageException(t('The translation file defines %1$s plural forms instead of %2$s', $pf[0], $locale->getPluralCount()));
                    } else {
                        $translations = $t;
                        break;
                    }
                }
            }
            if ($translations === null) {
                if ($err === null) {
                    throw new UserMessageException(t('Unknown file extension'));
                } else {
                    throw $err;
                }
            }

            $importer = $this->app->make(Importer::class);
            $me = $accessHelper->getUserEntity('current');
            /* @var Importer $importer */
            if ($access >= Access::ADMIN) {
                $importOptions = new ImportOptions(
                    $this->request->request->get('all-fuzzy'),
                    $this->request->request->get('fuzzy-unapprove')
                );
            } else {
                $importOptions = ImportOptions::forTranslators();
            }
            $imported = $importer->import($translations, $locale, $me, $importOptions);
            if ($imported->newApprovalNeeded > 0) {
                $this->app->make(NotificationRepository::class)->translationsNeedApproval(
                    $locale,
                    $imported->newApprovalNeeded,
                    $me->getUserID(),
                    ($packageVersionID === self::PACKAGEVERSION_UNREVIEWED) ? null : $packageVersionID
                );
            }
            $session->set('comtraShowDialogAtStartup', '
<table class="table table-condensed">
    <tbody>
        ' . ($imported->emptyTranslations > 0 ? ('<tr><td class="warning">' . t('Number of strings not translated (skipped)') . '</td><td> ' . $imported->emptyTranslations . '</td></tr>') : '') . '
        ' . ($imported->unknownStrings > 0 ? ('<tr><td class="danger">' . t('Number of translations for unknown translatable strings (skipped)') . '</td><td> ' . $imported->unknownStrings . '</td></tr>') : '') . '
        ' . ($imported->addedAsCurrent > 0 ? ('<tr><td class="success">' . t('Number of new translations added and marked as the current ones') . '</td><td> ' . $imported->addedAsCurrent . '</td></tr>') : '') . '
        ' . ($imported->addedNotAsCurrent > 0 ? ('<tr><td>' . t('Number of new translations added but not marked as the current ones') . '</td><td> ' . $imported->addedNotAsCurrent . '</td></tr>') : '') . '
        ' . ($imported->existingCurrentUntouched > 0 ? ('<tr><td>' . t('Number of already current translations untouched') . '</td><td> ' . $imported->existingCurrentUntouched . '</td></tr>') : '') . '
        ' . ($imported->existingCurrentApproved > 0 ? ('<tr><td class="success">' . t('Number of current translations marked as approved') . '</td><td> ' . $imported->existingCurrentApproved . '</td></tr>') : '') . '
        ' . ($imported->existingCurrentUnapproved > 0 ? ('<tr><td class="warning">' . t('Number of current translations marked as not approved') . '</td><td> ' . $imported->existingCurrentUnapproved . '</td></tr>') : '') . '
        ' . ($imported->existingActivated > 0 ? ('<tr><td class="success">' . t('Number of previous translations that have been activated (made current)') . '</td><td> ' . $imported->existingActivated . '</td></tr>') : '') . '
        ' . ($imported->existingNotCurrentUntouched > 0 ? ('<tr><td>' . t('Number of translations untouched') . '</td><td> ' . $imported->existingNotCurrentUntouched . '</td></tr>') : '') . '
        ' . ($imported->newApprovalNeeded > 0 ? ('<tr><td class="warning">' . t('Number of new translations needing approval') . '</td><td> ' . $imported->newApprovalNeeded . '</td></tr>') : '') . '
    </tbody>
</table>'
            );
        } catch (UserMessageException $x) {
            $session->set('comtraShowDialogAtStartup', '<div class="alert alert-danger">' . nl2br(h($x->getMessage())) . '</div>');
        }
        $config = $this->app->make('community_translation/config');
        $onlineTranslationPath = $config->get('options.onlineTranslationPath');

        $urlManager = $this->app->make('url/manager');
        $this->redirect($urlManager->resolve([$onlineTranslationPath, $packageVersionID, $locale->getID()]));
    }

    public function save_notifications($packageID)
    {
        $rf = $this->app->make(ResponseFactoryInterface::class);
        try {
            $valt = $this->app->make('token');
            if (!$valt->validate('comtra-save-notifications' . $packageID)) {
                throw new UserMessageException($valt->getErrorMessage());
            }
            $package = $this->app->make(PackageRepository::class)->find($packageID);
            if ($package === null) {
                throw new UserMessageException(t('Invalid translated package identifier received'));
            }
            /* @var PackageEntity $package */
            $packageVersions = [];
            foreach ($package->getVersions() as $pv) {
                $packageVersions[$pv->getID()] = $pv;
            }
            $post = $this->request->request;
            switch ((string) $post->get('newVersions')) {
                case '0':
                    $newVersions = false;
                    break;
                case '1':
                    $newVersions = true;
                    break;
                default:
                    throw new UserMessageException(t('Invalid parameter received (%s)', 'newVersions'));
            }
            switch ((string) $post->get('allVersions')) {
                case 'yes':
                    $notificationForVersions = array_values($packageVersions);
                    break;
                case 'no':
                    $notificationForVersions = [];
                    break;
                case 'custom':
                    $notificationForVersions = [];
                    foreach (explode(',', (string) $post->get('versions')) as $v) {
                        $v = (int) $v;
                        if (!isset($packageVersions[$v])) {
                            throw new UserMessageException(t('Invalid parameter received (%s)', 'versions'));
                        }
                        $notificationForVersions[] = $packageVersions[$v];
                    }
                    break;
                default:
                    throw new UserMessageException(t('Invalid parameter received (%s)', 'allVersions'));
            }
            $em = $this->getEntityManager();
            $em->beginTransaction();
            $ps = $this->getPackageSubscription($package);
            $ps->setNotifyNewVersions($newVersions);
            $em->persist($ps);
            $em->flush($ps);
            foreach ($this->getPackageVersionSubscriptions($package) as $pvs) {
                $pvs->setNotifyUpdates(in_array($pvs->getPackageVersion(), $notificationForVersions, true));
                $em->persist($pvs);
                $em->flush($pvs);
            }
            $em->commit();

            return $rf->json(true);
        } catch (UserMessageException $x) {
            return $rf->json(
                [
                    'error' => $x->getMessage(),
                ],
                400
            );
        }
    }

    /**
     * @param int $access
     * @param TranslationEntity $translation
     * @param UserEntity $user
     * @param mixed $packageVersionID
     *
     * @throws UserMessageException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function approveTranslation($access, TranslationEntity $translation, UserEntity $user, $packageVersionID)
    {
        if ($access < Access::ADMIN) {
            throw new UserMessageException(t('Access denied'));
        }
        if ($translation->isCurrent()) {
            throw new UserMessageException(t('The selected translation is already the current one'));
        }
        $translationID = $translation->getID();
        $translations = $this->convertTranslationToGettext($translation, false);
        $importer = $this->app->make(Importer::class);
        $importer->import($translations, $translation->getLocale(), $user, ImportOptions::forAdministrators());
        $this->getEntityManager()->clear();
        /** @var Translation $translation */
        $translation = $this->app->make(TranslationRepository::class)->find($translationID);
        $result = $this->app->make(Editor::class)->getTranslations($translation->getLocale(), $translation->getTranslatable());

        return $this->app->make(ResponseFactoryInterface::class)->json($result);
    }

    /**
     * @param int $access
     * @param TranslationEntity $translation
     *
     * @throws UserMessageException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function denyTranslation($access, TranslationEntity $translation)
    {
        if ($access < Access::ADMIN) {
            throw new UserMessageException(t('Access denied'));
        }
        if ($translation->isCurrent()) {
            throw new UserMessageException(t('The selected translation is already the current one'));
        }
        $em = $this->getEntityManager();
        $translation->setIsApproved(false);
        $em->persist($translation);
        $em->flush();

        $result = $this->app->make(Editor::class)->getTranslations($translation->getLocale(), $translation->getTranslatable());
        unset($result['current']);

        return $this->app->make(ResponseFactoryInterface::class)->json($result);
    }

    /**
     * @param int $access
     * @param TranslationEntity $translation
     * @param UserEntity $user
     * @param mixed $packageVersionID
     *
     * @throws UserMessageException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function reuseTranslation($access, TranslationEntity $translation, UserEntity $user, $packageVersionID)
    {
        if ($translation->isCurrent()) {
            throw new UserMessageException(t('The selected translation is already the current one'));
        }

        $translationID = $translation->getID();
        $translations = $this->convertTranslationToGettext($translation, $access < Access::ADMIN);
        $importer = $this->app->make(Importer::class);
        $imported = $importer->import($translations, $translation->getLocale(), $user, ($access >= Access::ADMIN) ? ImportOptions::forAdministrators() : ImportOptions::forTranslators());
        $this->getEntityManager()->clear();
        $translation = $this->app->make(TranslationRepository::class)->find($translationID);
        if ($imported->newApprovalNeeded > 0) {
            $this->app->make(NotificationRepository::class)->translationsNeedApproval(
                $translation->getLocale(),
                $imported->newApprovalNeeded,
                $user->getUserID(),
                ($packageVersionID === self::PACKAGEVERSION_UNREVIEWED) ? null : $packageVersionID
             );
        }
        $result = $this->app->make(Editor::class)->getTranslations($translation->getLocale(), $translation->getTranslatable());
        if ($imported->newApprovalNeeded && !$imported->addedAsCurrent) {
            $result['message'] = t('Since the current translation is approved, you have to wait that this new translation will be approved');
        }
        if ($imported->addedNotAsCurrent || $imported->existingNotCurrentUntouched) {
            unset($result['current']);
        }

        return $this->app->make(ResponseFactoryInterface::class)->json($result);
    }

    /**
     * @param int $access
     * @param LocaleEntity $locale
     * @param TranslatableEntity $translatable
     * @param PackageVersionEntity $packageVersion
     *
     * @throws UserMessageException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function setTranslationFromEditor($access, LocaleEntity $locale, TranslatableEntity $translatable, UserEntity $user, PackageVersionEntity $packageVersion = null)
    {
        $translation = null;
        $strings = $this->post('translated');
        $numStrings = ($translatable->getPlural() === '') ? 1 : $locale->getPluralCount();
        if (is_array($strings)) {
            $strings = array_values($strings);
            if (count($strings) === $numStrings) {
                foreach ($strings as $index => $string) {
                    if (is_string($string)) {
                        $string = str_replace("\r", "\n", str_replace("\r\n", "\n", $string));
                    } else {
                        $string = '';
                    }
                    if (trim($string) === '') {
                        $translation = null;
                        break;
                    }
                    if ($index === 0) {
                        $translation = TranslationEntity::create($locale, $translatable, $strings[0]);
                    } else {
                        $translation->{"setText$index"}($string);
                    }
                }
            }
        }
        if ($translation === null) {
            throw new UserMessageException(t('Please specify the translations'));
        }
        if ($access >= Access::ADMIN) {
            if ($this->post('approved') === '1') {
                $approved = true;
            } elseif ($this->post('approved') === '0') {
                $approved = false;
            } else {
                throw new UserMessageException(t('Missing parameter: %s', 'approved'));
            }
        } else {
            $approved = false;
        }

        if ($user instanceof UserEntity) {
            /** @var Achievements $achievements */
            $achievements = $this->app->make(Achievements::class, ["user" => User::getByUserID($user->getUserID())]);
            $achievements->assign("translator");
        }

        $translations = $this->convertTranslationToGettext($translation, !$approved);
        $importer = $this->app->make(Importer::class);
        $imported = $importer->import($translations, $locale, $user, ($access >= Access::ADMIN) ? ImportOptions::forAdministrators() : ImportOptions::forTranslators());
        $this->getEntityManager()->clear();
        $translatable = $this->app->make(TranslatableRepository::class)->find($translatable->getID());
        $locale = $this->app->make(LocaleRepository::class)->find($locale->getID());
        if ($imported->newApprovalNeeded > 0) {
            $this->app->make(NotificationRepository::class)->translationsNeedApproval(
                $locale,
                $imported->newApprovalNeeded,
                $user->getUserID(),
                ($packageVersion === null) ? null : $packageVersion->getID()
            );
        }
        $result = $this->app->make(Editor::class)->getTranslations($locale, $translatable);
        if ($imported->newApprovalNeeded && !$imported->addedAsCurrent) {
            $result['message'] = t('Since the current translation is approved, you have to wait that this new translation will be approved');
        }

        return $this->app->make(ResponseFactoryInterface::class)->json($result);
    }

    /**
     * @param int $access
     * @param LocaleEntity $locale
     * @param TranslatableEntity $translatable
     *
     * @throws UserMessageException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function unsetTranslationFromEditor($access, LocaleEntity $locale, TranslatableEntity $translatable)
    {
        $currentTranslation = $this->app->make(TranslationRepository::class)->findOneBy([
            'locale' => $locale,
            'translatable' => $translatable,
            'current' => true,
        ]);
        if ($currentTranslation !== null) {
            /* @var TranslationEntity $currentTranslation */
            $em = $this->getEntityManager();
            if ($currentTranslation->isApproved() && $access < Access::ADMIN) {
                throw new UserMessageException(t("The current translation is marked as reviewed, so you can't remove it."));
            }
            $currentTranslation->setIsCurrent(false);
            $em->persist($currentTranslation);
            $em->flush();
            $this->app->make(StatsRepository::class)->resetForTranslation($currentTranslation);
            $result = $this->app->make(Editor::class)->getTranslations($locale, $translatable);
        } else {
            $result = [];
        }

        return $this->app->make(ResponseFactoryInterface::class)->json($result);
    }

    private function getVersionsMenu(PackageVersionEntity $packageVersion, LocaleEntity $locale)
    {
        $config = $this->app->make('community_translation/config');
        $onlineTranslationPath = $config->get('options.onlineTranslationPath');
        $urlManager = $this->app->make('url/manager');
        $result = [];
        foreach ($packageVersion->getPackage()->getSortedVersions(true, null) as $pv) {
            if ($pv === $packageVersion) {
                $url = '';
            } else {
                $url = (string) $urlManager->resolve([$onlineTranslationPath, $pv->getID(), $locale->getID()]);
            }
            $result[$url] = $pv->getDisplayVersion();
        }
        if (count($result) < 2) {
            $result = null;
        }

        return $result;
    }

    private function getLocalesMenu($packageVersionID, LocaleEntity $locale)
    {
        $accessHelper = $this->getAccessHelper();
        $config = $this->app->make('community_translation/config');
        $onlineTranslationPath = $config->get('options.onlineTranslationPath');
        $urlManager = $this->app->make('url/manager');
        $result = [];
        foreach ($this->app->make(LocaleRepository::class)->getApprovedLocales() as $l) {
            if ($accessHelper->getLocaleAccess($locale) >= Access::TRANSLATE) {
                if ($l === $locale) {
                    $url = '';
                } else {
                    $url = (string) $urlManager->resolve([$onlineTranslationPath, $packageVersionID, $l->getID()]);
                }
                $result[$url] = $l->getDisplayName();
            }
        }
        if (count($result) < 2) {
            $result = null;
        }

        return $result;
    }

    /**
     * @param TranslationEntity $translation
     * @param bool $markAsFuzzy
     *
     * @return GettextTranslations
     */
    private function convertTranslationToGettext(TranslationEntity $translation, $markAsFuzzy)
    {
        $translatable = $translation->getTranslatable();
        $locale = $translation->getLocale();
        $translations = new GettextTranslations();
        $translations->setLanguage($locale->getID());
        $t = $translations->insert($translatable->getContext(), $translatable->getText(), $translatable->getPlural());
        $t->setTranslation($translation->getText0());
        if ($translatable->getPlural() !== '') {
            switch ($locale->getPluralCount()) {
                case 6:
                    $t->setPluralTranslation($translation->getText5(), 4);
                    /* @noinspection PhpMissingBreakStatementInspection */
                case 5:
                    $t->setPluralTranslation($translation->getText4(), 3);
                    /* @noinspection PhpMissingBreakStatementInspection */
                case 4:
                    $t->setPluralTranslation($translation->getText3(), 2);
                    /* @noinspection PhpMissingBreakStatementInspection */
                case 3:
                    $t->setPluralTranslation($translation->getText2(), 1);
                    /* @noinspection PhpMissingBreakStatementInspection */
                case 2:
                    $t->setPluralTranslation($translation->getText1(), 0);
                    break;
            }
        }
        if ($markAsFuzzy) {
            $t->addFlag('fuzzy');
        }

        return $translations;
    }

    /**
     * @param PackageEntity $package
     *
     * @return PackageSubscriptionEntity
     */
    private function getPackageSubscription(PackageEntity $package)
    {
        $em = $this->getEntityManager();
        $me = $this->getAccessHelper()->getUserEntity('current');
        $repo = $this->app->make(PackageSubscriptionRepository::class);
        /* @var PackageSubscriptionRepository $repo */
        $ps = $repo->find(['user' => $me, 'package' => $package]);
        if ($ps === null) {
            $ps = PackageSubscriptionEntity::create($me, $package, false);
            $em->persist($ps);
            $em->flush($ps);
        }

        return $ps;
    }

    /**
     * @param PackageEntity $package
     *
     * @return PackageVersionSubscriptionEntity[]
     */
    private function getPackageVersionSubscriptions(PackageEntity $package)
    {
        $result = [];
        $me = $this->getAccessHelper()->getUserEntity('current');
        $repo = $this->app->make(PackageVersionSubscriptionRepository::class);
        /* @var PackageVersionSubscriptionRepository $repo */
        $pvsList = $repo->createQueryBuilder('s')
            ->innerJoin(PackageVersionEntity::class, 'pv', 'WITH', 's.packageVersion = pv.id')
            ->where('s.user = :user')->setParameter('user', $me)
            ->andWhere('pv.package = :package')->setParameter('package', $package)
            ->getQuery()
                ->execute();
        foreach ($package->getSortedVersions(true) as $packageVersion) {
            $pvs = null;
            foreach ($pvsList as $existing) {
                if ($existing->getPackageVersion() === $packageVersion) {
                    $pvs = $existing;
                    break;
                }
            }
            if ($pvs === null) {
                $pvs = PackageVersionSubscriptionEntity::create($me, $packageVersion, false);
            }
            $result[] = $pvs;
        }

        return $result;
    }
}
