/**
 * @file plugins/generic/reviewerLattes/js/reviewerLattes.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The Lattes field, on the registration form and in the Roles tab of the
 * profile: shown to those who tick a reviewer box, and required (mark and
 * browser check) when the scope of the journal covers the person — every
 * reviewer, or reviewers in Brazil when Brazil is the country chosen (or the
 * country of the account, in the profile).
 *
 * Where the journal keeps students from reviewing, an e-mail address typed on
 * the registration form that contains one of its pieces unticks, disables and
 * hides the reviewer boxes, with a short notice.
 *
 * The server checks the same rules; this only tells the person before sending.
 * Visibility is set on the style, not with the "hidden" attribute, because a
 * theme rule such as "label {display: block}" wins over the attribute.
 */
(function () {
	'use strict';

	var BRAZIL = 'BR';

	function reviewerBoxes(form) {
		return form.querySelectorAll('input[type="checkbox"][name^="reviewerGroup["]');
	}

	// The element that shows one reviewer box: its label, list item or group.
	function boxHolder(box) {
		return (box.closest && box.closest('label, li, .form-check, .optin > div')) || box;
	}

	function setupStudent(field, form) {
		var patterns = [];
		try {
			patterns = JSON.parse(field.getAttribute('data-student-patterns') || '[]');
		} catch (e) {
			patterns = [];
		}
		var email = form.querySelector('input[name="email"]');
		if (!patterns.length || !email) {
			return function () {};
		}
		var lowered = patterns.map(function (pattern) {
			return String(pattern).toLowerCase();
		});
		var notice = document.createElement('p');
		notice.className = 'reviewerLattes__studentNotice';
		notice.setAttribute('role', 'status');
		notice.textContent = field.getAttribute('data-student-notice') || '';
		notice.style.display = 'none';
		var boxes = reviewerBoxes(form);
		if (boxes.length) {
			var holder = boxHolder(boxes[boxes.length - 1]);
			holder.parentNode.insertBefore(notice, holder.nextSibling);
		}

		return function () {
			var address = String(email.value || '').toLowerCase();
			var isStudent = lowered.some(function (piece) {
				return address.indexOf(piece) !== -1;
			});
			var current = reviewerBoxes(form);
			for (var i = 0; i < current.length; i++) {
				if (isStudent) {
					current[i].checked = false;
				}
				current[i].disabled = isStudent;
				boxHolder(current[i]).style.display = isStudent ? 'none' : '';
			}
			notice.style.display = isStudent ? '' : 'none';
		};
	}

	function setup(field) {
		if (field.getAttribute('data-reviewer-lattes-ready') === '1') {
			return;
		}
		field.setAttribute('data-reviewer-lattes-ready', '1');
		var form = field.closest('form');
		var input = field.querySelector('input[name="lattesUrl"]');
		if (!form || !input) {
			return;
		}
		var marker = field.querySelector('.reviewerLattes__required');
		var scope = field.getAttribute('data-required-scope') || 'none';
		var accountCountry = field.getAttribute('data-user-country') || '';
		var current = [];
		try {
			current = JSON.parse(field.getAttribute('data-current-reviewer-groups') || '[]').map(String);
		} catch (e) {
			current = [];
		}
		var updateStudent = setupStudent(field, form);

		function update() {
			updateStudent();
			var boxes = reviewerBoxes(form);
			var isReviewer = false;
			var isJoining = false;
			for (var i = 0; i < boxes.length; i++) {
				if (boxes[i].checked) {
					isReviewer = true;
					var group = (/\[(\d+)\]/.exec(boxes[i].name) || [])[1];
					if (current.indexOf(group) === -1) {
						isJoining = true;
					}
				}
			}
			// A page without reviewer boxes still shows the field: nothing would open it.
			var visible = boxes.length === 0 || isReviewer;
			var select = form.querySelector('[name="country"]');
			var country = select ? select.value : accountCountry;
			// Required only for those joining now: in the profile, an account that
			// already reviews is not asked (the server applies the same rule).
			var required = visible && isJoining && (scope === 'all' || (scope === 'brazil' && country === BRAZIL));

			field.style.display = visible ? '' : 'none';
			input.required = required;
			input.setAttribute('aria-required', required ? 'true' : 'false');
			if (marker) {
				marker.style.display = required ? '' : 'none';
			}
		}

		form.addEventListener('change', update);
		form.addEventListener('click', update);
		form.addEventListener('input', update);
		update();
	}

	function init() {
		var fields = document.querySelectorAll('[data-reviewer-lattes]');
		for (var i = 0; i < fields.length; i++) {
			setup(fields[i]);
		}
	}

	// Run on load, and again when loaded inside a tab of the profile (the page is already loaded).
	if (document.readyState !== 'loading') {
		init();
	} else {
		document.addEventListener('DOMContentLoaded', init);
	}
})();
