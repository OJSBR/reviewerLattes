<?php

/**
 * @file plugins/generic/reviewerLattes/ReviewerLattesSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewerLattesSettingsForm
 *
 * @ingroup plugins_generic_reviewerLattes
 *
 * @brief Who must give the Lattes link in a journal, and which e-mail
 *        addresses mark a student, who may not sign up to review.
 */

namespace APP\plugins\generic\reviewerLattes;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class ReviewerLattesSettingsForm extends Form
{
    /** The longest piece of e-mail address, and how many of them. */
    public const MAX_PATTERN_LENGTH = 100;
    public const MAX_PATTERNS = 50;

    public function __construct(private ReviewerLattesPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorCustom($this, 'requiredScope', 'required', 'form.invalid', fn ($value) => in_array($value, ReviewerLattesPlugin::SCOPES, true)));
        $this->addCheck(new FormValidatorCustom($this, 'studentEmailPatterns', 'optional', 'plugins.generic.reviewerLattes.settings.student.invalid', fn ($value) => self::arePatterns((string) $value)));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /** Whether a list of pieces is within the limits. */
    public static function arePatterns(string $text): bool
    {
        $patterns = ReviewerLattesPlugin::parsePatterns($text);
        if (count($patterns) > self::MAX_PATTERNS) {
            return false;
        }
        foreach ($patterns as $pattern) {
            if (mb_strlen($pattern) > self::MAX_PATTERN_LENGTH) {
                return false;
            }
        }

        return true;
    }

    public function initData(): void
    {
        $this->setData('requiredScope', $this->plugin->requiredScope($this->contextId));
        $this->setData('studentEmailPatterns', implode("\n", $this->plugin->studentPatterns($this->contextId)));
    }

    public function readInputData(): void
    {
        $this->readUserVars(['requiredScope', 'studentEmailPatterns']);
    }

    public function fetch($request, $template = null, $display = false)
    {
        TemplateManager::getManager($request)->assign([
            'pluginName' => $this->plugin->getName(),
            'requiredScopeOptions' => [
                ReviewerLattesPlugin::SCOPE_NONE => 'plugins.generic.reviewerLattes.settings.scope.none',
                ReviewerLattesPlugin::SCOPE_BRAZIL => 'plugins.generic.reviewerLattes.settings.scope.brazil',
                ReviewerLattesPlugin::SCOPE_ALL => 'plugins.generic.reviewerLattes.settings.scope.all',
            ],
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * Only the scope is written: the requiredForBrazil of 1.0.x is no longer
     * read once a scope is saved, so it is left as it is (the plugin never
     * writes to plugin_settings other than through updateSetting()).
     */
    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting($this->contextId, ReviewerLattesPlugin::SETTING_SCOPE, (string) $this->getData('requiredScope'), 'string');
        $this->plugin->updateSetting(
            $this->contextId,
            ReviewerLattesPlugin::SETTING_STUDENT_PATTERNS,
            implode("\n", ReviewerLattesPlugin::parsePatterns($this->getData('studentEmailPatterns'))),
            'string'
        );

        return parent::execute(...$functionArgs);
    }
}
