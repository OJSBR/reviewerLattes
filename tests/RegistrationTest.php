<?php

/**
 * @file plugins/generic/reviewerLattes/tests/RegistrationTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RegistrationTest
 *
 * @brief The registration form of the core, posted and saved, with the hooks of
 *        the plugin fired the way the core fires them: the rule is applied by
 *        the core's own validation, and the link is read back from the account
 *        the core created.
 *
 *        The captcha of the installation, when it is on, is left as it is: its
 *        error is the only one not looked at, and the account is saved through
 *        the form's own execute(), as the handler does after validating.
 *
 *        The second step of a registration through the OpenID plugin (ORCID)
 *        is posted the same way, through a stand-in with the name of its form
 *        (OpenIDStep2Form here): the OpenID plugin is not part of OJS, so the
 *        source of the real form is checked against the stand-in wherever the
 *        plugin is installed.
 */

namespace APP\plugins\generic\reviewerLattes\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\reviewerLattes\ReviewerLattesPlugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\context\Context;
use PKP\core\PKPRequest;
use PKP\form\Form;
use PKP\plugins\Hook;
use PKP\plugins\PluginSettingsDAO;
use PKP\security\Role;
use PKP\tests\PKPTestCase;
use PKP\user\form\RegistrationForm;

#[CoversClass(ReviewerLattesPlugin::class)]
class RegistrationTest extends PKPTestCase
{
    private const LIVE_SITE_USERS = 5000;

    private static ?ReviewerLattesPlugin $plugin = null;
    private static ?Context $context = null;
    private static ?int $reviewerGroupId = null;
    private static array $savedSettings = [];

    private array $createdUserIds = [];

