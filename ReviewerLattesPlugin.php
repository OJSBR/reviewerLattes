<?php

/**
 * @file plugins/generic/reviewerLattes/ReviewerLattesPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewerLattesPlugin
 *
 * @ingroup plugins_generic_reviewerLattes
 *
 * @brief Asks people who sign up as reviewers for the link to their Lattes CV
 *        (the CV platform of CNPq, Brazil), and stores it as the URL of the
 *        account: on the registration form, on the registration through the
 *        OpenID plugin (ORCID and others) and when an existing account picks
 *        its roles in the profile. Each journal decides who must give it —
 *        nobody, reviewers in Brazil, or every reviewer — and may keep
 *        students (by the e-mail address they use) from signing up to review.
 *
 *        The registration template has no hook, so the field is added to the
 *        rendered page by a named output filter, modelled on the reviewer
 *        interests field the theme itself wrote, and the page shows it only to
 *        those who tick the reviewer box.
 */

namespace APP\plugins\generic\reviewerLattes;

use APP\core\Application;
use APP\facades\Repo;
use DOMDocument;
use DOMElement;
use PKP\core\JSONMessage;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCustom;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\template\PKPTemplateManager;
use PKP\user\User;

class ReviewerLattesPlugin extends GenericPlugin
{
    /** The name of the field on the registration form. */
    public const FIELD = 'lattesUrl';

    /** Where the registration form of the core posts to. */
    public const REGISTER_ACTION = '/user/register';

    /** Where the second step of the OpenID plugin (registration through ORCID and others) posts to. */
    public const OPENID_ACTION = '/openid/registerOrConnect';

    /** Who must give the link: one of the SCOPE_ values. */
    public const SETTING_SCOPE = 'requiredScope';

    /** The link is always optional. */
    public const SCOPE_NONE = 'none';

    /** Required from reviewers in Brazil, optional for everyone else. */
    public const SCOPE_BRAZIL = 'brazil';

    /** Required from every reviewer, from any country. */
    public const SCOPE_ALL = 'all';

    public const SCOPES = [self::SCOPE_NONE, self::SCOPE_BRAZIL, self::SCOPE_ALL];

    /**
     * The setting of 1.0.x: whether reviewers in Brazil must give the link. Read
     * where the journal never saved a scope, as SCOPE_BRAZIL when on.
     */
    public const SETTING_REQUIRED = 'requiredForBrazil';

    /**
     * Pieces of e-mail address that mark a student, one per line ("@aluno.").
     * Empty — the default — lets everyone sign up to review.
     */
    public const SETTING_STUDENT_PATTERNS = 'studentEmailPatterns';

    /** The country, as the registration form posts it, the requirement applies to. */
    public const BRAZIL = 'BR';

    /** The Lattes ID: 16 digits, the one in lattes.cnpq.br/<id>. */
    public const LATTES_ID = '\d{16}';

    /**
     * The older identifier of a CV, still found in links of the CV search:
     * "K", 7 digits, a letter and a digit (K4723925J6).
     */
    public const LATTES_K_ID = 'K\d{7}[A-Z]\d';

