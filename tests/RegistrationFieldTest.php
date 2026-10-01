<?php

/**
 * @file plugins/generic/reviewerLattes/tests/RegistrationFieldTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RegistrationFieldTest
 *
 * @brief The field on registration pages written by different themes: the
 *        page of the core, a theme with its own form (no id, no fieldsets,
 *        a textarea for the interests), and a page with no interests field;
 *        and on the second step of a registration through the OpenID plugin
 *        (ORCID), which creates an account or links an existing one.
 */

namespace APP\plugins\generic\reviewerLattes\tests;

use APP\plugins\generic\reviewerLattes\ReviewerLattesPlugin;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(ReviewerLattesPlugin::class)]
class RegistrationFieldTest extends PKPTestCase
{
    /** The reviewer part of the registration page of the core (default theme), as rendered. */
    private const CORE_PAGE = <<<'HTML'
<form class="cmp_form register" id="register" method="post" action="https://example.org/index.php/j/user/register" role="form">
<fieldset class="identity"><div class="fields"><div class="country"><label><span class="label">Country</span>
<select name="country" id="country" required><option value="BR">Brazil</option></select></label></div></div></fieldset>
<fieldset class="reviewer"><div class="fields">
<div id="reviewerOptinGroup" class="optin"><label><input type="checkbox" name="reviewerGroup[16]" value="1"> Yes, I would like to review.</label></div>
<div id="reviewerInterests" class="reviewer_interests">
<label>
<span class="label">Reviewing interests</span>
<input type="text" name="interests" id="interests" value="">
</label>
</div>
</div></fieldset>
<div class="buttons"><button class="submit" type="submit">Register</button></div>
</form>
HTML;

    /** A theme with its own form: Bootstrap groups, label beside the field, a textarea, no id on the form. */
    private const THEME_PAGE = <<<'HTML'
<form class="form-register" method="post" action="https://example.org/index.php/j/pt_BR/user/register">
<div class="form-group"><label for="country">País</label><select name="country" id="country" class="form-control"><option value="BR">Brasil</option></select></div>
<div class="form-group">
<div class="form-check"><input type="checkbox" class="form-check-input" name="reviewerGroup[16]" id="reviewerGroup-16" value="1"><label for="reviewerGroup-16" class="form-check-label">Avaliar</label></div>
</div>
<div class="form-group">
<label for="interests">Área de interesse</label>
<textarea name="interests" id="interests" class="form-control" rows="3"></textarea>
</div>
<button type="submit" class="btn btn-primary">Cadastrar</button>
</form>
HTML;

    /** A page without an interests field at all. */
    private const BARE_PAGE = <<<'HTML'
<form method="post" action="/index.php/j/user/register">
<p><input type="text" name="username"></p>
<input type="checkbox" name="reviewerGroup[16]" value="1">
<input type="submit" value="Register">
</form>
HTML;

    /**
     * The second step of a registration through the OpenID plugin (authStep2.tpl
     * of generic/openid for OJS 3.5), as rendered: the part that creates an
     * account, with the reviewer box and the interests, and the part that links
     * an existing account, each with its own submit button.
     */
    private const OPENID_PAGE = <<<'HTML'
<div class="page page_oauth">
<form class="cmp_form cmp_form oauth" id="oauth" method="post" action="https://example.org/index.php/j/openid/registerOrConnect">
<input type="hidden" name="csrfToken" value="x">
<input type="hidden" name="oauthId" id="oauthId" value="abc">
<input type="hidden" name="selectedProvider" id="selectedProvider" value="orcid">
<input type="hidden" name="returnTo" id="returnTo" value="">
<ul id="openid-choice-select"><li><span id='showLoginForm' class='step2-choice-links'>Yes</span></li><li><span id='showRegisterForm' class='step2-choice-links'>No</span></li></ul>
<div id="register-form" class="page_register">
<fieldset class="register"><div class="fields">
<div class="given_name"><label><span class="label">Given Name</span><input type="text" name="givenName" id="givenName" value="Ana" maxlength="255" required aria-required="true"></label></div>
<div class="country"><label><span class="label">Country</span><select name="country" id="country" required aria-required="true"><option></option><option value="BR">Brazil</option></select></label></div>
</div></fieldset>
<fieldset class="reviewer"><div class="fields">
<div id="reviewerOptinGroup" class="optin"><label><input type="checkbox" name="reviewerGroup[16]" class="reviewerGroupInput" value="1"> Yes, I would like to be contacted with requests to review.</label></div>
<div id="reviewerInterests" class="reviewer_interests">
<label>
<span class="label">Reviewing interests</span>
<input type="text" name="interests" id="interests" value="" class="reviewerGroupInput">
</label>
</div>
</div></fieldset>
<div class="buttons"><button class="submit" type="submit" name="register">Complete registration</button></div>
</div>
<div id="login-form">
<fieldset class="login">
<div class="username"><label><span class="label">Username or email</span><input type="text" name="usernameLogin" id="usernameLogin" value="" maxlength="32" required aria-required="true"></label></div>
<div class="password"><label><span class="label">Password</span><input type="password" name="passwordLogin" id="passwordLogin" value="" maxlength="32" required aria-required="true"></label></div>
</fieldset>
<div class="buttons"><button class="submit" type="submit" name="connect">Connect</button></div>
</div>
</form>
</div>
HTML;

