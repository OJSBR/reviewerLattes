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
 * @brief Whether a journal requires the Lattes link from reviewers in Brazil.
 */

namespace APP\plugins\generic\reviewerLattes;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class ReviewerLattesSettingsForm extends Form
{
    /** The choices of the form, as posted. */
    public const OPTIONAL = 'optional';
    public const REQUIRED = 'required';

    public function __construct(private ReviewerLattesPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorCustom($this, 'lattesForBrazil', 'required', 'form.invalid', fn ($value) => in_array($value, [self::OPTIONAL, self::REQUIRED], true)));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData(): void
    {
        $this->setData('lattesForBrazil', $this->plugin->requiresForBrazil($this->contextId) ? self::REQUIRED : self::OPTIONAL);
    }

    public function readInputData(): void
    {
        $this->readUserVars(['lattesForBrazil']);
    }

    public function fetch($request, $template = null, $display = false)
    {
        TemplateManager::getManager($request)->assign([
            'pluginName' => $this->plugin->getName(),
            'lattesForBrazilOptions' => [
                self::OPTIONAL => 'plugins.generic.reviewerLattes.settings.optional',
                self::REQUIRED => 'plugins.generic.reviewerLattes.settings.required',
            ],
        ]);

        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting($this->contextId, ReviewerLattesPlugin::SETTING_REQUIRED, $this->getData('lattesForBrazil') === self::REQUIRED, 'bool');

        return parent::execute(...$functionArgs);
    }
}
