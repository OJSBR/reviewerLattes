/**
 * @file plugins/generic/reviewerLattes/js/reviewerLattes.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The Lattes field of the registration form: shown to those who tick a
 * reviewer box, and required (mark and browser check) when the journal
 * requires it from reviewers in Brazil and Brazil is the country chosen.
 *
 * The server checks the same rule; this only tells the person before sending.
 * Visibility is set on the style, not with the "hidden" attribute, because a
 * theme rule such as "label {display: block}" wins over the attribute.
 */
(function () {
	'use strict';

	var BRAZIL = 'BR';

	function setup(field) {
		var form = field.closest('form');
		var input = field.querySelector('input[name="lattesUrl"]');
		if (!form || !input) {
			return;
		}
		var marker = field.querySelector('.reviewerLattes__required');
		var requiredForBrazil = field.getAttribute('data-required-for-brazil') === '1';

		function update() {
			var boxes = form.querySelectorAll('input[type="checkbox"][name^="reviewerGroup["]');
			var isReviewer = false;
			for (var i = 0; i < boxes.length; i++) {
				if (boxes[i].checked) {
					isReviewer = true;
				}
			}
			// A page without reviewer boxes still shows the field: nothing would open it.
			var visible = boxes.length === 0 || isReviewer;
			var country = form.querySelector('[name="country"]');
			var required = visible && requiredForBrazil && !!country && country.value === BRAZIL;

			field.style.display = visible ? '' : 'none';
			input.required = required;
			input.setAttribute('aria-required', required ? 'true' : 'false');
			if (marker) {
				marker.style.display = required ? '' : 'none';
			}
		}

		form.addEventListener('change', update);
		form.addEventListener('click', update);
		update();
	}

	function init() {
		var fields = document.querySelectorAll('[data-reviewer-lattes]');
		for (var i = 0; i < fields.length; i++) {
			setup(fields[i]);
		}
	}

	if (document.readyState !== 'loading') {
		init();
	} else {
		document.addEventListener('DOMContentLoaded', init);
	}
})();