    /** The journal and its reviewer group open to registration, found once. */
    private static function findJournal(): void
    {
        if (self::$context) {
            return;
        }
        $contextDao = Application::getContextDAO();
        self::$context = $contextDao->getById((int) DB::table($contextDao->tableName)->min($contextDao->primaryKeyColumn));
        if (!self::$context) {
            return;
        }
        // As the registration form of the core finds the groups it offers.
        $group = Repo::userGroup()->getByRoleIds([Role::ROLE_ID_REVIEWER], self::$context->getId())
            ->first(fn ($userGroup) => (bool) $userGroup->permitSelfRegistration);
        self::$reviewerGroupId = $group ? (int) $group->id : null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::findJournal();
        if (!self::$context) {
            $this->markTestSkipped('there is no journal');
        }
        if (!self::$reviewerGroupId) {
            $this->markTestSkipped('the journal has no reviewer group open to registration');
        }
        if (DB::table('users')->count() > self::LIVE_SITE_USERS) {
            $this->markTestSkipped('this installation looks like a live site');
        }

        $this->mockRequest('index/user/register');
        $this->pinContext();
        Mail::fake();

        $contextId = (int) self::$context->getId();
        if (!self::$plugin) {
            // Registered once for the class: a hook added twice would answer twice.
            self::$plugin = new ReviewerLattesPlugin();
            foreach (['enabled', ReviewerLattesPlugin::SETTING_REQUIRED] as $name) {
                self::$savedSettings[$name] = self::$plugin->getSetting($contextId, $name);
            }
            self::$plugin->updateSetting($contextId, 'enabled', true, 'bool');
            $root = dirname(__DIR__);
            self::$plugin->register('generic', 'plugins/generic/' . basename($root), $contextId);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUserIds as $userId) {
            if ($user = Repo::user()->get($userId, true)) {
                Repo::user()->delete($user);
            }
        }
        $this->createdUserIds = [];
        Application::get()->getRequest()->_requestVars = null;
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$plugin && self::$context) {
            $contextId = (int) self::$context->getId();
            foreach (self::$savedSettings as $name => $value) {
                if ($value === null) {
                    self::forgetSetting($contextId, $name);
                } else {
                    self::$plugin->updateSetting($contextId, $name, $value, 'bool');
                }
            }
        }
        self::$plugin = null;
        parent::tearDownAfterClass();
    }

    public function testTheHooksAreTheOnesTheCoreFires(): void
    {
        // The old forms fire some hooks with the whole name lowercased and others
        // with the class name only lowercased; the wrong case never runs.
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/lib/pkp/classes/form/Form.php');
        $this->assertStringContainsString("strtolower(end(\$classNameParts)) . '::Constructor'", $source, 'Constructor keeps its case');
        $this->assertStringContainsString("strtolower(end(\$classNameParts)) . '::display'", $source, 'display keeps its case');
        $this->assertStringContainsString("strtolower(end(\$classNameParts) . '::readUserVars')", $source, 'readUserVars is fired lowercased');
        $this->assertStringContainsString("strtolower(end(\$classNameParts) . '::execute')", $source, 'execute is fired lowercased');
        $hooks = [];
        foreach (['registrationform', 'openidstep2form'] as $form) {
            foreach (['Constructor', 'readuservars', 'display', 'execute'] as $name) {
                $hooks[] = "{$form}::{$name}";
            }
        }
        foreach ($hooks as $hook) {
            $this->assertNotEmpty(Hook::getHooks($hook), "{$hook} has no listener");
        }
    }

    public function testABrazilianReviewerMustGiveTheLinkWhereTheJournalRequiresIt(): void
    {
        $this->requireForBrazil(true);
        $errors = $this->validate($this->post(['country' => 'BR', 'lattesUrl' => '']));
        $this->assertSame([__('plugins.generic.reviewerLattes.field.required')], $errors);
    }

    public function testAnotherCountryNeverHasToGiveIt(): void
    {
        $this->requireForBrazil(true);
        $this->assertSame([], $this->validate($this->post(['country' => 'PT', 'lattesUrl' => ''])));
    }

    public function testAPersonWhoDoesNotAskToReviewNeverHasToGiveIt(): void
    {
        $this->requireForBrazil(true);
        $vars = $this->post(['country' => 'BR', 'lattesUrl' => '']);
        unset($vars['reviewerGroup']);
        $this->assertSame([], $this->validate($vars));
    }

    public function testWhereTheJournalDoesNotRequireItABrazilianMayLeaveItEmpty(): void
    {
        $this->requireForBrazil(false);
        $this->assertSame([], $this->validate($this->post(['country' => 'BR', 'lattesUrl' => ''])));
    }

    public function testALinkThatIsNotLattesIsRefusedFromAnyone(): void
    {
        $this->requireForBrazil(false);
        foreach (['BR', 'PT'] as $country) {
            $errors = $this->validate($this->post(['country' => $country, 'lattesUrl' => 'https://orcid.org/0000-0002-1825-0097']));
            $this->assertSame([__('plugins.generic.reviewerLattes.field.invalid')], $errors, $country);
        }
    }

    /**
     * The point of the plugin: the link typed on the registration form ends up
     * on the account, in its standard form.
     */
    public function testTheLinkIsSavedAsTheUrlOfTheNewAccount(): void
    {
        $this->requireForBrazil(true);
        $vars = $this->post(['country' => 'BR', 'lattesUrl' => 'lattes.cnpq.br/1234567890123456']);
        $form = $this->form($vars);
        $form->validate();
        $this->assertArrayNotHasKey(ReviewerLattesPlugin::FIELD, $form->getErrorsArray());

        $userId = $form->execute();
        $this->assertIsInt($userId);
        $this->createdUserIds[] = $userId;

        // Read back from the database, past every cache.
        $stored = DB::table('users')->where('user_id', $userId)->value('url');
        $this->assertSame('https://lattes.cnpq.br/1234567890123456', $stored);
        $this->assertSame('BR', DB::table('users')->where('user_id', $userId)->value('country'));
    }

    public function testAForeignReviewerMayGiveTheLinkToo(): void
    {
        $this->requireForBrazil(true);
        $form = $this->form($this->post(['country' => 'PT', 'lattesUrl' => 'http://buscatextual.cnpq.br/buscatextual/visualizacv.do?id=K4723925J6']));
        $form->validate();
        $this->assertArrayNotHasKey(ReviewerLattesPlugin::FIELD, $form->getErrorsArray());
        $userId = $form->execute();
        $this->createdUserIds[] = $userId;

        $this->assertSame('https://buscatextual.cnpq.br/buscatextual/visualizacv.do?id=K4723925J6', DB::table('users')->where('user_id', $userId)->value('url'));
    }

    /**
     * What the plugin takes for granted about the second step of the OpenID
     * plugin, read from its source wherever it is installed: the hooks of the
     * core fire for it (fetch() and execute() of Form are reached), the two
     * buttons are told apart by the value posted, the account exists before
     * the hook of execute() fires, and the page is the one the field is put on.
     */
    public function testTheOpenIdFormIsTheOneThePluginExpects(): void
    {
        $root = dirname(__DIR__, 2) . '/openid';
        $formFile = $root . '/forms/OpenIDStep2Form.php';
        if (!is_file($formFile)) {
            $this->markTestSkipped('the OpenID plugin is not installed here');
        }
        $form = (string) file_get_contents($formFile);
        $template = (string) file_get_contents($root . '/templates/authStep2.tpl');
        $script = (string) file_get_contents($root . '/js/scripts.js');

        $this->assertMatchesRegularExpression('/class\s+OpenIDStep2Form\s+extends\s+Form\b/', $form);
        $this->assertStringContainsString('parent::__construct(', $form, 'the Constructor hook is fired by Form');
        $this->assertStringContainsString('parent::fetch(', $form, 'the display hook is fired by Form::fetch()');
        $this->assertMatchesRegularExpression("/readUserVars\([^;]*'register'[^;]*'connect'[^;]*'reviewerGroup'/s", $form);
        $this->assertStringContainsString("is_string(\$this->getData('register'))", $form);
        // The account is created before parent::execute(), which fires the hook.
        $execute = substr($form, (int) strpos($form, 'function execute('));
        $this->assertNotFalse(strpos($execute, '_registerUser()'));
        $this->assertGreaterThan(strpos($execute, '_registerUser()'), strpos($execute, 'parent::execute('));

        $this->assertMatchesRegularExpression('/action="\{url page="openid" op="registerOrConnect"\}"/', $template);
        $this->assertMatchesRegularExpression('/name="interests"[^>]*class="reviewerGroupInput"/', $template);
        $this->assertStringContainsString('name="register"', $template);
        $this->assertStringContainsString('name="reviewerGroup[', $template);
        // Its script makes every input of the part required, except the reviewer ones.
        $this->assertStringContainsString(':not(#emailConsent, .reviewerGroupInput)', $script);
    }

    public function testToCreateAnAccountThroughOrcidABrazilianReviewerMustGiveTheLink(): void
    {
        $this->requireForBrazil(true);
        $form = $this->openIdForm($this->openIdPost('register', ['country' => 'BR', 'lattesUrl' => '']));
        $this->assertTrue(ReviewerLattesPlugin::createsAccount($form));
        $this->assertSame([__('plugins.generic.reviewerLattes.field.required')], $this->errorsOf($form));

        // The rule of the registration form of the core, and no other.
        $this->assertSame([], $this->errorsOf($this->openIdForm($this->openIdPost('register', ['country' => 'PT', 'lattesUrl' => '']))));
        $vars = $this->openIdPost('register', ['country' => 'BR', 'lattesUrl' => '']);
        unset($vars['reviewerGroup']);
        $this->assertSame([], $this->errorsOf($this->openIdForm($vars)));
        $this->requireForBrazil(false);
        $this->assertSame([], $this->errorsOf($this->openIdForm($this->openIdPost('register', ['country' => 'BR', 'lattesUrl' => '']))));
    }

    public function testToCreateAnAccountThroughOrcidALinkThatIsNotLattesIsRefused(): void
    {
        $this->requireForBrazil(false);
        $form = $this->openIdForm($this->openIdPost('register', ['country' => 'PT', 'lattesUrl' => 'https://orcid.org/0000-0002-1825-0097']));
        $this->assertSame([__('plugins.generic.reviewerLattes.field.invalid')], $this->errorsOf($form));
    }

    public function testLinkingAnExistingAccountNeverLooksAtTheField(): void
    {
        $this->requireForBrazil(true);
        foreach (['', 'https://orcid.org/0000-0002-1825-0097'] as $value) {
            $form = $this->openIdForm($this->openIdPost('connect', ['country' => 'BR', 'lattesUrl' => $value]));
            $this->assertFalse(ReviewerLattesPlugin::createsAccount($form));
            $this->assertSame([], $this->errorsOf($form), "connect with '{$value}'");
        }
    }

    /**
     * The point of the change: the link typed on the second step of a
     * registration through ORCID ends up on the account that step created.
     */
    public function testTheLinkIsSavedAsTheUrlOfTheAccountCreatedThroughOrcid(): void
    {
        $this->requireForBrazil(true);
        $vars = $this->openIdPost('register', ['country' => 'BR', 'lattesUrl' => 'http://lattes.cnpq.br/1234567890123456/']);
        $form = $this->openIdForm($vars);
        $this->assertSame([], $this->errorsOf($form));

        $userId = $form->execute();
        $this->assertIsInt($userId);
        $this->createdUserIds[] = $userId;
        $this->assertSame('https://lattes.cnpq.br/1234567890123456', DB::table('users')->where('user_id', $userId)->value('url'));

        // A URL the account already has is never replaced.
        $again = $this->openIdForm(array_merge($vars, ['lattesUrl' => '6543210987654321']));
        Hook::call('openidstep2form::execute', [$again]);
        $this->assertSame('https://lattes.cnpq.br/1234567890123456', DB::table('users')->where('user_id', $userId)->value('url'));
    }

    public function testLinkingAnExistingAccountLeavesItsUrlAlone(): void
    {
        $this->requireForBrazil(false);
        $vars = $this->openIdPost('register', ['country' => 'BR', 'lattesUrl' => '']);
        $userId = $this->openIdForm($vars)->execute();
        $this->assertIsInt($userId);
        $this->createdUserIds[] = $userId;
        $this->assertEmpty(DB::table('users')->where('user_id', $userId)->value('url'));

        // The same person, the same e-mail and username, now linking the account.
        $connect = $this->openIdPost('connect', [
            'username' => $vars['username'], 'email' => $vars['email'], 'usernameLogin' => $vars['username'],
            'lattesUrl' => 'lattes.cnpq.br/1234567890123456',
        ]);
        $this->assertNull($this->openIdForm($connect)->execute());
        $this->assertEmpty(DB::table('users')->where('user_id', $userId)->value('url'));
    }

    /**
     * The second step of the OpenID plugin as posted by one of its two buttons
     * ("register" or "connect"): the fields of the person, with no password.
     */
    private function openIdPost(string $button, array $overrides): array
    {
        $vars = $this->post([]);
        unset($vars['password'], $vars['password2']);

        return array_merge($vars, ['selectedProvider' => 'orcid', $button => ''], $overrides);
    }

    private function openIdForm(array $vars): OpenIDStep2Form
    {
        Application::get()->getRequest()->_requestVars = $vars;
        $form = new OpenIDStep2Form();
        $form->readInputData();

        return $form;
    }

    /** The errors of a form on the Lattes field only, after validating it. */
    private function errorsOf(Form $form): array
    {
        $form->validate();
        $errors = $form->getErrorsArray()[ReviewerLattesPlugin::FIELD] ?? null;

        return $errors === null ? [] : (array) $errors;
    }

    /**
     * The posted form of a person who asks to review, with everything else the
     * core needs, and a username of its own.
     */
    private function post(array $overrides): array
    {
        $username = 'lattes' . substr(str_replace('.', '', (string) microtime(true)), -9) . random_int(10, 99);

        return array_merge([
            'username' => $username,
            'password' => 'Ojsbr!Teste2026',
            'password2' => 'Ojsbr!Teste2026',
            'givenName' => 'Teste',
            'familyName' => 'Lattes',
            'affiliation' => 'OJSBR',
            'email' => $username . '@example.invalid',
            'country' => 'BR',
            'interests' => '',
            'privacyConsent' => '1',
            'reviewerGroup' => [self::$reviewerGroupId => '1'],
        ], $overrides);
    }

    private function form(array $vars): RegistrationForm
    {
        Application::get()->getRequest()->_requestVars = $vars;
        $form = new RegistrationForm(Application::get()->getRequest()->getSite());
        $form->readInputData();

        return $form;
    }

    /** The errors of the form on the Lattes field only. */
    private function validate(array $vars): array
    {
        $form = $this->form($vars);
        $form->validate();
        $errors = $form->getErrorsArray()[ReviewerLattesPlugin::FIELD] ?? null;

        return $errors === null ? [] : (array) $errors;
    }

    private function requireForBrazil(bool $required): void
    {
        self::$plugin->updateSetting((int) self::$context->getId(), ReviewerLattesPlugin::SETTING_REQUIRED, $required, 'bool');
    }

    /** The request answers for the journal, as the registration page of the journal would. */
    private function pinContext(): void
    {
        $request = Application::get()->getRequest();
        $router = new class () extends PageRouter {
            public $pinned;

            public function getContext(PKPRequest $request, bool $forceReload = false): ?Context
            {
                return $this->pinned;
            }
        };
        $router->setApplication(Application::get());
        $router->pinned = self::$context;
        $request->setRouter($router);
    }

    /**
     * Remove a setting, as if it had never been saved. Not through
     * PluginSettingsDAO::deleteSetting(), which filters on a "plugin_Name"
     * column: MySQL does not mind the case, PostgreSQL has no such column.
     */
    private static function forgetSetting(int $contextId, string $name): void
    {
        $settings = new class () extends PluginSettingsDAO {
            public function forget(int $contextId, string $pluginName, string $name): void
            {
                $pluginName = static::_normalizePluginName($pluginName);
                DB::table('plugin_settings')
                    ->where('plugin_name', $pluginName)
                    ->whereRaw('COALESCE(context_id, 0) = ?', [$contextId])
                    ->where('setting_name', $name)
                    ->delete();
                Cache::forget($this->_getCacheId($contextId, $pluginName, true));
            }
        };
        $settings->forget($contextId, self::$plugin->getName(), $name);
    }
}
