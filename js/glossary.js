/**
 * GBM Portfolio — Glosario page logic.
 * Live search across all terms; hides empty sections.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', () => {
		document.body.classList.add('gbm-app-active');
		const input = document.getElementById('glossary-search');
		const noResults = document.getElementById('no-results');
		if (!input) return;

		function applyFilter() {
			const q = (input.value || '').trim().toLowerCase();
			const sections = document.querySelectorAll('[data-section]');
			let anyVisible = false;
			for (const section of sections) {
				let sectionHasMatch = false;
				for (const term of section.querySelectorAll('.term')) {
					const txt = term.textContent.toLowerCase();
					const match = !q || txt.includes(q);
					term.classList.toggle('hidden', !match);
					if (match) sectionHasMatch = true;
				}
				section.classList.toggle('empty', !sectionHasMatch);
				if (sectionHasMatch) anyVisible = true;
			}
			noResults.classList.toggle('show', !anyVisible);
		}

		input.addEventListener('input', applyFilter);
		input.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') { input.value = ''; applyFilter(); }
		});
	});
})();