    private function parts(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Lattes CV (link)',
            'description' => 'The address of your Lattes CV, for example https://lattes.cnpq.br/1234567890123456. Required for reviewers in Brazil.',
            'example' => 'https://lattes.cnpq.br/1234567890123456',
            'value' => '',
            'requiredForBrazil' => true,
            'required' => false,
            'error' => null,
            'requiredLabel' => 'Required',
        ], $overrides);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function testOnThePageOfTheCoreTheFieldStandsWithTheInterestsAndTakesTheirLook(): void
    {
        $html = ReviewerLattesPlugin::insertRegistrationField(self::CORE_PAGE, $this->parts());
        $x = $this->xpath($html);

        $inputs = $x->query('//input[@name="lattesUrl"]');
        $this->assertSame(1, $inputs->length);
        $input = $inputs->item(0);
        // Inside the block the core shows to reviewers, right after the interests label.
        $this->assertSame(1, $x->query('//div[@id="reviewerInterests"]//input[@name="lattesUrl"]')->length);
        $this->assertSame(1, $x->query('//label[.//input[@name="interests"]]/following-sibling::label[.//input[@name="lattesUrl"]]')->length);
        // Dressed like the interests label: same span.label for the text.
        $label = $x->query('//label[.//input[@name="lattesUrl"]]')->item(0);
        $this->assertStringContainsString('Lattes CV (link)', $x->query('.//span[@class="label"]', $label)->item(0)->textContent);
        $this->assertSame('1', $label->getAttribute('data-reviewer-lattes'));
        $this->assertSame('1', $label->getAttribute('data-required-for-brazil'));
        $this->assertSame('url', $input->getAttribute('inputmode'));
        $this->assertSame('https://lattes.cnpq.br/1234567890123456', $input->getAttribute('placeholder'));
        $this->assertFalse($input->hasAttribute('required'), 'Not required before the person picks Brazil and asks to review.');
        // The id of the interests block is not copied: it would exist twice.
        $this->assertSame(1, $x->query('//*[@id="reviewerInterests"]')->length);
        $this->assertSame(1, $x->query('//*[@id="lattesUrlDescription"]')->length);
    }

    public function testOnAThemeWithItsOwnFormTheFieldFollowsTheThemesGroup(): void
    {
        $html = ReviewerLattesPlugin::insertRegistrationField(self::THEME_PAGE, $this->parts());
        $x = $this->xpath($html);

        $this->assertSame(1, $x->query('//input[@name="lattesUrl"]')->length);
        // A group of its own, after the interests group, with the classes of the theme.
        $group = $x->query('//div[contains(@class,"form-group")][.//textarea[@name="interests"]]/following-sibling::div[1]')->item(0);
        $this->assertNotNull($group);
        $this->assertStringContainsString('form-group', $group->getAttribute('class'));
        $this->assertStringContainsString('reviewerLattes', $group->getAttribute('class'));
        $input = $x->query('.//input[@name="lattesUrl"]', $group)->item(0);
        $this->assertSame('form-control', $input->getAttribute('class'), 'The input keeps the class the theme gave the interests field.');
        $this->assertSame('lattesUrl', $x->query('.//label', $group)->item(0)->getAttribute('for'));
        $this->assertSame(0, $x->query('.//textarea', $group)->length, 'A single-line input, not a copy of the textarea.');
        // The interests field is still there, untouched.
        $this->assertSame(1, $x->query('//textarea[@name="interests"]')->length);
    }

    public function testWithoutAnInterestsFieldTheFieldGoesBeforeTheSubmitControl(): void
    {
        $html = ReviewerLattesPlugin::insertRegistrationField(self::BARE_PAGE, $this->parts());
        $this->assertSame(1, substr_count($html, 'name="lattesUrl"'));
        $this->assertLessThan(strpos($html, 'type="submit"'), strpos($html, 'name="lattesUrl"'));
        $this->assertStringContainsString('data-reviewer-lattes="1"', $html);
    }

    public function testTheRequiredMarkFollowsTheStateOfTheForm(): void
    {
        $x = $this->xpath(ReviewerLattesPlugin::insertRegistrationField(self::CORE_PAGE, $this->parts(['required' => true])));
        $input = $x->query('//input[@name="lattesUrl"]')->item(0);
        $this->assertTrue($input->hasAttribute('required'));
        $this->assertSame('true', $input->getAttribute('aria-required'));
        $marker = $x->query('//*[contains(@class,"reviewerLattes__required")]')->item(0);
        $this->assertNotNull($marker);
        $this->assertStringNotContainsString('display:none', (string) $marker->getAttribute('style'));

        // Not required now: the mark is there for the script, hidden.
        $x = $this->xpath(ReviewerLattesPlugin::insertRegistrationField(self::CORE_PAGE, $this->parts()));
        $marker = $x->query('//*[contains(@class,"reviewerLattes__required")]')->item(0);
        $this->assertNotNull($marker);
        $this->assertStringContainsString('display:none', $marker->getAttribute('style'));
    }

    public function testAJournalThatDoesNotRequireItSaysSoToTheScript(): void
    {
        $x = $this->xpath(ReviewerLattesPlugin::insertRegistrationField(self::THEME_PAGE, $this->parts(['requiredForBrazil' => false])));
        $this->assertSame('0', $x->query('//*[@data-reviewer-lattes]')->item(0)->getAttribute('data-required-for-brazil'));
    }

    public function testTheTypedValueAndTheErrorComeBackEscaped(): void
    {
        $evil = '"><script>alert(1)</script>';
        foreach ([self::CORE_PAGE, self::THEME_PAGE, self::BARE_PAGE] as $page) {
            $html = ReviewerLattesPlugin::insertRegistrationField($page, $this->parts(['value' => $evil, 'error' => '<b>bad</b>']));
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringNotContainsString('<b>bad</b>', $html);
            $x = $this->xpath($html);
            $this->assertSame($evil, $x->query('//input[@name="lattesUrl"]')->item(0)->getAttribute('value'));
            $this->assertSame(1, $x->query('//*[contains(@class,"error")][contains(.,"<b>bad</b>")]')->length);
        }
    }

    public function testTheFieldIsAddedOnceAndOnlyToTheRegistrationForm(): void
    {
        $once = ReviewerLattesPlugin::insertRegistrationField(self::CORE_PAGE, $this->parts());
        $this->assertSame($once, ReviewerLattesPlugin::insertRegistrationField($once, $this->parts()));

        $login = str_replace('/user/register', '/login/signIn', self::CORE_PAGE);
        $this->assertSame($login, ReviewerLattesPlugin::insertRegistrationField($login, $this->parts()));
        $this->assertSame('<p>no form</p>', ReviewerLattesPlugin::insertRegistrationField('<p>no form</p>', $this->parts()));
    }

    public function testItLivesWithTheRegistrationFieldOfWhatsAppContributor(): void
    {
        // The other house plugin that adds a field to the same page, through the
        // same hooks. Each field must appear once, whichever filter runs first.
        $other = 'APP\plugins\generic\whatsAppContributor\WhatsAppContributorPlugin';
        if (!class_exists($other)) {
            $this->markTestSkipped('whatsAppContributor is not installed here');
        }
        $theirs = [
            'name' => 'whatsapp', 'label' => 'Phone', 'example' => '+55 11 98888-7777', 'description' => 'Phone',
            'value' => '', 'required' => false, 'error' => null,
        ];

        foreach ([self::CORE_PAGE, self::THEME_PAGE] as $page) {
            $page = str_replace('<select name="country"', '<input type="text" name="affiliation" value=""><select name="country"', $page);
            $oursFirst = $other::insertRegistrationField(ReviewerLattesPlugin::insertRegistrationField($page, $this->parts()), $theirs);
            $theirsFirst = ReviewerLattesPlugin::insertRegistrationField($other::insertRegistrationField($page, $theirs), $this->parts());
            foreach ([$oursFirst, $theirsFirst] as $html) {
                $this->assertSame(1, substr_count($html, 'name="lattesUrl"'));
                $this->assertSame(1, substr_count($html, 'name="whatsapp"'));
                // Ours stays with the reviewer part, after the interests.
                $this->assertGreaterThan(strpos($html, 'name="interests"'), strpos($html, 'name="lattesUrl"'));
            }
        }
    }

    public function testOnTheOpenIdPageTheFieldStandsWithTheInterestsInThePartThatCreatesTheAccount(): void
    {
        $html = ReviewerLattesPlugin::insertRegistrationField(self::OPENID_PAGE, $this->parts());
        $x = $this->xpath($html);

        $this->assertSame(1, $x->query('//input[@name="lattesUrl"]')->length);
        // In the reviewer block of the part that creates an account, right after the interests.
        $this->assertSame(1, $x->query('//div[@id="register-form"]//div[@id="reviewerInterests"]//input[@name="lattesUrl"]')->length);
        $this->assertSame(1, $x->query('//label[.//input[@name="interests"]]/following-sibling::label[.//input[@name="lattesUrl"]]')->length);
        $this->assertSame(0, $x->query('//div[@id="login-form"]//input[@name="lattesUrl"]')->length, 'Not in the part that links an existing account.');
        // The class of the interests input, which the OpenID plugin's script leaves
        // optional when it shows the part: "required" is the plugin's to decide.
        $input = $x->query('//input[@name="lattesUrl"]')->item(0);
        $this->assertSame('reviewerGroupInput', $input->getAttribute('class'));
        $this->assertFalse($input->hasAttribute('required'));
        $this->assertSame('1', $x->query('//*[@data-reviewer-lattes]')->item(0)->getAttribute('data-required-for-brazil'));
        // Both buttons and everything else are where they were.
        $this->assertSame(1, $x->query('//button[@name="register"]')->length);
        $this->assertSame(1, $x->query('//button[@name="connect"]')->length);
        $this->assertSame(1, $x->query('//*[@id="reviewerInterests"]')->length);
    }

    public function testOnTheOpenIdPageTheRequiredMarkFollowsTheStateOfTheForm(): void
    {
        $x = $this->xpath(ReviewerLattesPlugin::insertRegistrationField(self::OPENID_PAGE, $this->parts(['required' => true, 'error' => 'Required.'])));
        $input = $x->query('//input[@name="lattesUrl"]')->item(0);
        $this->assertTrue($input->hasAttribute('required'));
        $this->assertSame(1, $x->query('//div[@id="register-form"]//*[contains(@class,"error")][contains(.,"Required.")]')->length);
    }

    public function testOnTheOpenIdPageWithoutInterestsTheFieldGoesBeforeTheButtonThatCreatesTheAccount(): void
    {
        // A journal with no reviewer group open to registration: no reviewer block at all.
        $page = preg_replace('~<fieldset class="reviewer">.*?</fieldset>~s', '', self::OPENID_PAGE);
        $this->assertStringNotContainsString('name="interests"', $page);

        $html = ReviewerLattesPlugin::insertRegistrationField($page, $this->parts());
        $this->assertSame(1, substr_count($html, 'name="lattesUrl"'));
        $field = strpos($html, 'name="lattesUrl"');
        // Before "register", not before "connect", the last button of the form.
        $this->assertLessThan(strpos($html, 'name="register"'), $field);
        $this->assertGreaterThan(strpos($html, 'id="register-form"'), $field);
        $this->assertLessThan(strpos($html, 'id="login-form"'), $field);
    }

    public function testTheOpenIdPageGetsTheFieldOnceAndNoOtherOpenIdPageGetsIt(): void
    {
        $once = ReviewerLattesPlugin::insertRegistrationField(self::OPENID_PAGE, $this->parts());
        $this->assertSame($once, ReviewerLattesPlugin::insertRegistrationField($once, $this->parts()));

        // Another page of the OpenID plugin, and the sign-in page of the core.
        foreach (['/openid/doAuthentication', '/login/signIn'] as $action) {
            $other = str_replace('/openid/registerOrConnect', $action, self::OPENID_PAGE);
            $this->assertSame($other, ReviewerLattesPlugin::insertRegistrationField($other, $this->parts()), $action);
        }
    }

    public function testALabelBesideTheFieldIsNotTakenForItsBlock(): void
    {
        // <label for="interests">…</label><textarea>: the label closes before the
        // field, so the block is the group around both.
        $page = self::THEME_PAGE;
        $at = strpos($page, '<textarea');
        $block = ReviewerLattesPlugin::fieldBlock($page, $at, strlen($page));
        $this->assertNotNull($block);
        $html = substr($page, $block[0], $block[1] - $block[0]);
        $this->assertStringStartsWith('<div class="form-group">', $html);
        $this->assertStringContainsString('<label for="interests">', $html);
        $this->assertStringEndsWith('</div>', $html);
    }
}