    /**
     * Register the plugin and its hooks.
     *
     * The plugin acts only on a web page of a journal (the registration form),
     * so the hooks are added where it is enabled and nowhere else.
     *
     * @param string $category
     * @param string $path
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return $success;
        }

        // The names of the hooks of the old forms are not written the same way:
        // Form::__construct() and Form::display() lowercase the class name only,
        // while readUserVars() and execute() lowercase the whole name. A name in
        // the wrong case is not an error anywhere — the hook simply never runs —
        // so these are taken from Form.php as they are fired there.
        Hook::add('registrationform::Constructor', $this->addRegistrationChecks(...));
        Hook::add('registrationform::readuservars', $this->readRegistrationField(...));
        Hook::add('registrationform::display', $this->addRegistrationField(...));
        Hook::add('registrationform::execute', $this->saveRegistrationField(...));

        // Registration through the OpenID plugin (ORCID and other providers): its
        // second step, in "register" mode. "connect" links an existing account.
        Hook::add('openidstep2form::Constructor', $this->addOpenIdChecks(...));
        Hook::add('openidstep2form::readuservars', $this->readRegistrationField(...));
        Hook::add('openidstep2form::display', $this->addOpenIdField(...));
        Hook::add('openidstep2form::execute', $this->saveOpenIdField(...));

        // Profile → Roles: an existing account that becomes a reviewer.
        Hook::add('rolesform::Constructor', $this->addRolesChecks(...));
        Hook::add('rolesform::readuservars', $this->readRegistrationField(...));
        Hook::add('rolesform::display', $this->addRolesField(...));
        Hook::add('rolesform::execute', $this->saveRolesField(...));

        return $success;
    }

    /**
     * Name shown in the plugins list.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.reviewerLattes.displayName');
    }

    /**
     * Description shown in the plugins list.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.reviewerLattes.description');
    }

    /**
     * Add the settings action to the plugin entry in the plugins list.
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        // The settings belong to a journal; there is nothing to set up for the site.
        if (!$request->getContext() || !$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));

        return $actions;
    }

    /**
     * Show and save the settings form.
     */
    public function manage($args, $request): JSONMessage
    {
        $context = $request->getContext();
        if ($request->getUserVar('verb') !== 'settings' || !$context) {
            return parent::manage($args, $request);
        }

        $form = new ReviewerLattesSettingsForm($this, (int) $context->getId());
        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                return new JSONMessage(true);
            }
        } else {
            $form->initData();
        }

        return new JSONMessage(true, $form->fetch($request));
    }

    //
    // The Lattes link
    //

    /**
     * The address of a Lattes CV in its standard form, from what a person may
     * paste: the address with or without "http(s)://" and "www.", the address
     * of the CV search (buscatextual.cnpq.br) with the ID in the query, or the
     * ID alone. Returns null for anything that is not one of these, and for an
     * empty value.
     *
     * The ID is checked for its shape only: CNPq answers every ID with a
     * redirect and asks for a captcha before saying whether the CV exists.
     */
    public static function normalizeLattesUrl($raw): ?string
    {
        $value = trim(preg_replace('/\s+/u', '', (string) $raw) ?? '');
        if ($value === '') {
            return null;
        }

        // The ID alone.
        if (preg_match('/^(' . self::LATTES_ID . ')$/', $value, $match)) {
            return self::lattesUrl($match[1]);
        }
        if (preg_match('/^(' . self::LATTES_K_ID . ')$/i', $value, $match)) {
            return self::lattesUrl(strtoupper($match[1]));
        }

        if (!preg_match('~^(?:https?://)?(?:www\.)?([a-z0-9.-]+)(/[^?#]*)?(?:\?([^#]*))?(?:#.*)?$~i', $value, $parts)) {
            return null;
        }
        $host = strtolower($parts[1]);
        $path = $parts[2] ?? '';
        parse_str($parts[3] ?? '', $query);

        // lattes.cnpq.br/1234567890123456
        if ($host === 'lattes.cnpq.br' && preg_match('~^/(' . self::LATTES_ID . ')/?$~', $path, $match)) {
            return self::lattesUrl($match[1]);
        }

        // buscatextual.cnpq.br/buscatextual/{cv|visualizacv.do|visualizacv.jsp}?id=…
        if ($host === 'buscatextual.cnpq.br' && preg_match('~^/buscatextual/(?:cv|visualizacv\.do|visualizacv\.jsp)/?$~i', $path)) {
            $id = is_string($query['id'] ?? null) ? $query['id'] : '';
            if (preg_match('/^' . self::LATTES_ID . '$/', $id)) {
                return self::lattesUrl($id);
            }
            if (preg_match('/^' . self::LATTES_K_ID . '$/i', $id)) {
                return self::lattesUrl(strtoupper($id));
            }
        }

        return null;
    }

    /**
     * The address a Lattes ID is published under: lattes.cnpq.br for the
     * 16-digit ID, the CV search for the older K identifier.
     */
    public static function lattesUrl(string $id): string
    {
        return preg_match('/^' . self::LATTES_ID . '$/', $id)
            ? 'https://lattes.cnpq.br/' . $id
            : 'https://buscatextual.cnpq.br/buscatextual/visualizacv.do?id=' . $id;
    }

    /**
     * Whether the link is required from this person: the person asks to review,
     * and the scope of the journal covers them — every reviewer, or reviewers
     * in Brazil when Brazil is their country.
     *
     * @param string $scope one of the SCOPE_ values
     * @param mixed $country the country of the person (posted, or of the account)
     * @param mixed $reviewerGroups the reviewer boxes ticked ([userGroupId => 1])
     */
    public static function isRequiredFor(string $scope, $country, $reviewerGroups): bool
    {
        if (!is_array($reviewerGroups) || count(array_filter($reviewerGroups)) === 0) {
            return false;
        }

        return match ($scope) {
            self::SCOPE_ALL => true,
            self::SCOPE_BRAZIL => strtoupper(trim((string) $country)) === self::BRAZIL,
            default => false,
        };
    }

    /**
     * The scope a journal has: what it saved, or — where it never saved one —
     * what the setting of 1.0.x meant (on: reviewers in Brazil; off or never
     * saved: nobody).
     *
     * @param mixed $saved the saved scope, if any
     * @param mixed $legacy the saved requiredForBrazil of 1.0.x, if any
     */
    public static function scopeFrom($saved, $legacy): string
    {
        if (in_array($saved, self::SCOPES, true)) {
            return $saved;
        }

        return $legacy ? self::SCOPE_BRAZIL : self::SCOPE_NONE;
    }

    /**
     * The pieces of e-mail address of a setting: one per line, trimmed, without
     * empty lines and repeats.
     *
     * @return string[]
     */
    public static function parsePatterns($text): array
    {
        $patterns = [];
        foreach (preg_split('/\R/u', (string) $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array(mb_strtolower($line), array_map('mb_strtolower', $patterns), true)) {
                $patterns[] = $line;
            }
        }

        return $patterns;
    }

    /**
     * Whether an e-mail address contains one of the pieces, regardless of case.
     * Each piece is matched as written: it is escaped, never read as a regular
     * expression.
     *
     * @param string[] $patterns
     */
    public static function isStudentEmail($email, array $patterns): bool
    {
        $email = trim((string) $email);
        if ($email === '' || !$patterns) {
            return false;
        }
        $pieces = array_map(fn (string $pattern) => preg_quote($pattern, '/'), $patterns);

        return preg_match('/' . implode('|', $pieces) . '/iu', $email) === 1;
    }

    /**
     * The reviewer boxes of a post that are ticked.
     *
     * @return array<int, string> [userGroupId => value]
     */
    public static function tickedGroups($reviewerGroups): array
    {
        return is_array($reviewerGroups) ? array_filter($reviewerGroups) : [];
    }

    //
    // Registration
    //

    /**
     * The journal of the request, when the plugin is enabled in it.
     */
    public function currentContextId(): ?int
    {
        $context = Application::get()->getRequest()->getContext();

        return $context && $this->getEnabled($context->getId()) ? (int) $context->getId() : null;
    }

    /**
     * Who the journal requires the link from (see scopeFrom()).
     */
    public function requiredScope(int $contextId): string
    {
        return self::scopeFrom($this->getSetting($contextId, self::SETTING_SCOPE), $this->getSetting($contextId, self::SETTING_REQUIRED));
    }

    /**
     * The pieces of e-mail address that keep students from reviewing in the
     * journal; empty where it lets everyone review.
     *
     * @return string[]
     */
    public function studentPatterns(int $contextId): array
    {
        return self::parsePatterns($this->getSetting($contextId, self::SETTING_STUDENT_PATTERNS));
    }

    /**
     * Hook: registrationform::Constructor — the link, when given, must be a
     * Lattes CV; and it must be given when the journal requires it from this
     * person.
     *
     * @param array $args [$form, &$template]
     */
    public function addRegistrationChecks($hookName, $args): bool
    {
        $form = $args[0];
        $contextId = $this->currentContextId();
        if (!$form instanceof Form || $contextId === null) {
            return Hook::CONTINUE;
        }

        $form->addCheck(new FormValidatorCustom(
            $form,
            self::FIELD,
            'optional',
            'plugins.generic.reviewerLattes.field.invalid',
            fn ($value) => self::normalizeLattesUrl($value) !== null
        ));

        // "required" so the check also runs on an empty field; whether it is
        // required depends on the country and the reviewer boxes of the post.
        $scope = $this->requiredScope($contextId);
        $form->addCheck(new FormValidatorCustom(
            $form,
            self::FIELD,
            'required',
            self::requiredMessageKey($scope),
            fn ($value) => trim((string) $value) !== ''
                || !self::isRequiredFor($scope, $form->getData('country'), $form->getData('reviewerGroup'))
        ));

        // A student, by the e-mail address typed, may not ask to review.
        $patterns = $this->studentPatterns($contextId);
        if ($patterns) {
            $form->addCheck(new FormValidatorCustom(
                $form,
                'reviewerGroup',
                'required',
                'plugins.generic.reviewerLattes.student.error',
                fn ($groups) => !self::tickedGroups($groups) || !self::isStudentEmail($form->getData('email'), $patterns)
            ));
        }

        return Hook::CONTINUE;
    }

    /**
     * The message for an empty field, by who it is required from.
     */
    public static function requiredMessageKey(string $scope): string
    {
        return $scope === self::SCOPE_ALL
            ? 'plugins.generic.reviewerLattes.field.requiredAll'
            : 'plugins.generic.reviewerLattes.field.required';
    }

    /**
     * Hook: registrationform::readuservars and rolesform::readuservars (the
     * core lowercases the whole name)
     *
     * @param array $args [$form, &$vars]
     */
    public function readRegistrationField($hookName, $args): bool
    {
        if ($this->currentContextId() !== null) {
            $vars = &$args[1];
            $vars[] = self::FIELD;
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook: registrationform::display — add the script that shows the field to
     * reviewers and marks it when required, its small stylesheet, and the
     * output filter that puts the field on the page.
     *
     * @param array $args [$form, &$output]
     */
    public function addRegistrationField($hookName, $args): bool
    {
        $form = $args[0];
        $contextId = $this->currentContextId();
        if (!$form instanceof Form || $contextId === null) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $templateMgr = PKPTemplateManager::getManager($request);
        $base = $request->getBaseUrl() . '/' . $this->getPluginPath();
        $stamp = '?v=' . urlencode($this->assetVersion());
        $templateMgr->addJavaScript('reviewerLattes', $base . '/js/reviewerLattes.js' . $stamp, ['contexts' => ['frontend']]);
        $templateMgr->addStyleSheet('reviewerLattes', $base . '/css/reviewerLattes.css' . $stamp, ['contexts' => ['frontend']]);

        $parts = self::registrationFieldParts($form, $this->requiredScope($contextId), $this->studentPatterns($contextId));
        // Named: Smarty calls every closure filter "closure", so an unnamed one would replace, or be
        // replaced by, the output filter of another plugin in the same request.
        $templateMgr->registerFilter('output', fn (string $output): string => self::insertRegistrationField($output, $parts), 'reviewerLattesRegistrationField');

        return Hook::CONTINUE;
    }

    /**
     * The version in the address of the plugin's files. PKP appends
     * ?v={application version} to URLs without a query, which does not change
     * when only the plugin is updated.
     */
    public function assetVersion(): string
    {
        $version = $this->getCurrentVersion();

        return $version ? $version->getVersionString() : '0';
    }

    /**
     * The pieces of the registration field, which are then dressed with the
     * markup of the theme.
     *
     * @param string[] $studentPatterns
     *
     * @return array{label: string, description: string, example: string, value: string, scope: string, required: bool, error: ?string, requiredLabel: string, studentPatterns: string[], studentNotice: string, userCountry: string}
     */
    public static function registrationFieldParts(Form $form, string $scope, array $studentPatterns = []): array
    {
        $errors = $form->getErrorsArray();

        return self::fieldParts(
            $scope,
            (string) $form->getData(self::FIELD),
            // As the page is first drawn; the script keeps it in step with the form.
            self::isRequiredFor($scope, $form->getData('country'), $form->getData('reviewerGroup')),
            $errors[self::FIELD] ?? null,
            $studentPatterns
        );
    }

    /**
     * The pieces of the field, wherever it is shown.
     *
     * @param string[] $studentPatterns
     */
    public static function fieldParts(string $scope, string $value, bool $required, ?string $error, array $studentPatterns = [], string $userCountry = '', array $currentReviewerGroups = []): array
    {
        $example = __('plugins.generic.reviewerLattes.field.example');
        $description = __('plugins.generic.reviewerLattes.field.description', ['example' => $example]);
        if ($scope === self::SCOPE_BRAZIL) {
            $description .= ' ' . __('plugins.generic.reviewerLattes.field.requiredForBrazil');
        } elseif ($scope === self::SCOPE_ALL) {
            $description .= ' ' . __('plugins.generic.reviewerLattes.field.requiredForAll');
        }

        return [
            'label' => __('plugins.generic.reviewerLattes.field.label'),
            'description' => $description,
            'example' => $example,
            'value' => $value,
            'scope' => $scope,
            'required' => $required,
            'error' => $error,
            'requiredLabel' => __('common.required'),
            'studentPatterns' => array_values($studentPatterns),
            'studentNotice' => __('plugins.generic.reviewerLattes.student.notice'),
            'userCountry' => $userCountry,
            'currentReviewerGroups' => array_values(array_map('intval', $currentReviewerGroups)),
        ];
    }

    /**
     * The attributes that hand the rule to the script.
     *
     * @return array<string, string>
     */
    public static function scriptAttributes(array $parts): array
    {
        $attributes = [
            'data-reviewer-lattes' => '1',
            'data-required-scope' => $parts['scope'],
        ];
        if ($parts['userCountry'] !== '') {
            $attributes['data-user-country'] = $parts['userCountry'];
        }
        if (!empty($parts['currentReviewerGroups'])) {
            // In the profile: the reviewer groups the account is already in, whose
            // boxes do not make the link required (the rule is for those joining).
            $attributes['data-current-reviewer-groups'] = json_encode($parts['currentReviewerGroups']);
        }
        if ($parts['studentPatterns']) {
            $attributes['data-student-patterns'] = json_encode($parts['studentPatterns'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $attributes['data-student-notice'] = $parts['studentNotice'];
        }

        return $attributes;
    }

    /**
     * Put the field on the registration page, once.
     *
     * The page belongs to the theme, so nothing of the core is taken for
     * granted. The form is found by where it posts to, which no theme changes;
     * the field is then built from the markup of the reviewer interests field
     * the theme wrote — the same wrapper, the same classes, the same shape of
     * label — and put right after it, inside the part of the page that belongs
     * to reviewers. Where the theme has no interests field, the markup of the
     * core is used and the field goes before the control that sends the form.
     */
    public static function insertRegistrationField(string $output, array $parts, string $action = self::REGISTER_ACTION): string
    {
        if (preg_match('/<input\b[^>]*\bname="' . self::FIELD . '"/', $output)) {
            return $output;
        }
        if (!preg_match('~<form\b[^>]*\baction="[^"]*' . preg_quote($action, '~') . '[^"]*"[^>]*>~i', $output, $match, PREG_OFFSET_CAPTURE)) {
            return $output;
        }
        $formStart = $match[0][1];
        $formEnd = strpos($output, '</form>', $formStart);
        if ($formEnd === false) {
            return $output;
        }
        $inForm = substr($output, $formStart, $formEnd - $formStart);

        // Dressed like the interests field of the theme, and standing beside it.
        if (preg_match('/<(?:input|textarea)\b[^>]*\bname="interests"/i', $inForm, $found, PREG_OFFSET_CAPTURE)) {
            $block = self::fieldBlock($output, $formStart + $found[0][1], $formEnd);
            if ($block) {
                $dressed = self::dressLikeTheme(substr($output, $block[0], $block[1] - $block[0]), $parts);
                if ($dressed !== null) {
                    return substr_replace($output, $dressed, $block[1], 0);
                }
            }
        }

        // Nothing to model it on: before the control that sends the form — the
        // "register" one where the form has two (the OpenID step also connects).
        $field = self::renderRegistrationField($parts);
        if (preg_match('~<(?:button|input)\b[^>]*\bname="register"~i', $inForm, $register, PREG_OFFSET_CAPTURE)) {
            return substr_replace($output, $field, $formStart + $register[0][1], 0);
        }
        if (preg_match_all('~<(?:button|input)\b[^>]*\btype="submit"~i', $inForm, $submits, PREG_OFFSET_CAPTURE)) {
            $last = end($submits[0]);

            return substr_replace($output, $field, $formStart + $last[1], 0);
        }

        return substr_replace($output, $field, $formEnd, 0);
    }

    /**
     * The smallest block of markup that holds a field: from the opening tag of
     * the nearest element that encloses it to its matching close. A label that
     * closes before the field (a label beside the field, not around it) does
     * not count.
     *
     * @return ?array{0: int, 1: int} where the block starts and ends
     */
    public static function fieldBlock(string $html, int $inputAt, int $limit): ?array
    {
        $before = substr($html, 0, $inputAt);
        $candidates = [];
        foreach (['label', 'div', 'li', 'p'] as $tag) {
            if (preg_match_all('/<' . $tag . '\b/i', $before, $all, PREG_OFFSET_CAPTURE)) {
                foreach ($all[0] as [, $at]) {
                    $candidates[$at] = $tag;
                }
            }
        }
        krsort($candidates);

        foreach ($candidates as $start => $tag) {
            $end = self::matchingClose($html, $start, $tag, $limit);
            if ($end !== null && $end > $inputAt) {
                return [$start, $end];
            }
        }

        return null;
    }

    /**
     * Where the element opened at $start ends (just after its closing tag),
     * counting the elements of the same name opened in between.
     */
    private static function matchingClose(string $html, int $start, string $tag, int $limit): ?int
    {
        $depth = 0;
        $at = $start;
        while ($at < $limit) {
            $open = preg_match('/<' . $tag . '\b/i', $html, $o, PREG_OFFSET_CAPTURE, $at + 1) ? $o[0][1] : false;
            $close = stripos($html, '</' . $tag, $at + 1);
            if ($close === false || $close > $limit) {
                return null;
            }
            if ($open !== false && $open < $close) {
                $depth++;
                $at = $open;
                continue;
            }
            if ($depth === 0) {
                $end = strpos($html, '>', $close);

                return $end === false ? null : $end + 1;
            }
            $depth--;
            $at = $close;
        }

        return null;
    }

    /**
     * The block of the interests field, adapted to this one: its wrapper and
     * its classes are kept, the label takes our text, the field becomes a
     * single-line input with our attributes, and the description goes where
     * the theme puts one.
     */
    public static function dressLikeTheme(string $model, array $parts): ?string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $model, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $wrapper = $loaded ? $document->documentElement : null;
        if (!$wrapper) {
            return null;
        }
        $control = null;
        foreach (['input', 'textarea'] as $tag) {
            foreach ($wrapper->getElementsByTagName($tag) as $element) {
                if ($element->getAttribute('name') === 'interests') {
                    $control = $element;
                    break 2;
                }
            }
        }
        if (!$control) {
            return null;
        }

        // A single-line input, with the classes the theme gave the model.
        $input = $document->createElement('input');
        if ($control->hasAttribute('class')) {
            $input->setAttribute('class', $control->getAttribute('class'));
        }
        $input->setAttribute('type', 'text');
        $input->setAttribute('inputmode', 'url');
        $input->setAttribute('name', self::FIELD);
        $input->setAttribute('id', self::FIELD);
        $input->setAttribute('value', (string) $parts['value']);
        $input->setAttribute('maxlength', '255');
        $input->setAttribute('autocomplete', 'url');
        $input->setAttribute('spellcheck', 'false');
        $input->setAttribute('placeholder', $parts['example']);
        $input->setAttribute('aria-describedby', self::FIELD . 'Description');
        if ($parts['required']) {
            $input->setAttribute('required', 'required');
            $input->setAttribute('aria-required', 'true');
        }
        $control->parentNode->replaceChild($input, $control);

        // The wrapper keeps its layout classes; what names the interests field
        // (and the core hides until the reviewer box is ticked) is ours now.
        if ($wrapper->hasAttribute('id')) {
            $wrapper->removeAttribute('id');
        }
        $classes = [];
        foreach (preg_split('/\s+/', trim($wrapper->getAttribute('class'))) ?: [] as $class) {
            if ($class !== '' && stripos($class, 'interest') === false) {
                $classes[] = $class;
            }
        }
        $classes[] = 'reviewerLattes';
        $wrapper->setAttribute('class', implode(' ', $classes));
        foreach (self::scriptAttributes($parts) as $name => $value) {
            $wrapper->setAttribute($name, $value);
        }

        // The label: ours, pointing at our input, with the mark the script shows when required.
        $label = $wrapper->getElementsByTagName('label')->item(0) ?? ($wrapper->tagName === 'label' ? $wrapper : null);
        if ($label) {
            if ($label->hasAttribute('for')) {
                $label->setAttribute('for', self::FIELD);
            }
            self::replaceLabelText($document, $label, $parts['label']);
            $textHolder = self::labelTextHolder($label);
            $textHolder->appendChild(self::requiredMarker($document, $parts));
        }

        // The description: where the theme has one, or right after the input.
        $description = null;
        foreach ($wrapper->getElementsByTagName('*') as $element) {
            if (str_contains(strtolower($element->getAttribute('class')), 'description')) {
                $description = $element;
                break;
            }
        }
        if (!$description) {
            $description = $document->createElement('small');
            $description->setAttribute('class', 'description');
            $input->parentNode->insertBefore($description, $input->nextSibling);
        }
        while ($description->firstChild) {
            $description->removeChild($description->firstChild);
        }
        $description->setAttribute('id', self::FIELD . 'Description');
        $description->appendChild($document->createTextNode($parts['description']));

        if (!empty($parts['error'])) {
            $error = $document->createElement('span');
            $error->setAttribute('class', 'error');
            $error->appendChild($document->createTextNode($parts['error']));
            $wrapper->appendChild($error);
        }

        $html = $document->saveHTML($wrapper);

        return $html === false ? null : $html;
    }

    /**
     * The markup of the field when the page gives nothing to model it on: the
     * shape the pages of the core use.
     */
    public static function renderRegistrationField(array $parts): string
    {
        $e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        $marker = '<span class="required reviewerLattes__required"' . ($parts['required'] ? '' : ' style="display:none"') . '>'
            . '<span aria-hidden="true">*</span><span class="pkp_screen_reader">' . $e($parts['requiredLabel']) . '</span></span>';

        $attributes = '';
        foreach (self::scriptAttributes($parts) as $name => $value) {
            $attributes .= ' ' . $name . '="' . $e($value) . '"';
        }

        return '<div class="reviewerLattes"' . $attributes . '>'
            . '<label><span class="label">' . $e($parts['label']) . ' ' . $marker . '</span>'
            . '<input type="text" inputmode="url" name="' . self::FIELD . '" id="' . self::FIELD . '" value="' . $e($parts['value']) . '" maxlength="255" autocomplete="url" spellcheck="false"'
            . ' placeholder="' . $e($parts['example']) . '" aria-describedby="' . self::FIELD . 'Description"' . ($parts['required'] ? ' required aria-required="true"' : '') . '></label>'
            . '<div class="description" id="' . self::FIELD . 'Description">' . $e($parts['description']) . '</div>'
            . ($parts['error'] ? '<span class="error">' . $e($parts['error']) . '</span>' : '')
            . '</div>';
    }

    /**
     * The mark of a required field, as the pages of the core write it. Hidden
     * unless the field is required now; the script shows it when the person
     * picks Brazil and asks to review.
     */
    private static function requiredMarker(DOMDocument $document, array $parts): DOMElement
    {
        $marker = $document->createElement('span');
        $marker->setAttribute('class', 'required reviewerLattes__required');
        if (!$parts['required']) {
            $marker->setAttribute('style', 'display:none');
        }
        $star = $document->createElement('span', '*');
        $star->setAttribute('aria-hidden', 'true');
        $reader = $document->createElement('span');
        $reader->setAttribute('class', 'pkp_screen_reader');
        $reader->appendChild($document->createTextNode($parts['requiredLabel']));
        $marker->appendChild($document->createTextNode(' '));
        $marker->appendChild($star);
        $marker->appendChild($reader);

        return $marker;
    }

    /**
     * The element of a label that holds its text: the span the pages of the
     * core use, or the label itself.
     */
    private static function labelTextHolder(DOMElement $label): DOMElement
    {
        foreach ($label->getElementsByTagName('span') as $span) {
            if (str_contains(strtolower($span->getAttribute('class')), 'label')) {
                return $span;
            }
        }

        return $label;
    }

    /**
     * The text of a label, wherever the theme keeps it: directly inside the
     * label or inside the span the pages of the core use.
     */
    private static function replaceLabelText(DOMDocument $document, DOMElement $label, string $text): void
    {
        $holder = self::labelTextHolder($label);
        foreach ($holder->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE && trim($node->nodeValue) !== '') {
                $node->nodeValue = $text;

                return;
            }
        }
        $holder->insertBefore($document->createTextNode($text), $holder->firstChild);
    }

    /**
     * Hook: registrationform::execute — store the link, in its standard form,
     * as the URL of the new account, before the core adds it.
     *
     * @param array $args [$form, ...]
     */
    public function saveRegistrationField($hookName, $args): bool
    {
        $form = $args[0];
        if (!$form instanceof Form || !isset($form->user) || $this->currentContextId() === null) {
            return Hook::CONTINUE;
        }

        $url = self::normalizeLattesUrl($form->getData(self::FIELD));
        if ($url !== null) {
            $form->user->setUrl($url);
        }

        return Hook::CONTINUE;
    }

    //
    // Registration through the OpenID plugin
    //

    /**
     * Whether the second step of the OpenID plugin is creating an account: its
     * "register" button was pressed. The "connect" button links an account that
     * already exists, which is left alone.
     */
    public static function isOpenIdRegistration(Form $form): bool
    {
        return is_string($form->getData('register'));
    }

    /**
     * Hook: openidstep2form::Constructor — the rules of the registration form,
     * for an account created through the OpenID plugin.
     *
     * @param array $args [$form, &$template]
     */
    public function addOpenIdChecks($hookName, $args): bool
    {
        $form = $args[0];
        $contextId = $this->currentContextId();
        if (!$form instanceof Form || $contextId === null) {
            return Hook::CONTINUE;
        }

        $form->addCheck(new FormValidatorCustom(
            $form,
            self::FIELD,
            'optional',
            'plugins.generic.reviewerLattes.field.invalid',
            fn ($value) => !self::isOpenIdRegistration($form) || self::normalizeLattesUrl($value) !== null
        ));

        $scope = $this->requiredScope($contextId);
        $form->addCheck(new FormValidatorCustom(
            $form,
            self::FIELD,
            'required',
            self::requiredMessageKey($scope),
            fn ($value) => !self::isOpenIdRegistration($form)
                || trim((string) $value) !== ''
                || !self::isRequiredFor($scope, $form->getData('country'), $form->getData('reviewerGroup'))
        ));

        $patterns = $this->studentPatterns($contextId);
        if ($patterns) {
            $form->addCheck(new FormValidatorCustom(
                $form,
                'reviewerGroup',
                'required',
                'plugins.generic.reviewerLattes.student.error',
                fn ($groups) => !self::isOpenIdRegistration($form)
                    || !self::tickedGroups($groups)
                    || !self::isStudentEmail($form->getData('email'), $patterns)
            ));
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook: openidstep2form::display — the field, modelled on the interests
     * field of the page as on the registration form; the form is found by
     * where it posts to (…/openid/registerOrConnect).
     *
     * @param array $args [$form, &$output]
     */
    public function addOpenIdField($hookName, $args): bool
    {
        $form = $args[0];
        $contextId = $this->currentContextId();
        if (!$form instanceof Form || $contextId === null) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $templateMgr = PKPTemplateManager::getManager($request);
        $base = $request->getBaseUrl() . '/' . $this->getPluginPath();
        $stamp = '?v=' . urlencode($this->assetVersion());
        $templateMgr->addJavaScript('reviewerLattes', $base . '/js/reviewerLattes.js' . $stamp, ['contexts' => ['frontend']]);
        $templateMgr->addStyleSheet('reviewerLattes', $base . '/css/reviewerLattes.css' . $stamp, ['contexts' => ['frontend']]);

        $parts = self::registrationFieldParts($form, $this->requiredScope($contextId), $this->studentPatterns($contextId));
        $templateMgr->registerFilter('output', fn (string $output): string => self::insertRegistrationField($output, $parts, self::OPENID_ACTION), 'reviewerLattesOpenIdField');

        return Hook::CONTINUE;
    }

    /**
     * Hook: openidstep2form::execute — the OpenID plugin has created and saved
     * the account by then; it is found by the username of the form and the link
     * becomes its URL, unless it already has a Lattes link.
     *
     * @param array $args [$form, ...]
     */
    public function saveOpenIdField($hookName, $args): bool
    {
        $form = $args[0];
        if (!$form instanceof Form || !self::isOpenIdRegistration($form) || $this->currentContextId() === null) {
            return Hook::CONTINUE;
        }
        $url = self::normalizeLattesUrl($form->getData(self::FIELD));
        $username = trim((string) $form->getData('username'));
        if ($url === null || $username === '') {
            return Hook::CONTINUE;
        }
        $user = Repo::user()->getByUsername($username, true);
        if (!$user instanceof User || self::hasLattes($user)) {
            return Hook::CONTINUE;
        }

        Repo::user()->edit($user, ['url' => $url]);

        return Hook::CONTINUE;
    }

    //
    // Profile → Roles
    //

    /**
     * The reviewer groups of the journal an account is joining with this post:
     * ticked, open to self-registration, of the journal of the request, and
     * not yet the account's. Someone who already reviews and saves the tab for
     * another reason is not stopped by a rule meant for those who sign up now.
     *
     * @return array<int, string> [userGroupId => value]
     */
    public function newReviewerGroups(User $user, int $contextId, $reviewerGroups): array
    {
        $ticked = self::tickedGroups($reviewerGroups);
        if (!$ticked) {
            return [];
        }
        $new = [];
        foreach (Repo::userGroup()->getByRoleIds([Role::ROLE_ID_REVIEWER], $contextId) as $userGroup) {
            $groupId = (int) $userGroup->id;
            if ($userGroup->permitSelfRegistration && isset($ticked[$groupId]) && !Repo::userGroup()->userInGroup((int) $user->getId(), $groupId)) {
                $new[$groupId] = $ticked[$groupId];
            }
        }

        return $new;
    }

    /**
     * Whether an account already has a Lattes link as its URL.
     */
    public static function hasLattes(?User $user): bool
    {
        return $user !== null && self::normalizeLattesUrl($user->getUrl()) !== null;
    }

    /**
     * Hook: rolesform::Constructor — the same rule as on the registration form,
     * for an account that becomes a reviewer: the country and the e-mail are
     * those of the account, and an account that already has a Lattes link is
     * not asked again.
     *
     * @param array $args [$form, &$template]
     */
    public function addRolesChecks($hookName, $args): bool
    {
        $form = $args[0];
        $contextId = $this->currentContextId();
        if (!$form instanceof Form || !method_exists($form, 'getUser') || $contextId === null) {
            return Hook::CONTINUE;
        }
        // The account is read when the form is checked: BaseProfileForm keeps it
        // only after Form::__construct(), where this hook is fired.
        $account = fn (): ?User => ($user = $form->getUser()) instanceof User ? $user : null;

        $form->addCheck(new FormValidatorCustom(
            $form,
            self::FIELD,
            'optional',
            'plugins.generic.reviewerLattes.field.invalid',
            fn ($value) => self::normalizeLattesUrl($value) !== null
        ));

        $scope = $this->requiredScope($contextId);
        $form->addCheck(new FormValidatorCustom(
            $form,
            self::FIELD,
            'required',
            self::requiredMessageKey($scope),
            fn ($value) => trim((string) $value) !== ''
                || !($user = $account())
                || self::hasLattes($user)
                || !self::isRequiredFor($scope, $user->getCountry(), $this->newReviewerGroups($user, $contextId, $form->getData('reviewerGroup')))
        ));

        $patterns = $this->studentPatterns($contextId);
        if ($patterns) {
            $form->addCheck(new FormValidatorCustom(
                $form,
                'reviewerGroup',
                'required',
                'plugins.generic.reviewerLattes.student.error',
                fn ($groups) => !($user = $account())
                    || !self::isStudentEmail($user->getEmail(), $patterns)
                    || !$this->newReviewerGroups($user, $contextId, $groups)
            ));
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook: rolesform::display — fired by fetch(), which builds the Roles tab of
     * the profile. The tab arrives in an AJAX response, where the assets of the
     * page are not loaded again, so the script goes inside the form.
     *
     * @param array $args [$form, &$output]
     */
    public function addRolesField($hookName, $args): bool
    {
        $form = $args[0];
        $contextId = $this->currentContextId();
        if (!$form instanceof Form || !method_exists($form, 'getUser') || $contextId === null) {
            return Hook::CONTINUE;
        }
        $user = $form->getUser();
        if (!$user instanceof User) {
            return Hook::CONTINUE;
        }

        $patterns = $this->studentPatterns($contextId);
        $isStudent = self::isStudentEmail($user->getEmail(), $patterns);
        $errors = $form->getErrorsArray();
        $reviewerGroupIds = $currentGroupIds = [];
        foreach (Repo::userGroup()->getByRoleIds([Role::ROLE_ID_REVIEWER], $contextId) as $userGroup) {
            $reviewerGroupIds[] = (int) $userGroup->id;
            if (Repo::userGroup()->userInGroup((int) $user->getId(), (int) $userGroup->id)) {
                $currentGroupIds[] = (int) $userGroup->id;
            }
        }
        // An account with a Lattes link is not asked again.
        $parts = self::hasLattes($user) ? null : self::fieldParts(
            $this->requiredScope($contextId),
            (string) $form->getData(self::FIELD),
            false,
            $errors[self::FIELD] ?? null,
            [],
            strtoupper((string) $user->getCountry()),
            $currentGroupIds
        );
        $request = Application::get()->getRequest();
        $script = $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/reviewerLattes.js?v=' . urlencode($this->assetVersion());
        $notice = $isStudent ? __('plugins.generic.reviewerLattes.student.notice') : null;

        $templateMgr = PKPTemplateManager::getManager($request);
        $templateMgr->registerFilter(
            'output',
            fn (string $output): string => self::insertRolesField($output, $parts, $script, $notice, $reviewerGroupIds),
            'reviewerLattesRolesField'
        );

        return Hook::CONTINUE;
    }

    /**
     * Put the field, the student notice and the script in the Roles tab.
     *
     * The tab is a form of the back end, which themes do not rewrite, so the
     * markup of the core is a firm anchor: the field goes at the end of the
     * "userGroups" fieldset, right after the reviewing interests, in the shape
     * of the fields of the core forms. The form is found by its id and by where
     * it posts to (…/save-roles); without the fieldset the field goes before
     * the required-fields note, then before the buttons, then at the end.
     *
     * A student's reviewer boxes that are not ticked are disabled: a disabled
     * box is not sent, and the core takes a role away when its box is not
     * sent, so a box already ticked is left alone and the server decides.
     *
     * @param ?array $parts the field, or null when the account already has a Lattes link
     * @param ?string $studentNotice the notice, when the account is a student's
     * @param int[] $reviewerGroupIds the reviewer groups of the journal
     */
    public static function insertRolesField(string $output, ?array $parts, string $scriptUrl, ?string $studentNotice, array $reviewerGroupIds = []): string
    {
        if (!preg_match('~<form\b[^>]*\bid="rolesForm"[^>]*>|<form\b[^>]*\baction="[^"]*/save-roles[^"]*"[^>]*>~i', $output, $match, PREG_OFFSET_CAPTURE)) {
            return $output;
        }
        if (str_contains($output, 'data-reviewer-lattes-roles')) {
            return $output;
        }
        $formStart = $match[0][1];
        $formEnd = strpos($output, '</form>', $formStart);
        if ($formEnd === false) {
            return $output;
        }
        $e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');

        // The student: unticked reviewer boxes of the journal disabled, with the notice.
        if ($studentNotice !== null) {
            $form = substr($output, $formStart, $formEnd - $formStart);
            $form = preg_replace_callback('~<input\b[^>]*\bname="reviewerGroup\[(\d+)\]"[^>]*>~i', function (array $box) use ($reviewerGroupIds) {
                if (($reviewerGroupIds && !in_array((int) $box[1], $reviewerGroupIds, true)) || preg_match('/\bchecked\b/i', $box[0]) || preg_match('/\bdisabled\b/i', $box[0])) {
                    return $box[0];
                }

                return preg_replace('~\s*/?>$~', ' disabled="disabled" data-reviewer-lattes-student="1"$0', $box[0]);
            }, $form) ?? $form;
            $form = preg_replace(
                '~(<input\b[^>]*data-reviewer-lattes-student="1"[^>]*>.*?</li>)~is',
                '$1<li class="reviewerLattes__studentNotice" role="note">' . $e($studentNotice) . '</li>',
                $form,
                1
            ) ?? $form;
            $output = substr_replace($output, $form, $formStart, $formEnd - $formStart);
            $formEnd = strpos($output, '</form>', $formStart);
        }

        $field = ($parts ? self::renderRolesField($parts) : '')
            . '<script src="' . $e($scriptUrl) . '" data-reviewer-lattes-roles="1"></script>';

        $inForm = substr($output, $formStart, $formEnd - $formStart);
        foreach ([
            '~<fieldset\b[^>]*\bid="userGroups"[^>]*>.*?(</fieldset>)~is',
            '~(<p>\s*<span class="formRequired">)~i',
            '~(<div\b[^>]*class="[^"]*formButtons[^"]*")~i',
        ] as $pattern) {
            if (preg_match($pattern, $inForm, $found, PREG_OFFSET_CAPTURE)) {
                return substr_replace($output, $field, $formStart + $found[1][1], 0);
            }
        }

        return substr_replace($output, $field, $formEnd, 0);
    }

    /**
     * The field in the shape of the fields of the core forms of the back end.
     */
    public static function renderRolesField(array $parts): string
    {
        $e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        $attributes = '';
        foreach (self::scriptAttributes($parts) as $name => $value) {
            $attributes .= ' ' . $name . '="' . $e($value) . '"';
        }
        $marker = '<span class="req reviewerLattes__required" style="display:none">*</span>';

        return '<div class="section reviewerLattes"' . $attributes . '>'
            . '<input type="text" class="field text" inputmode="url" name="' . self::FIELD . '" id="' . self::FIELD . '" value="' . $e($parts['value']) . '"'
            . ' maxlength="255" autocomplete="url" spellcheck="false" placeholder="' . $e($parts['example']) . '" aria-describedby="' . self::FIELD . 'Description">'
            . '<span><label class="sub_label" for="' . self::FIELD . '">' . $e($parts['label']) . ' ' . $marker . '</label></span>'
            . '<div class="description" id="' . self::FIELD . 'Description">' . $e($parts['description']) . '</div>'
            . ($parts['error'] ? '<span class="error">' . $e($parts['error']) . '</span>' : '')
            . '</div>';
    }

    /**
     * Hook: rolesform::execute — after the core saved the roles, store the
     * link as the URL of the account, unless the account already has a Lattes
     * link there: an existing one is never overwritten. Any other URL (a
     * personal page) gives way to the Lattes link, as the field asks for.
     *
     * @param array $args [$form, ...]
     */
    public function saveRolesField($hookName, $args): bool
    {
        $form = $args[0];
        if (!$form instanceof Form || !method_exists($form, 'getUser') || $this->currentContextId() === null) {
            return Hook::CONTINUE;
        }
        $user = $form->getUser();
        $url = self::normalizeLattesUrl($form->getData(self::FIELD));
        if (!$user instanceof User || $url === null || self::hasLattes($user)) {
            return Hook::CONTINUE;
        }

        Repo::user()->edit($user, ['url' => $url]);
        // Right after this hook BaseProfileForm::execute() saves the user of the
        // request as it is in memory, which would put the old URL back: the
        // link goes on that object too (in a real request it is the same one).
        $user->setUrl($url);
        $requestUser = Application::get()->getRequest()->getUser();
        if ($requestUser instanceof User && $requestUser !== $user && (int) $requestUser->getId() === (int) $user->getId()) {
            $requestUser->setUrl($url);
        }

        return Hook::CONTINUE;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\reviewerLattes\ReviewerLattesPlugin', '\ReviewerLattesPlugin');
}
