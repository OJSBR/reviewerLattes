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
 * @brief Asks people who register as reviewers for the link to their Lattes CV
 *        (the CV platform of CNPq, Brazil), and stores it as the URL of the
 *        account. A journal may require it from reviewers in Brazil; anyone
 *        else may give it, but it is never required from them.
 *
 *        The registration template has no hook, so the field is added to the
 *        rendered page by a named output filter, modelled on the reviewer
 *        interests field the theme itself wrote, and the page shows it only to
 *        those who tick the reviewer box.
 */

namespace APP\plugins\generic\reviewerLattes;

use APP\core\Application;
use DOMDocument;
use DOMElement;
use PKP\core\JSONMessage;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCustom;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\template\PKPTemplateManager;

class ReviewerLattesPlugin extends GenericPlugin
{
    /** The name of the field on the registration form. */
    public const FIELD = 'lattesUrl';

    /** Whether reviewers in Brazil must give the link (off where never saved). */
    public const SETTING_REQUIRED = 'requiredForBrazil';

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
     * Whether the link is required from this person: the journal requires it,
     * the person is in Brazil and asked to review.
     *
     * @param mixed $country the country posted by the form
     * @param mixed $reviewerGroups the reviewer boxes posted by the form ([userGroupId => 1])
     */
    public static function isRequiredFor(bool $requiredForBrazil, $country, $reviewerGroups): bool
    {
        return $requiredForBrazil
            && strtoupper(trim((string) $country)) === self::BRAZIL
            && is_array($reviewerGroups)
            && count(array_filter($reviewerGroups)) > 0;
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
     * Whether the journal requires the link from reviewers in Brazil. Off where
     * the setting was never saved; whatever was saved counts, and off stays off
     * whether the database gives back '' or '0'.
     */
    public function requiresForBrazil(int $contextId): bool
    {
        return (bool) $this->getSetting($contextId, self::SETTING_REQUIRED);
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
        $requiredForBrazil = $this->requiresForBrazil($contextId);
        $form->addCheck(new FormValidatorCustom(
            $form,
            self::FIELD,
            'required',
            'plugins.generic.reviewerLattes.field.required',
            fn ($value) => trim((string) $value) !== ''
                || !self::isRequiredFor($requiredForBrazil, $form->getData('country'), $form->getData('reviewerGroup'))
        ));

        return Hook::CONTINUE;
    }

    /**
     * Hook: registrationform::readuservars (the core lowercases the whole name)
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

        $parts = self::registrationFieldParts($form, $this->requiresForBrazil($contextId));
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
     * @return array{label: string, description: string, example: string, value: string, requiredForBrazil: bool, required: bool, error: ?string, requiredLabel: string}
     */
    public static function registrationFieldParts(Form $form, bool $requiredForBrazil): array
    {
        $errors = $form->getErrorsArray();
        $example = __('plugins.generic.reviewerLattes.field.example');
        $description = __('plugins.generic.reviewerLattes.field.description', ['example' => $example]);
        if ($requiredForBrazil) {
            $description .= ' ' . __('plugins.generic.reviewerLattes.field.requiredForBrazil');
        }

        return [
            'label' => __('plugins.generic.reviewerLattes.field.label'),
            'description' => $description,
            'example' => $example,
            'value' => (string) $form->getData(self::FIELD),
            'requiredForBrazil' => $requiredForBrazil,
            // As the page is first drawn; the script keeps it in step with the form.
            'required' => self::isRequiredFor($requiredForBrazil, $form->getData('country'), $form->getData('reviewerGroup')),
            'error' => $errors[self::FIELD] ?? null,
            'requiredLabel' => __('common.required'),
        ];
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
    public static function insertRegistrationField(string $output, array $parts): string
    {
        if (preg_match('/<input\b[^>]*\bname="' . self::FIELD . '"/', $output)) {
            return $output;
        }
        if (!preg_match('~<form\b[^>]*\baction="[^"]*/user/register[^"]*"[^>]*>~i', $output, $match, PREG_OFFSET_CAPTURE)) {
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

        // Nothing to model it on: before the control that sends the form.
        $field = self::renderRegistrationField($parts);
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
        $wrapper->setAttribute('data-reviewer-lattes', '1');
        $wrapper->setAttribute('data-required-for-brazil', $parts['requiredForBrazil'] ? '1' : '0');

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

        return '<div class="reviewerLattes" data-reviewer-lattes="1" data-required-for-brazil="' . ($parts['requiredForBrazil'] ? '1' : '0') . '">'
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
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\reviewerLattes\ReviewerLattesPlugin', '\ReviewerLattesPlugin');
}
