<?php

/**
 * @file plugins/generic/reviewerLattes/tests/OpenIDStep2Form.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OpenIDStep2Form
 *
 * @brief A stand-in for the form of the second step of a registration through
 *        the OpenID plugin of PKP (APP\plugins\generic\openid\forms\OpenIDStep2Form),
 *        which is not part of OJS and is not installed where PKP runs the
 *        suite. It has the same short class name, so the core fires the same
 *        hooks for it (openidstep2form::…), and it does what the real form does
 *        where the plugin is concerned: it reads the same fields, tells
 *        "register" from "connect" by the button posted, creates and saves the
 *        account inside execute() and only then calls parent::execute().
 *
 *        RegistrationTest checks the source of the real form against these
 *        points wherever the OpenID plugin is installed.
 */

namespace APP\plugins\generic\reviewerLattes\tests;

use APP\facades\Repo;
use PKP\core\Core;
use PKP\facades\Locale;
use PKP\form\Form;
use PKP\security\Validation;

class OpenIDStep2Form extends Form
{
    public function __construct()
    {
        parent::__construct('authStep2.tpl');
    }

    /** The fields the real form reads, the two buttons among them. */
    public function readInputData()
    {
        parent::readInputData();
        $this->readUserVars([
            'selectedProvider', 'oauthId', 'username', 'email', 'givenName', 'familyName', 'affiliation', 'country',
            'privacyConsent', 'emailConsent', 'register', 'connect', 'usernameLogin', 'passwordLogin',
            'readerGroup', 'reviewerGroup', 'interests',
        ]);
    }

    /**
     * The account is created and saved here, as by the real form; the hook of
     * the core comes last. Linking an existing account changes nothing here.
     *
     * @return ?int the id of the account created
     */
    public function execute(...$functionArgs)
    {
        $userId = null;
        if (is_string($this->getData('register'))) {
            $locale = Locale::getLocale();
            $user = Repo::user()->newDataObject();
            $user->setUsername($this->getData('username'));
            $user->setGivenName($this->getData('givenName'), $locale);
            $user->setFamilyName($this->getData('familyName'), $locale);
            $user->setEmail($this->getData('email'));
            $user->setCountry($this->getData('country'));
            $user->setAffiliation($this->getData('affiliation'), $locale);
            $user->setDateRegistered(Core::getCurrentDate());
            $user->setInlineHelp(1);
            $user->setPassword(Validation::encryptCredentials($this->getData('username'), base64_encode(random_bytes(16))));
            $userId = Repo::user()->add($user);
        }

        parent::execute(...$functionArgs);

        return $userId;
    }
}
