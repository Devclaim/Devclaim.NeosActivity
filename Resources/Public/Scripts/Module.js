/*
 * The activity log (ActivityController): selecting an entry of the timeline shows it in the detail pane and loads its
 * frames; the views side by side and slider; folded groups; the changed words marked, in the fields and in the
 * rendered website (the frames have the same origin as the module, so their documents can be read)
 */
(() => {
	const MAX_CELLS = 250000;

	/** words and the spaces between them, so a diff keeps the text as it was */
	const tokens = (text) => text.split(/(\s+)/).filter((token) => token !== '');

	/**
	 * A word diff (longest common subsequence): a list of [kind, text], kind "same", "del" or "ins". Too long texts:
	 * one deletion and one insertion
	 */
	function diff(oldText, newText) {
		const a = tokens(oldText);
		const b = tokens(newText);
		if (a.length * b.length > MAX_CELLS) {
			return [['del', oldText], ['ins', newText]];
		}
		const lengths = Array.from({ length: a.length + 1 }, () => new Uint16Array(b.length + 1));
		for (let i = a.length - 1; i >= 0; i--) {
			for (let j = b.length - 1; j >= 0; j--) {
				lengths[i][j] = a[i] === b[j] ? lengths[i + 1][j + 1] + 1 : Math.max(lengths[i + 1][j], lengths[i][j + 1]);
			}
		}
		const parts = [];
		const push = (kind, text) => {
			const last = parts[parts.length - 1];
			if (last && last[0] === kind) {
				last[1] += text;
			} else {
				parts.push([kind, text]);
			}
		};
		let i = 0;
		let j = 0;
		while (i < a.length && j < b.length) {
			if (a[i] === b[j]) {
				push('same', a[i]);
				i++;
				j++;
			} else if (lengths[i + 1][j] >= lengths[i][j + 1]) {
				push('del', a[i++]);
			} else {
				push('ins', b[j++]);
			}
		}
		while (i < a.length) push('del', a[i++]);
		while (j < b.length) push('ins', b[j++]);
		return parts;
	}

	/** the parts of one side as nodes: the other side's changes left out */
	function fragment(document, parts, side) {
		const result = document.createDocumentFragment();
		for (const [kind, text] of parts) {
			if (kind === 'same') {
				result.append(text);
			} else if ((kind === 'del' && side !== 'after') || (kind === 'ins' && side !== 'before')) {
				const mark = document.createElement(kind);
				mark.textContent = text;
				result.append(mark);
			}
		}
		return result;
	}

	/** a changed field: old and new as one text with the changed words marked */
	function markField(field) {
		const parts = diff(field.dataset.old ?? '', field.dataset.new ?? '');
		field.replaceChildren(fragment(document, parts, 'both'));
	}

	const textNodes = (document) => {
		const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
			acceptNode: (node) =>
				node.textContent.trim() === '' || node.parentElement?.closest('script, style')
					? NodeFilter.FILTER_REJECT
					: NodeFilter.FILTER_ACCEPT
		});
		const nodes = [];
		while (walker.nextNode()) nodes.push(walker.currentNode);
		return nodes;
	};

	const MARK_STYLE =
		'ins{background:#c9f0d4;color:#0b4a1f;text-decoration:none}' +
		'del{background:#fde1df;color:#7a1d16;text-decoration:line-through}';

	/**
	 * The changed words in the rendered website, before and after: only where both have the same text nodes (the same
	 * structure, the texts changed); a changed structure is shown as it is
	 */
	function markFrames(before, after) {
		const beforeNodes = textNodes(before);
		const afterNodes = textNodes(after);
		if (beforeNodes.length !== afterNodes.length) {
			return;
		}
		for (const document of [before, after]) {
			const style = document.createElement('style');
			style.textContent = MARK_STYLE;
			document.head.append(style);
		}
		beforeNodes.forEach((beforeNode, index) => {
			const afterNode = afterNodes[index];
			if (beforeNode.textContent === afterNode.textContent) {
				return;
			}
			const parts = diff(beforeNode.textContent, afterNode.textContent);
			beforeNode.replaceWith(fragment(before, parts, 'before'));
			afterNode.replaceWith(fragment(after, parts, 'after'));
		});
	}

	/**
	 * The height of the rendered node: its root element (data-styleguide-root, PROTOCOL.md of the styleguide), not the
	 * page, which may be as high as the frame (100vh) and would grow with it
	 */
	const rootOf = (document) => document?.querySelector('[data-styleguide-root]') ?? document?.body ?? null;

	/** a frame as high as the node in it; the frames of one view as high as the highest */
	function fit(frames) {
		const heights = frames.map((frame) => {
			const root = rootOf(frame.contentDocument);
			return root ? Math.ceil(root.getBoundingClientRect().bottom) : 0;
		});
		const height = Math.max(160, ...heights);
		frames.forEach((frame) => (frame.style.height = `${height}px`));
	}

	/**
	 * The address of a node preview: the manifest of the styleguide adapter (Devclaim.NeosStyleguide, PROTOCOL.md)
	 * names its page (nodePreview), the frame names the node reference (ActivityNodeReferenceResolver)
	 */
	let manifest = null;
	function previewTemplate() {
		const root = document.querySelector('[data-activity]');
		const adapter = root?.dataset.adapter;
		if (!adapter) {
			return Promise.resolve(null);
		}
		manifest ??= fetch(`${adapter.replace(/\/$/, '')}/manifest`, { credentials: 'same-origin' })
			.then((response) => (response.ok ? response.json() : null))
			.then((data) => (typeof data?.nodePreview === 'string' ? data.nodePreview : null))
			.catch(() => null);
		return manifest;
	}

	async function load(pane) {
		const frames = [...pane.querySelectorAll('iframe[data-ref]')];
		const pending = frames.filter((frame) => !frame.src && !frame.dataset.loading);
		if (pending.length === 0) {
			return;
		}
		pending.forEach((frame) => (frame.dataset.loading = 'true'));
		const template = await previewTemplate();
		if (!template) {
			// the adapter cannot show nodes: the changed fields below are all there is
			pane.closest('[data-detail]')?.classList.add('activity__article--no-preview');
			return;
		}
		const site = document.querySelector('[data-activity]')?.dataset.site ?? '';
		let loaded = 0;
		for (const frame of pending) {
			frame.addEventListener('load', () => {
				const root = rootOf(frame.contentDocument);
				if (root) {
					new ResizeObserver(() => fit(frames)).observe(root);
				}
				if (++loaded === pending.length) {
					fit(frames);
					const before = pane.querySelector('iframe[data-side="before"]')?.contentDocument;
					const after = pane.querySelector('iframe[data-side="after"]')?.contentDocument;
					if (before?.body && after?.body) {
						markFrames(before, after);
					}
				}
			});
			frame.src = template
				.replace('{ref}', encodeURIComponent(frame.dataset.ref))
				.replace('{site}', encodeURIComponent(site));
		}
	}

	/** a view of an opened entry: side by side or slider (the frames load when shown) */
	function show(article, view) {
		article.querySelectorAll('[data-view]').forEach((button) => {
			button.classList.toggle('activity__view--active', button.dataset.view === view);
		});
		article.querySelectorAll('[data-pane]').forEach((pane) => {
			pane.hidden = pane.dataset.pane !== view;
			if (!pane.hidden) {
				load(pane);
			}
		});
	}

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

		scope.querySelectorAll('[data-detail]').forEach((article) => {
			article.querySelectorAll('[data-view]').forEach((button) => {
				button.addEventListener('click', () => show(article, button.dataset.view));
			});
			const handle = article.querySelector('.activity__handle');
			if (handle) {
				dragLine(handle);
			}
		});

		scope.querySelectorAll('[data-older]').forEach((link) => {
			link.addEventListener('click', (event) => {
				event.preventDefault();
				loadOlder(root, link);
			});
		});
	}

	/**
	 * The line between before (left of it) and after: dragged with mouse, finger or pen, moved with the arrow keys.
	 * While dragging a shield lies over the frames, which would take the pointer events.
	 */
	function dragLine(handle) {
		const stack = handle.closest('.activity__stack');
		const clip = stack.querySelector('.activity__clip');
		const shield = stack.querySelector('.activity__shield');
		const set = (percent) => {
			const value = Math.min(100, Math.max(0, percent));
			clip.style.clipPath = `inset(0 ${100 - value}% 0 0)`;
			handle.style.left = `${value}%`;
			handle.setAttribute('aria-valuenow', String(Math.round(value)));
		};
		const follow = (event) => {
			const box = stack.getBoundingClientRect();
			set(((event.clientX - box.left) / box.width) * 100);
		};
		const stop = () => {
			shield.hidden = true;
			window.removeEventListener('pointermove', follow);
			window.removeEventListener('pointerup', stop);
			window.removeEventListener('pointercancel', stop);
		};
		handle.addEventListener('pointerdown', (event) => {
			event.preventDefault();
			shield.hidden = false;
			follow(event);
			window.addEventListener('pointermove', follow);
			window.addEventListener('pointerup', stop);
			window.addEventListener('pointercancel', stop);
		});
		handle.addEventListener('keydown', (event) => {
			const step = event.shiftKey ? 10 : 2;
			const now = Number(handle.getAttribute('aria-valuenow'));
			if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
				event.preventDefault();
				set(now + (event.key === 'ArrowRight' ? step : -step));
			}
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
			article.hidden = article.dataset.detail !== key;
			if (!article.hidden) {
				found = true;
				const active = article.querySelector('.activity__view--active');
				show(article, active ? active.dataset.view : 'side');
			}
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
				root.querySelector(`[data-detail="${entry.dataset.entry}"] iframe, [data-detail="${entry.dataset.entry}"] dd`)
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
