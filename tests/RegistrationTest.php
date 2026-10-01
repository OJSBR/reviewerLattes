<?php

/**
 * @file plugins/generic/reviewerLattes/tests/RegistrationTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RegistrationTest
 *
 * @brief The forms of the core, posted and saved, with the hooks of the plugin
 *        fired the way the core fires them: the registration form, and the
 *        Roles tab of the profile (RolesForm). The rules are applied by the
 *        core's own validation, and the link is read back from the account in
 *        the database.
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
use PKP\core\Core;
use PKP\core\PKPRequest;
use PKP\core\Registry;
use PKP\plugins\Hook;
use PKP\plugins\PluginSettingsDAO;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\tests\PKPTestCase;
use PKP\user\form\RegistrationForm;
use PKP\user\form\RolesForm;
use PKP\user\User;

#[CoversClass(ReviewerLattesPlugin::class)]
class RegistrationTest extends PKPTestCase
{
    private const LIVE_SITE_USERS = 5000;

    private const SETTINGS = [
        'enabled' => 'bool',
        ReviewerLattesPlugin::SETTING_SCOPE => 'string',
        ReviewerLattesPlugin::SETTING_REQUIRED => 'bool',
        ReviewerLattesPlugin::SETTING_STUDENT_PATTERNS => 'string',
    ];

    private const STUDENT_PATTERNS = "@aluno.\n@alunos.\n@estudante.\n@estudantes.\n@discente.\n@discentes.";

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
            foreach (array_keys(self::SETTINGS) as $name) {
                self::$savedSettings[$name] = self::$plugin->getSetting($contextId, $name);
            }
            self::$plugin->updateSetting($contextId, 'enabled', true, 'bool');
            $root = dirname(__DIR__);
            self::$plugin->register('generic', 'plugins/generic/' . basename($root), $contextId);
        }
        // Each test starts from a journal that set nothing.
        foreach ([ReviewerLattesPlugin::SETTING_SCOPE, ReviewerLattesPlugin::SETTING_REQUIRED, ReviewerLattesPlugin::SETTING_STUDENT_PATTERNS] as $name) {
            self::forgetSetting($contextId, $name);
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
        $nobody = null;
        Registry::set('user', $nobody);
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
                    self::$plugin->updateSetting($contextId, $name, $value, self::SETTINGS[$name]);
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
        foreach (['registrationform', 'rolesform', 'openidstep2form'] as $form) {
            foreach (['::Constructor', '::readuservars', '::display', '::execute'] as $hook) {
                $this->assertNotEmpty(Hook::getHooks($form . $hook), "{$form}{$hook} has no listener");
            }
        }
    }

    //
    // Registration
    //

    public function testABrazilianReviewerMustGiveTheLinkWhereTheJournalRequiresItFromBrazil(): void
    {
        $this->setScope('brazil');
        $this->assertSame([__('plugins.generic.reviewerLattes.field.required')], $this->lattesErrors($this->post(['country' => 'BR', 'lattesUrl' => ''])));
        $this->assertSame([], $this->lattesErrors($this->post(['country' => 'PT', 'lattesUrl' => ''])), 'another country never has to');
    }

    public function testEveryReviewerMustGiveItWhereTheJournalRequiresItFromAll(): void
    {
        $this->setScope('all');
        foreach (['BR', 'PT', 'US'] as $country) {
            $this->assertSame([__('plugins.generic.reviewerLattes.field.requiredAll')], $this->lattesErrors($this->post(['country' => $country, 'lattesUrl' => ''])), $country);
        }
    }

    public function testNobodyHasToGiveItWhereTheScopeIsNone(): void
    {
        $this->setScope('none');
        $this->assertSame([], $this->lattesErrors($this->post(['country' => 'BR', 'lattesUrl' => ''])));
    }

    public function testAJournalThatSetItUpIn10KeepsItsRule(): void
    {
        // 1.0.x saved only requiredForBrazil; 1.1 reads it as the brazil scope.
        self::$plugin->updateSetting((int) self::$context->getId(), ReviewerLattesPlugin::SETTING_REQUIRED, true, 'bool');
        $this->assertSame('brazil', self::$plugin->requiredScope((int) self::$context->getId()));
        $this->assertSame([__('plugins.generic.reviewerLattes.field.required')], $this->lattesErrors($this->post(['country' => 'BR', 'lattesUrl' => ''])));
        $this->assertSame([], $this->lattesErrors($this->post(['country' => 'PT', 'lattesUrl' => ''])));
    }

    public function testAPersonWhoDoesNotAskToReviewNeverHasToGiveIt(): void
    {
        $this->setScope('all');
        $vars = $this->post(['country' => 'BR', 'lattesUrl' => '']);
        unset($vars['reviewerGroup']);
        $this->assertSame([], $this->lattesErrors($vars));
    }

    public function testALinkThatIsNotLattesIsRefusedInEveryScope(): void
    {
        foreach (['none', 'brazil', 'all'] as $scope) {
            $this->setScope($scope);
            foreach (['BR', 'PT'] as $country) {
                $errors = $this->lattesErrors($this->post(['country' => $country, 'lattesUrl' => 'https://orcid.org/0000-0002-1825-0097']));
                $this->assertSame([__('plugins.generic.reviewerLattes.field.invalid')], $errors, "{$scope} {$country}");
            }
        }
    }

    /**
     * The point of the plugin: the link typed on the registration form ends up
     * on the account, in its standard form.
     */
    public function testTheLinkIsSavedAsTheUrlOfTheNewAccount(): void
    {
        $this->setScope('brazil');
        $form = $this->form($this->post(['country' => 'BR', 'lattesUrl' => 'lattes.cnpq.br/1234567890123456']));
        $form->validate();
        $this->assertArrayNotHasKey(ReviewerLattesPlugin::FIELD, $form->getErrorsArray());

        $userId = $form->execute();
        $this->assertIsInt($userId);
        $this->createdUserIds[] = $userId;

        // Read back from the database, past every cache.
        $this->assertSame('https://lattes.cnpq.br/1234567890123456', DB::table('users')->where('user_id', $userId)->value('url'));
        $this->assertSame('BR', DB::table('users')->where('user_id', $userId)->value('country'));
    }

    public function testAForeignReviewerMayGiveTheLinkToo(): void
    {
        $this->setScope('brazil');
        $form = $this->form($this->post(['country' => 'PT', 'lattesUrl' => 'http://buscatextual.cnpq.br/buscatextual/visualizacv.do?id=K4723925J6']));
        $form->validate();
        $this->assertArrayNotHasKey(ReviewerLattesPlugin::FIELD, $form->getErrorsArray());
        $userId = $form->execute();
        $this->createdUserIds[] = $userId;

        $this->assertSame('https://buscatextual.cnpq.br/buscatextual/visualizacv.do?id=K4723925J6', DB::table('users')->where('user_id', $userId)->value('url'));
    }

    public function testAStudentAddressCannotAskToReviewOnTheRegistrationForm(): void
    {
        $this->setStudents(self::STUDENT_PATTERNS);
        $vars = $this->post(['lattesUrl' => '1234567890123456']);
        $vars['email'] = 'teste' . random_int(1000, 9999) . '@ALUNO.cps.sp.gov.br';
        $this->assertSame([__('plugins.generic.reviewerLattes.student.error')], $this->errorsOn('reviewerGroup', $vars));

        // The same address may still register as a reader.
        unset($vars['reviewerGroup']);
        $this->assertSame([], $this->errorsOn('reviewerGroup', $vars));

        // Another address may ask to review.
        $this->assertSame([], $this->errorsOn('reviewerGroup', $this->post(['lattesUrl' => '1234567890123456'])));
    }

    public function testWithoutPiecesNobodyIsAStudent(): void
    {
        $vars = $this->post(['lattesUrl' => '1234567890123456']);
        $vars['email'] = 'teste' . random_int(1000, 9999) . '@aluno.cps.sp.gov.br';
        $this->assertSame([], $this->errorsOn('reviewerGroup', $vars));
    }

    //
    // Profile → Roles
    //

    public function testAnAccountThatBecomesAReviewerInRolesMustGiveTheLink(): void
    {
        $this->setScope('all');
        $user = $this->account('PT', null);
        $this->assertSame([__('plugins.generic.reviewerLattes.field.requiredAll')], $this->rolesErrors($user, ['reviewerGroup' => [self::$reviewerGroupId => '1'], 'lattesUrl' => '']));

        $this->setScope('brazil');
        $this->assertSame([], $this->rolesErrors($user, ['reviewerGroup' => [self::$reviewerGroupId => '1'], 'lattesUrl' => '']), 'an account outside Brazil, brazil scope');
        $brazilian = $this->account('BR', null);
        $this->assertSame([__('plugins.generic.reviewerLattes.field.required')], $this->rolesErrors($brazilian, ['reviewerGroup' => [self::$reviewerGroupId => '1'], 'lattesUrl' => '']), 'the country of the account counts');
    }

    public function testInRolesTheLinkIsSavedOnTheAccount(): void
    {
        $this->setScope('all');
        $user = $this->account('BR', null);
        $form = $this->rolesForm($user, ['reviewerGroup' => [self::$reviewerGroupId => '1'], 'lattesUrl' => 'http://lattes.cnpq.br/6543210987654321/']);
        $this->assertNoPluginErrors($form);
        $form->execute();

        $this->assertSame('https://lattes.cnpq.br/6543210987654321', DB::table('users')->where('user_id', $user->getId())->value('url'));
        $this->assertTrue(Repo::userGroup()->userInGroup((int) $user->getId(), self::$reviewerGroupId), 'the core saved the role too');
    }

    public function testInRolesALattesLinkAlreadyOnTheAccountIsEnoughAndIsNeverOverwritten(): void
    {
        $this->setScope('all');
        $user = $this->account('BR', 'http://lattes.cnpq.br/1111111111111111');
        $this->assertSame([], $this->rolesErrors($user, ['reviewerGroup' => [self::$reviewerGroupId => '1'], 'lattesUrl' => '']), 'not asked again');

        $form = $this->rolesForm($user, ['reviewerGroup' => [self::$reviewerGroupId => '1'], 'lattesUrl' => '2222222222222222']);
        $this->assertNoPluginErrors($form);
        $form->execute();
        $this->assertSame('http://lattes.cnpq.br/1111111111111111', DB::table('users')->where('user_id', $user->getId())->value('url'), 'the Lattes link of the account stays');
    }

    public function testInRolesAnUrlThatIsNotLattesIsReplacedByTheLink(): void
    {
        $this->setScope('none');
        $user = $this->account('BR', 'https://example.org/me');
        $form = $this->rolesForm($user, ['reviewerGroup' => [self::$reviewerGroupId => '1'], 'lattesUrl' => '3333333333333333']);
        $this->assertNoPluginErrors($form);
        $form->execute();
        $this->assertSame('https://lattes.cnpq.br/3333333333333333', DB::table('users')->where('user_id', $user->getId())->value('url'));
    }

    public function testInRolesSomeoneWhoAlreadyReviewsIsNotStopped(): void
    {
        $this->setScope('all');
        $this->setStudents(self::STUDENT_PATTERNS);
        $user = $this->account('BR', null, 'teste' . random_int(1000, 9999) . '@aluno.cps.sp.gov.br');
        Repo::userGroup()->assignUserToGroup((int) $user->getId(), self::$reviewerGroupId);

        // Saving the tab for another reason, with the reviewer box as it was.
        $this->assertSame([], $this->rolesErrors($user, ['reviewerGroup' => [self::$reviewerGroupId => '1'], 'lattesUrl' => '']));
        $this->assertSame([], $this->rolesErrors($user, ['reviewerGroup' => [self::$reviewerGroupId => '1'], 'lattesUrl' => ''], 'reviewerGroup'));
    }

    public function testInRolesAStudentCannotBecomeAReviewer(): void
    {
        $this->setStudents(self::STUDENT_PATTERNS);
        $user = $this->account('BR', 'http://lattes.cnpq.br/1111111111111111', 'teste' . random_int(1000, 9999) . '@discente.ufxx.br');
        $this->assertSame([__('plugins.generic.reviewerLattes.student.error')], $this->rolesErrors($user, ['reviewerGroup' => [self::$reviewerGroupId => '1']], 'reviewerGroup'));
        $this->assertSame([], $this->rolesErrors($user, ['readerGroup' => []], 'reviewerGroup'), 'not asking to review is fine');
    }

    //
    // Registration through the OpenID plugin
    //

    public function testOnTheOpenIdStepTheRulesApplyToRegistrationOnly(): void
    {
        $this->requireOpenId();
        $this->setScope('all');
        $this->setStudents(self::STUDENT_PATTERNS);

        $register = $this->openIdErrors(['register' => '', 'country' => 'PT', 'lattesUrl' => '']);
        $this->assertSame([__('plugins.generic.reviewerLattes.field.requiredAll')], $register[ReviewerLattesPlugin::FIELD] ?? []);

        $orcid = $this->openIdErrors(['register' => '', 'country' => 'BR', 'lattesUrl' => 'https://orcid.org/0000-0002-1825-0097']);
        $this->assertSame([__('plugins.generic.reviewerLattes.field.invalid')], $orcid[ReviewerLattesPlugin::FIELD] ?? [], 'an ORCID iD is not a Lattes CV');

        $student = $this->openIdErrors(['register' => '', 'lattesUrl' => '1234567890123456', 'email' => 'fulano@aluno.cps.sp.gov.br']);
        $this->assertSame([__('plugins.generic.reviewerLattes.student.error')], $student['reviewerGroup'] ?? []);

        $fine = $this->openIdErrors(['register' => '', 'lattesUrl' => 'lattes.cnpq.br/1234567890123456']);
        $this->assertArrayNotHasKey(ReviewerLattesPlugin::FIELD, $fine);
        $this->assertArrayNotHasKey('reviewerGroup', $fine);

        // "connect" links an existing account: nothing of the plugin applies.
        $connect = $this->openIdErrors(['connect' => '', 'usernameLogin' => 'nobody-here', 'passwordLogin' => 'x', 'lattesUrl' => 'not a link', 'email' => 'fulano@aluno.cps.sp.gov.br']);
        $this->assertArrayNotHasKey(ReviewerLattesPlugin::FIELD, $connect);
        $this->assertArrayNotHasKey('reviewerGroup', $connect);
    }

    public function testTheAccountCreatedThroughOpenIdGetsTheLink(): void
    {
        $this->requireOpenId();
        // The moment the hook is fired: the OpenID plugin has just created the account.
        $user = $this->account('BR', null);
        $form = $this->openIdForm(['register' => '', 'username' => $user->getUsername(), 'lattesUrl' => '  http://lattes.cnpq.br/4444444444444444 ']);
        Hook::call('openidstep2form::execute', [$form]);
        $this->assertSame('https://lattes.cnpq.br/4444444444444444', DB::table('users')->where('user_id', $user->getId())->value('url'));

        // Never over a Lattes link, and never in "connect" mode.
        $form = $this->openIdForm(['register' => '', 'username' => $user->getUsername(), 'lattesUrl' => '5555555555555555']);
        Hook::call('openidstep2form::execute', [$form]);
        $other = $this->account('BR', 'https://example.org/me');
        $connect = $this->openIdForm(['connect' => '', 'username' => $other->getUsername(), 'lattesUrl' => '6666666666666666']);
        Hook::call('openidstep2form::execute', [$connect]);
        $this->assertSame('https://lattes.cnpq.br/4444444444444444', DB::table('users')->where('user_id', $user->getId())->value('url'));
        $this->assertSame('https://example.org/me', DB::table('users')->where('user_id', $other->getId())->value('url'));
    }

    private function requireOpenId(): void
    {
        if (!class_exists('APP\plugins\generic\openid\forms\OpenIDStep2Form')) {
            $this->markTestSkipped('the OpenID plugin is not installed here');
        }
    }

    private function openIdForm(array $vars): \PKP\form\Form
    {
        $post = $this->post([]);
        unset($post['password'], $post['password2']);
        Application::get()->getRequest()->_requestVars = array_merge($post, $vars);
        $class = 'APP\plugins\generic\openid\forms\OpenIDStep2Form';
        // The OpenID plugin as the registry would load it: with its path, not registered
        // (its own hooks stay out of the test).
        $plugin = new \APP\plugins\generic\openid\OpenIDPlugin();
        $plugin->pluginPath = 'plugins/generic/openid';
        $form = new $class($plugin);
        $form->readInputData();

        return $form;
    }

    /** The errors of the OpenID step on the fields of the plugin. */
    private function openIdErrors(array $vars): array
    {
        $form = $this->openIdForm($vars);
        $form->validate();

        return array_map(fn ($error) => (array) $error, array_intersect_key($form->getErrorsArray(), [ReviewerLattesPlugin::FIELD => 1, 'reviewerGroup' => 1]));
    }

    //
    // Helpers
    //

    /**
     * The posted registration form of a person who asks to review, with
     * everything else the core needs, and a username of its own.
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

    /** The errors of the registration form on one field only. */
    private function errorsOn(string $field, array $vars): array
    {
        $form = $this->form($vars);
        $form->validate();
        $errors = $form->getErrorsArray()[$field] ?? null;

        return $errors === null ? [] : (array) $errors;
    }

    private function lattesErrors(array $vars): array
    {
        return $this->errorsOn(ReviewerLattesPlugin::FIELD, $vars);
    }

    /** A test account, deleted after the test. */
    private function account(string $country, ?string $url, ?string $email = null): User
    {
        $username = 'lattesr' . substr(str_replace('.', '', (string) microtime(true)), -8) . random_int(10, 99);
        $user = Repo::user()->newDataObject();
        $user->setUsername($username);
        $user->setEmail($email ?? $username . '@example.invalid');
        $user->setGivenName('Teste', 'en');
        $user->setFamilyName('Lattes', 'en');
        $user->setCountry($country);
        $user->setUrl($url);
        $user->setPassword(Validation::encryptCredentials($username, 'Ojsbr!Teste2026'));
        $user->setDateRegistered(Core::getCurrentDate());
        $userId = Repo::user()->add($user);
        $this->createdUserIds[] = $userId;

        return Repo::user()->get($userId, true);
    }

    /** The Roles tab posted by the account itself. */
    private function rolesForm(User $user, array $vars): RolesForm
    {
        $this->mockRequest('index/user/profile', (int) $user->getId());
        $this->pinContext();
        // RolesForm::execute() saves the roles of the user of the request.
        Registry::set('user', $user);
        Application::get()->getRequest()->_requestVars = $vars;
        $form = new RolesForm($user);
        $form->readInputData();

        return $form;
    }

    /**
     * The form checked, with no error from the plugin. The profile form also
     * checks that it was posted with its token, which a test request is not:
     * like the captcha of the registration form, that error is not looked at,
     * and the form is then saved through its own execute().
     */
    private function assertNoPluginErrors(RolesForm $form): void
    {
        $form->validate();
        $errors = $form->getErrorsArray();
        $this->assertArrayNotHasKey(ReviewerLattesPlugin::FIELD, $errors);
        $this->assertArrayNotHasKey('reviewerGroup', $errors);
    }

    private function rolesErrors(User $user, array $vars, string $field = ReviewerLattesPlugin::FIELD): array
    {
        $form = $this->rolesForm($user, $vars);
        $form->validate();
        $errors = $form->getErrorsArray()[$field] ?? null;

        return $errors === null ? [] : (array) $errors;
    }

    private function setScope(string $scope): void
    {
        self::$plugin->updateSetting((int) self::$context->getId(), ReviewerLattesPlugin::SETTING_SCOPE, $scope, 'string');
    }

    private function setStudents(string $patterns): void
    {
        self::$plugin->updateSetting((int) self::$context->getId(), ReviewerLattesPlugin::SETTING_STUDENT_PATTERNS, $patterns, 'string');
    }

    /** The request answers for the journal, as the pages of the journal would. */
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
