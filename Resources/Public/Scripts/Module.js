/*
 * The activity log (ActivityController): selecting an entry of the timeline shows it in the detail pane; folded
 * groups; older entries loaded below; the module as high as the window; on a phone list and entry taking turns. The
 * compare of an entry and the word diff of its fields: Compare.js of Devclaim.NeosModernBackend.
 */
(() => {
	/** a changed field with its changed words marked (the compare of Devclaim.NeosModernBackend, Compare.js) */
	const markField = (field) => window.DevclaimCompare?.markField(field);

	/** the behaviour of entries, groups and details, for the page and for each loaded page of older entries */
	function bind(root, scope) {
		scope.querySelectorAll('dd[data-old][data-new]').forEach(markField);

		scope.querySelectorAll('[data-entry]').forEach((entry) => {
			entry.addEventListener('click', () => select(root, entry.dataset.entry));
			// up and down move through the timeline
			entry.addEventListener('keydown', (event) => {
				if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
					return;
				}
				const list = entries(root);
				const next = list[list.indexOf(entry) + (event.key === 'ArrowDown' ? 1 : -1)];
				if (next) {
					event.preventDefault();
					next.focus();
					select(root, next.dataset.entry);
				}
			});
		});

		scope.querySelectorAll('[data-unfold]').forEach((button) => {
			button.addEventListener('click', () => {
				button.closest('[data-group]')?.querySelectorAll('[data-folded]').forEach((entry) => (entry.hidden = false));
				button.remove();
			});
		});

		scope.querySelectorAll('[data-older]').forEach((link) => {
			link.addEventListener('click', (event) => {
				event.preventDefault();
				loadOlder(root, link);
			});
		});
	}

	const entries = (root) => [...root.querySelectorAll('[data-entry]:not([hidden])')];

	/** on a phone the list and the opened entry take turns (Module.css) */
	const isNarrow = () => window.matchMedia('(max-width: 1000px)').matches;

	/** shows an entry in the detail pane; on a phone in place of the list (open: false when the page starts) */
	function select(root, key, open = true) {
		if (open && isNarrow()) {
			root.classList.add('activity--detail-open');
			root.scrollIntoView({ block: 'start' });
		}
		root.querySelectorAll('[data-entry]').forEach((entry) => {
			entry.classList.toggle('activity__entry--selected', entry.dataset.entry === key);
		});
		let found = false;
		root.querySelectorAll('[data-detail]').forEach((article) => {
			// its compare loads once it is shown (Compare.js)
			article.hidden = article.dataset.detail !== key;
			found ||= !article.hidden;
		});
		const placeholder = root.querySelector('[data-detail-placeholder]');
		if (placeholder) {
			placeholder.hidden = found;
		}
	}

	/**
	 * Older entries below the ones shown: the next page of the log, its timeline appended (a day already shown keeps
	 * its heading) and its details added to the detail pane
	 */
	async function loadOlder(root, link) {
		link.setAttribute('aria-busy', 'true');
		let html;
		try {
			const response = await fetch(link.href, { credentials: 'same-origin' });
			if (!response.ok) {
				throw new Error(String(response.status));
			}
			html = await response.text();
		} catch {
			// the page itself still works
			window.location.href = link.href;
			return;
		}
		const next = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-activity]');
		const list = root.querySelector('[data-timeline]');
		const nextList = next?.querySelector('[data-timeline]');
		if (!next || !list || !nextList) {
			window.location.href = link.href;
			return;
		}
		const days = [...list.querySelectorAll('[data-day]')];
		const lastDay = days[days.length - 1]?.textContent.trim();
		const firstDay = nextList.querySelector('[data-day]');
		if (firstDay && firstDay.textContent.trim() === lastDay) {
			firstDay.remove();
		}

		const added = document.createElement('div');
		added.className = 'activity__page';
		added.append(...nextList.querySelectorAll('[data-day], [data-group]'));
		bind(root, added);
		list.querySelector('[data-paging]')?.remove();
		list.append(added);
		const paging = nextList.querySelector('[data-paging]');
		if (paging) {
			// "Newest" belongs to the first page only
			paging.querySelectorAll('a:not([data-older])').forEach((link) => link.remove());
			bind(root, paging);
			list.append(paging);
		}

		const details = document.createElement('div');
		details.append(...next.querySelectorAll('[data-detail]'));
		bind(root, details);
		root.querySelector('[data-details]').append(...details.children);
	}

	/**
	 * On wide screens the panes scroll each on their own and the page does not: the module as high as the window below
	 * its top, less whatever Neos puts below it (measured, not guessed). Narrow: as high as its content.
	 */
	function fitHeight(root) {
		root.style.height = '';
		if (isNarrow()) {
			return;
		}
		const top = root.getBoundingClientRect().top + window.scrollY;
		root.style.height = `${Math.max(480, window.innerHeight - top)}px`;
		const overflow = document.documentElement.scrollHeight - window.innerHeight;
		if (overflow > 0) {
			root.style.height = `${Math.max(480, root.offsetHeight - overflow)}px`;
		}
	}

	function start() {
		const root = document.querySelector('[data-activity]');
		if (!root) {
			return;
		}
		fitHeight(root);
		window.addEventListener('resize', () => fitHeight(root));
		bind(root, root);
		// the first entry with something to see, else the first one
		const first =
			entries(root).find((entry) =>
				root.querySelector(`[data-detail="${entry.dataset.entry}"] [data-compare], [data-detail="${entry.dataset.entry}"] dd`)
			) ?? entries(root)[0];
		if (first) {
			select(root, first.dataset.entry, false);
		}
		root.querySelector('[data-back]')?.addEventListener('click', () => {
			root.classList.remove('activity--detail-open');
			root.querySelector('.activity__entry--selected')?.scrollIntoView({ block: 'center' });
		});
		const toggle = root.querySelector('[data-filter-toggle]');
		toggle?.addEventListener('click', () => {
			const open = root.classList.toggle('activity--filters-open');
			toggle.setAttribute('aria-expanded', String(open));
		});
	}

	// Neos loads the scripts of a module in the <head>
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
