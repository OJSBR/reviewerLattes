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
        foreach (['registrationform::Constructor', 'registrationform::readuservars', 'registrationform::display', 'registrationform::execute'] as $hook) {
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
