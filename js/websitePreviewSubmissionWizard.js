(function() {
	var config = window.websitePreviewSubmissionWizardConfig || {};
	var genreId = config.genreId;
	var genreName = config.genreName || 'Web Project';

	if (!genreId) {
		return;
	}

	function isSubmissionWizard() {
		return window.location.href.indexOf('/submission/wizard/') !== -1;
	}

	function addWebProjectGenreOption() {
		if (!isSubmissionWizard()) {
			return;
		}

		var modal = Array.prototype.filter.call(document.querySelectorAll('.modal, [role=dialog]'), function(element) {
			return (element.textContent || '').indexOf('What kind of file is this?') !== -1;
		})[0];
		if (!modal || (modal.textContent || '').indexOf(genreName) !== -1) {
			return;
		}

		var options = modal.querySelector('fieldset') || modal.querySelector('.modal__content') || modal;
		var otherInput = Array.prototype.filter.call(options.querySelectorAll('input[type=radio]'), function(input) {
			var label = input.closest('label');
			return label && (label.textContent || '').trim() === 'Other';
		})[0];
		var referenceLabel = otherInput ? otherInput.closest('label') : null;
		if (!referenceLabel) {
			return;
		}

		var label = referenceLabel.cloneNode(true);
		var input = label.querySelector('input[type=radio]');
		if (!input) {
			return;
		}
		input.value = String(genreId);
		input.checked = false;
		input.removeAttribute('checked');
		input.id = 'genreId-web-project';

		Array.prototype.forEach.call(label.childNodes, function(node) {
			if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
				node.textContent = ' ' + genreName;
			}
		});
		if ((label.textContent || '').indexOf(genreName) === -1) {
			label.appendChild(document.createTextNode(' ' + genreName));
		}

		referenceLabel.parentNode.insertBefore(label, referenceLabel);
	}

	function initSubmissionWizardSupport() {
		addWebProjectGenreOption();
		var observer = new MutationObserver(function() {
			addWebProjectGenreOption();
		});
		observer.observe(document.body, {childList: true, subtree: true});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initSubmissionWizardSupport);
	} else {
		initSubmissionWizardSupport();
	}
})();
