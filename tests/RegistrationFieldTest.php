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
 *        and in the Roles tab of the profile.
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

    private function parts(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Lattes CV (link)',
            'description' => 'The address of your Lattes CV, for example https://lattes.cnpq.br/1234567890123456. Required for reviewers in Brazil.',
            'example' => 'https://lattes.cnpq.br/1234567890123456',
            'value' => '',
            'scope' => 'brazil',
            'required' => false,
            'error' => null,
            'requiredLabel' => 'Required',
            'studentPatterns' => [],
            'studentNotice' => 'Students cannot sign up to review.',
            'userCountry' => '',
            'currentReviewerGroups' => [],
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
        $this->assertSame('brazil', $label->getAttribute('data-required-scope'));
        $this->assertFalse($label->hasAttribute('data-student-patterns'), 'No student rule where the journal has none.');
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

    public function testTheScopeAndTheStudentRuleGoToTheScript(): void
    {
        foreach (['none', 'brazil', 'all'] as $scope) {
            foreach ([self::CORE_PAGE, self::THEME_PAGE, self::BARE_PAGE] as $page) {
                $x = $this->xpath(ReviewerLattesPlugin::insertRegistrationField($page, $this->parts(['scope' => $scope])));
                $this->assertSame($scope, $x->query('//*[@data-reviewer-lattes]')->item(0)->getAttribute('data-required-scope'));
            }
        }

        $patterns = ['@aluno.', '"><b>'];
        $x = $this->xpath(ReviewerLattesPlugin::insertRegistrationField(self::CORE_PAGE, $this->parts(['studentPatterns' => $patterns])));
        $field = $x->query('//*[@data-reviewer-lattes]')->item(0);
        $this->assertSame($patterns, json_decode($field->getAttribute('data-student-patterns'), true), 'The pieces reach the script as written, escaped in the attribute.');
        $this->assertSame('Students cannot sign up to review.', $field->getAttribute('data-student-notice'));
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

    /** The Roles tab of the profile, as the core renders it (3.5). */
    private const ROLES_TAB = <<<'HTML'
<form class="pkp_form" id="rolesForm" method="post" action="https://example.org/index.php/j/$$$call$$$/tab/user/profile-tab/save-roles" enctype="multipart/form-data">
<fieldset id="userGroups" class="pkp_formArea border"><legend>Roles</legend>
<div class="section"><ul class="checkbox_and_radiobutton">
<li><label><input type="checkbox" id="readerGroup-17" name="readerGroup[17]" class="field checkbox"/> Reader</label></li>
<li><label><input type="checkbox" id="reviewerGroup-16" name="reviewerGroup[16]" class="field checkbox"/> Reviewer</label></li>
<li><label><input type="checkbox" id="reviewerGroup-99" name="reviewerGroup[99]" class="field checkbox"/> Reviewer of another journal</label></li>
</ul></div>
<div class="section"><div id="interests"><ul class="interests"></ul><span><label class="sub_label" for="interests">Reviewing interests</label></span></div></div>
</fieldset>
<p><span class="formRequired">Required fields are marked with an asterisk</span></p>
<div class="section formButtons form_buttons"><button class="pkp_button submitFormButton" type="submit">Save</button></div>
</form>
HTML;

    public function testInTheRolesTabTheFieldEndsTheRolesBlockWithItsScript(): void
    {
        $html = ReviewerLattesPlugin::insertRolesField(self::ROLES_TAB, $this->parts(['scope' => 'all', 'userCountry' => 'PT']), 'https://example.org/plugins/generic/reviewerLattes/js/reviewerLattes.js?v=1.1.0.0', null, [16]);
        $x = $this->xpath($html);

        $this->assertSame(1, $x->query('//input[@name="lattesUrl"]')->length);
        // Inside the roles block, after the interests: the same place as on the core page.
        $this->assertSame(1, $x->query('//fieldset[@id="userGroups"]/div[contains(@class,"section")][.//*[@id="interests"]]/following-sibling::div[contains(@class,"reviewerLattes")][.//input[@name="lattesUrl"]]')->length);
        $field = $x->query('//*[@data-reviewer-lattes]')->item(0);
        $this->assertSame('all', $field->getAttribute('data-required-scope'));
        $this->assertSame('PT', $field->getAttribute('data-user-country'), 'The country of the account, for the script.');
        // The script comes with the tab: the tab arrives in an AJAX response.
        $this->assertSame(1, $x->query('//script[@data-reviewer-lattes-roles][contains(@src,"js/reviewerLattes.js?v=1.1.0.0")]')->length);
        // Nothing disabled for someone who is not a student.
        $this->assertSame(0, $x->query('//input[@disabled]')->length);
        $this->assertSame($html, ReviewerLattesPlugin::insertRolesField($html, $this->parts(), 'x.js', null), 'Added once.');
    }

    public function testInRolesTheScriptKnowsTheGroupsTheAccountIsAlreadyIn(): void
    {
        $x = $this->xpath(ReviewerLattesPlugin::insertRolesField(self::ROLES_TAB, $this->parts(['currentReviewerGroups' => [16]]), 'x.js', null, [16]));
        $this->assertSame('[16]', $x->query('//*[@data-reviewer-lattes]')->item(0)->getAttribute('data-current-reviewer-groups'));
        $x = $this->xpath(ReviewerLattesPlugin::insertRolesField(self::ROLES_TAB, $this->parts(), 'x.js', null, [16]));
        $this->assertFalse($x->query('//*[@data-reviewer-lattes]')->item(0)->hasAttribute('data-current-reviewer-groups'));
    }

    public function testAnAccountWithALattesLinkGetsNoFieldInRoles(): void
    {
        $html = ReviewerLattesPlugin::insertRolesField(self::ROLES_TAB, null, 'x.js', null, [16]);
        $this->assertStringNotContainsString('name="lattesUrl"', $html);
        $this->assertStringContainsString('data-reviewer-lattes-roles', $html);
    }

    public function testAStudentCannotTickTheReviewerBoxesOfTheJournalInRoles(): void
    {
        $html = ReviewerLattesPlugin::insertRolesField(self::ROLES_TAB, $this->parts(), 'x.js', 'Students cannot sign up to review.', [16]);
        $x = $this->xpath($html);

        $this->assertTrue($x->query('//input[@name="reviewerGroup[16]"]')->item(0)->hasAttribute('disabled'));
        // Other boxes, and reviewer boxes of other journals, are left alone.
        $this->assertFalse($x->query('//input[@name="readerGroup[17]"]')->item(0)->hasAttribute('disabled'));
        $this->assertFalse($x->query('//input[@name="reviewerGroup[99]"]')->item(0)->hasAttribute('disabled'));
        $this->assertSame(1, $x->query('//li[contains(@class,"reviewerLattes__studentNotice")]')->length);
        $this->assertSame('Students cannot sign up to review.', trim($x->query('//li[contains(@class,"reviewerLattes__studentNotice")]')->item(0)->textContent));
    }

    public function testAStudentAlreadyReviewingKeepsTheBoxTicked(): void
    {
        // A disabled box is not sent, and the core ends a role whose box is not
        // sent: a ticked box must stay as it is.
        $ticked = str_replace('name="reviewerGroup[16]" class="field checkbox"/>', 'name="reviewerGroup[16]" class="field checkbox" checked="checked"/>', self::ROLES_TAB);
        $this->assertNotSame($ticked, self::ROLES_TAB);
        $x = $this->xpath(ReviewerLattesPlugin::insertRolesField($ticked, $this->parts(), 'x.js', 'Students cannot sign up to review.', [16]));
        $box = $x->query('//input[@name="reviewerGroup[16]"]')->item(0);
        $this->assertFalse($box->hasAttribute('disabled'));
        $this->assertTrue($box->hasAttribute('checked'));
    }

    public function testInRolesWithoutTheRolesBlockTheFieldFallsBackInOrder(): void
    {
        $noFieldset = preg_replace('~<fieldset\b.*?</fieldset>~s', '<ul><li><input type="checkbox" name="reviewerGroup[16]"></li></ul>', self::ROLES_TAB);
        $html = ReviewerLattesPlugin::insertRolesField($noFieldset, $this->parts(), 'x.js', null);
        $this->assertLessThan(strpos($html, 'class="formRequired"'), strpos($html, 'name="lattesUrl"'), 'before the required-fields note');

        $noNote = preg_replace('~<p><span class="formRequired">.*?</p>~s', '', $noFieldset);
        $html = ReviewerLattesPlugin::insertRolesField($noNote, $this->parts(), 'x.js', null);
        $this->assertLessThan(strpos($html, 'formButtons'), strpos($html, 'name="lattesUrl"'), 'then before the buttons');

        $this->assertSame('<form id="other"></form>', ReviewerLattesPlugin::insertRolesField('<form id="other"></form>', $this->parts(), 'x.js', null), 'not on another form');
    }

    public function testTheRolesFieldIsEscaped(): void
    {
        $html = ReviewerLattesPlugin::insertRolesField(self::ROLES_TAB, $this->parts(['value' => '"><script>alert(1)</script>', 'error' => '<b>bad</b>']), 'x.js?a=1&b="2"', '<i>notice</i>', [16]);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>bad</b>', $html);
        $this->assertStringNotContainsString('<i>notice</i>', $html);
        $this->assertStringContainsString('src="x.js?a=1&amp;b=&quot;2&quot;"', $html);
    }

    /** The second step of the OpenID plugin 5.x (registration through ORCID), as rendered. */
    private const OPENID_PAGE = <<<'HTML'
<form class="cmp_form cmp_form oauth" id="oauth" method="post" action="https://example.org/index.php/j/openid/registerOrConnect">
<fieldset class="register"><div class="fields"><div class="email"><label><span class="label">Email</span><input type="email" name="email" id="email" value=""></label></div>
<div class="country"><label><select name="country" id="country"><option value="BR">Brazil</option></select></label></div></div></fieldset>
<fieldset class="reviewer"><div class="fields">
<div id="reviewerOptinGroup" class="optin"><label><input type="checkbox" name="reviewerGroup[16]" class="reviewerGroupInput" value="1"> Yes, review</label></div>
<div id="reviewerInterests" class="reviewer_interests"><label><span class="label">Reviewing interests</span><input type="text" name="interests" id="interests" value="" class="reviewerGroupInput"></label></div>
</div></fieldset>
<div class="buttons"><button class="submit" type="submit" name="register">Register</button></div>
<fieldset class="login"><input type="text" name="usernameLogin"><input type="password" name="passwordLogin"></fieldset>
<div class="buttons"><button class="submit" type="submit" name="connect">Connect</button></div>
</form>
HTML;

    public function testOnTheOpenIdStepTheFieldStandsWithTheInterests(): void
    {
        $html = ReviewerLattesPlugin::insertRegistrationField(self::OPENID_PAGE, $this->parts(), ReviewerLattesPlugin::OPENID_ACTION);
        $x = $this->xpath($html);
        $this->assertSame(1, $x->query('//input[@name="lattesUrl"]')->length);
        $this->assertSame(1, $x->query('//div[@id="reviewerInterests"]//input[@name="lattesUrl"]')->length);
        // The class that hooks the inputs of the reviewer block in the OpenID page is kept.
        $this->assertSame('reviewerGroupInput', $x->query('//input[@name="lattesUrl"]')->item(0)->getAttribute('class'));

        // The registration form of the core is not the OpenID step, and the other way round.
        $this->assertSame(self::OPENID_PAGE, ReviewerLattesPlugin::insertRegistrationField(self::OPENID_PAGE, $this->parts()));
        $this->assertSame(self::CORE_PAGE, ReviewerLattesPlugin::insertRegistrationField(self::CORE_PAGE, $this->parts(), ReviewerLattesPlugin::OPENID_ACTION));
    }

    public function testOnTheOpenIdStepWithoutInterestsTheFieldGoesBeforeRegisterNotConnect(): void
    {
        $page = preg_replace('~<div id="reviewerInterests".*?</div>~s', '', self::OPENID_PAGE);
        $html = ReviewerLattesPlugin::insertRegistrationField($page, $this->parts(), ReviewerLattesPlugin::OPENID_ACTION);
        $this->assertSame(1, substr_count($html, 'name="lattesUrl"'));
        $this->assertLessThan(strpos($html, 'name="register"'), strpos($html, 'name="lattesUrl"'));
        $this->assertLessThan(strpos($html, 'name="connect"'), strpos($html, 'name="lattesUrl"'));
        $this->assertLessThan(strpos($html, 'name="usernameLogin"'), strpos($html, 'name="lattesUrl"'), 'with the registration, not with the login of connect');
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
