/**
 * @file cypress/tests/functional/ReviewerLattes.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the setting saved and read back, the field on the real
 * registration page (shown to reviewers, marked required for Brazil), and a
 * registration sent through the page with the link read back from the account.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration; the first test enables the plugin when it is off. The setting
 * is put back and the account created is disabled.
 *
 * The registration that is actually sent runs only where the registration page
 * has no captcha, as in PKP's continuous integration: a captcha is never solved
 * by the suite. Where there is one, the same path is covered by
 * tests/RegistrationTest.php, which posts the core registration form in PHP and
 * reads the link back from the database.
 */

describe('Reviewer Lattes plugin', {testIsolation: false}, function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	// Set where the registration page has a captcha: the test that sends it is then left to PHPUnit.
	const captchaOnRegister = !!Cypress.env('captchaOnRegister');

	const rowName = 'reviewerlattesplugin';
	const settingsForm = 'form[id="reviewerLattesSettingsForm"]';
	const lattesId = '1234567890123456';
	let original = null;
	const createdUsernames = [];

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	const waitJQuery = () => cy.window({timeout: 60000}).should((win) => expect(win.jQuery && win.jQuery.active).to.eq(0));

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick.
	const login = (username, password) => {
		cy.clearCookies();
		// The first request of a run can find PHP's server still cold on PKP's CI:
		// cy.request gives up at 30 s, a page load waits for pageLoadTimeout.
		request({url: pageUrl('login'), timeout: 120000}).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	const enablePlugin = () => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	const waitFormHandler = () => cy.window({timeout: 30000}).should((win) => {
		expect(win.jQuery(settingsForm).data('pkp.handler'), 'form handler').to.exist;
	});

	const openSettings = () => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		waitFormHandler();
	};

	// ---- end of helpers ----

	// Saves the setting through the modal of the page already open.
	const saveSetting = (value) => {
		openSettings();
		cy.get(settingsForm + ' input[name="lattesForBrazil"][value="' + value + '"]').check({force: true});
		waitFormHandler();
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		waitJQuery();
		cy.get(settingsForm).should('not.exist');
	};

	// Saves the setting without the modal (used to put it back), as the modal posts it.
	const postSetting = (value) => cy.window({log: false}).then((win) => request({
		method: 'POST',
		url: pageUrl('$$$call$$$/grid/settings/plugins/settings-plugin-grid/manage') + '?verb=settings&plugin=' + rowName + '&category=generic&save=1',
		form: true,
		body: {csrfToken: win.pkp.currentUser.csrfToken, lattesForBrazil: value},
	}));

	// The registration page as a visitor sees it.
	const registrationForm = () => {
		cy.clearCookies();
		cy.visit(pageUrl('user/register') + '?reload=' + Date.now());
		cy.get('form[action*="/user/register"]', {timeout: 30000}).should('exist');
	};
	const form = 'form[action*="/user/register"]';
	const field = () => cy.get(form + ' [data-reviewer-lattes]');
	const reviewerBox = () => cy.get(form + ' input[type="checkbox"][name^="reviewerGroup["]').first();

	before(function() {
		cy.visit(pageUrl('login'));
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin();
	});

	after(function() {
		if (original === null) {
			return;
		}
		login(adminUser, adminPassword);
		postSetting(original);
		createdUsernames.forEach((username) => {
			api(pageUrl('api/v1/users?searchPhrase=' + username + '&count=10')).then((users) => {
				const user = users.items.find((item) => item.userName === username);
				if (user) {
					// The core grid has no deletion short of merging accounts: the test
					// account is disabled, with its reason, and stays out of every list.
					cy.window({log: false}).then((win) => request({
						method: 'POST',
						url: pageUrl('$$$call$$$/grid/settings/user/user-grid/disable-user'),
						form: true,
						failOnStatusCode: false,
						body: {userId: user.id, enable: 0, disableReason: 'reviewerLattes test account', csrfToken: win.pkp.currentUser.csrfToken},
					}));
				}
			});
		});
	});

	it('Saves the requirement for Brazil and reads it back', function() {
		// The setting as found, put back in after(). Read in the modal, on the page
		// already open: PKP's CI serves one request at a time, and a request of the
		// suite would wait behind the plugin gallery the Plugins tab is still loading.
		openSettings();
		cy.get(settingsForm + ' input[name="lattesForBrazil"]:checked').invoke('val').then((value) => {
			if (original === null) {
				original = value;
			}
		});
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		waitJQuery();
		cy.get(settingsForm).should('not.exist');

		saveSetting('required');
		openSettings();
		cy.get(settingsForm + ' input[name="lattesForBrazil"][value="required"]').should('be.checked');
		saveSetting('optional');
		openSettings();
		cy.get(settingsForm + ' input[name="lattesForBrazil"][value="optional"]').should('be.checked');
		saveSetting('required');
	});

	it('Shows the field to reviewers only, and marks it required for Brazil', function() {
		registrationForm();
		// Looked for by what it is: the theme decides the markup around it.
		cy.get(form + ' input[name="lattesUrl"]').should('exist').and('have.attr', 'inputmode', 'url');
		cy.get(form + ' #lattesUrlDescription').invoke('text').should('contain', 'lattes.cnpq.br').and('not.contain', '##');

		reviewerBox().uncheck({force: true});
		field().should('not.be.visible');

		reviewerBox().check({force: true});
		field().should('be.visible');
		cy.get(form + ' select[name="country"]').select('BR');
		cy.get(form + ' input[name="lattesUrl"]').should('have.attr', 'required');
		cy.get(form + ' .reviewerLattes__required').should('be.visible');

		// Another country: the field stays, never required.
		cy.get(form + ' select[name="country"]').select('PT');
		field().should('be.visible');
		cy.get(form + ' input[name="lattesUrl"]').should('not.have.attr', 'required');
		cy.get(form + ' .reviewerLattes__required').should('not.be.visible');

		// Not a reviewer any more: the field goes, and with it the requirement.
		cy.get(form + ' select[name="country"]').select('BR');
		reviewerBox().uncheck({force: true});
		field().should('not.be.visible');
		cy.get(form + ' input[name="lattesUrl"]').should('not.have.attr', 'required');
	});

	it('Leaves the field optional for Brazil when the journal does not require it', function() {
		login(adminUser, adminPassword);
		postSetting('optional');
		registrationForm();
		reviewerBox().check({force: true});
		cy.get(form + ' select[name="country"]').select('BR');
		field().should('be.visible');
		cy.get(form + ' input[name="lattesUrl"]').should('not.have.attr', 'required');
		cy.get(form + ' .reviewerLattes__required').should('not.be.visible');
		login(adminUser, adminPassword);
		postSetting('required');
	});

	// The point of the field: the link typed on the registration page ends up on
	// the account. Sent through the page and read back where an editor reads it.
	(captchaOnRegister ? it.skip : it)('Saves the link typed on the registration page as the URL of the account', function() {
		const username = 'lattes' + Date.now().toString().slice(-8);
		createdUsernames.push(username);
		registrationForm();
		cy.get(form + ' input[name="givenName"]').type('Teste', {delay: 0});
		cy.get(form + ' input[name="familyName"]').type('Lattes', {delay: 0});
		cy.get(form + ' input[name="affiliation"]').type('OJSBR', {delay: 0});
		cy.get(form + ' select[name="country"]').select('BR');
		cy.get(form + ' input[name="email"]').type(username + '@mailinator.com', {delay: 0});
		cy.get(form + ' input[name="username"]').type(username, {delay: 0});
		cy.get(form + ' input[name="password"]').type('Ojsbr!Teste2026', {delay: 0, log: false});
		cy.get(form + ' input[name="password2"]').type('Ojsbr!Teste2026', {delay: 0, log: false});
		cy.get('body').then(($body) => {
			if ($body.find(form + ' input[name="privacyConsent"]').length) {
				cy.get(form + ' input[name="privacyConsent"]').check({force: true});
			}
		});
		reviewerBox().check({force: true});
		// Typed as people paste it: no scheme, spaces around.
		cy.get(form + ' input[name="lattesUrl"]').type('  lattes.cnpq.br/' + lattesId + ' ', {delay: 0});
		cy.get(form).submit();
		cy.get(form + ' input[name="lattesUrl"]', {timeout: 30000}).should('not.exist');

		login(adminUser, adminPassword);
		api(pageUrl('api/v1/users?searchPhrase=' + username + '&count=10')).then((users) => {
			const user = users.items.find((item) => item.userName === username);
			expect(user, 'the account was created').to.exist;

			return request({url: pageUrl('$$$call$$$/grid/settings/user/user-grid/edit-user') + '?rowId=' + user.id, failOnStatusCode: false});
		}).then((response) => {
			const answer = typeof response.body === 'string' ? JSON.parse(response.body) : response.body;
			const content = String(answer.content).replace(/\s+/g, ' ');
			expect(content, 'the account form was opened').to.contain('userUrl');
			expect(content, 'the link became the URL of the account, in its standard form')
				.to.match(new RegExp('name="userUrl"[^>]*value="https://lattes\\.cnpq\\.br/' + lattesId + '"|value="https://lattes\\.cnpq\\.br/' + lattesId + '"[^>]*name="userUrl"'));
		});
	});

	// And a link that is not Lattes has to say so on the page, not vanish.
	(captchaOnRegister ? it.skip : it)('Refuses a link that is not a Lattes CV', function() {
		registrationForm();
		cy.get(form + ' input[name="givenName"]').type('Teste', {delay: 0});
		cy.get(form + ' select[name="country"]').select('PT');
		reviewerBox().check({force: true});
		cy.get(form + ' input[name="lattesUrl"]').type('https://orcid.org/0000-0002-1825-0097', {delay: 0});
		cy.get(form).submit();
		// Back on the form, with the value kept and the reason given next to the field.
		cy.get(form + ' input[name="lattesUrl"]', {timeout: 30000}).should('have.value', 'https://orcid.org/0000-0002-1825-0097');
		cy.get(form + ' [data-reviewer-lattes] .error').invoke('text').should('contain', 'lattes.cnpq.br');
	});
});
