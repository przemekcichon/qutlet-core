/**
 * Qutlet Core — kreator (wizard) przeglądu produktu (P-17.2, D-17.1/D-17.6).
 *
 * Orkiestruje WIDOCZNOŚĆ/KOLEJNOŚĆ istniejących metaboxów w kroki: fizycznie
 * przenosi (appendChild — przenosi węzeł, nie klonuje) DOM node'y bieżącego
 * kroku do karty kreatora i chowa resztę (`display:none`), bez ingerencji w
 * logikę zapisu. Karta kreatora jest wstawiona jako dziecko `<form id="post">`
 * (`position:fixed` w CSS daje pełnoekranową nakładkę mimo pozycji w DOM) —
 * przenoszone pola zostają więc wewnątrz tego samego formularza, więc
 * zbiorczy submit Woo (kroki 3–5) działa bez zmian; kroki 1–2 (qutlet-ai)
 * mają własny AJAX, niezależny od tego submitu.
 *
 * Oryginalne miejsce każdego dotkniętego węzła zapamiętujemy przez niewidoczny
 * placeholder (komentarz DOM) wstawiony TUŻ PRZED nim, nie przez porównanie
 * „czy mój zapamiętany nextSibling już wrócił" — ta druga heurystyka myli
 * kolejność przy przywracaniu dwóch węzłów tego samego kroku, jeśli okażą się
 * bezpośrednimi sąsiadami w DOM (pierwszy przywrócony ląduje na końcu
 * rodzica zamiast na swoim miejscu). Placeholder działa niezależnie od
 * kolejności przywracania.
 */
( function () {
	var config = window.qutletProductReviewWizard;

	if ( ! config || ! Array.isArray( config.steps ) || 0 === config.steps.length ) {
		return;
	}

	var form = document.getElementById( 'post' );

	if ( ! form ) {
		return;
	}

	var originalPositions = new Map(); // el -> { placeholder, display } — miejsce/widoczność PRZED dotknięciem przez kreator.
	var currentStepNodes = [];
	var currentStepIndex = 0;

	var overlay = buildOverlay();
	var stepsListEl = overlay.querySelector( '[data-qutlet-wizard-steps]' );
	var stepDots = Array.prototype.slice.call( stepsListEl.querySelectorAll( '[data-qutlet-wizard-step-index]' ) );
	var bodyEl = overlay.querySelector( '[data-qutlet-wizard-body]' );
	var backBtn = overlay.querySelector( '[data-qutlet-wizard-back]' );
	var nextBtn = overlay.querySelector( '[data-qutlet-wizard-next]' );

	form.appendChild( overlay );

	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-qutlet-wizard-open]' ) ) {
			e.preventDefault();
			open();

			return;
		}

		if ( overlay.hidden ) {
			return;
		}

		if ( e.target === overlay || e.target.closest( '[data-qutlet-wizard-close]' ) ) {
			close();

			return;
		}

		var dot = e.target.closest( '[data-qutlet-wizard-step-index]' );

		if ( dot ) {
			goToStep( parseInt( dot.getAttribute( 'data-qutlet-wizard-step-index' ), 10 ) );
		}
	} );

	backBtn.addEventListener( 'click', function () {
		goToStep( currentStepIndex - 1 );
	} );

	nextBtn.addEventListener( 'click', function () {
		if ( currentStepIndex >= config.steps.length - 1 ) {
			finish();

			return;
		}

		goToStep( currentStepIndex + 1 );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! overlay.hidden ) {
			close();
		}
	} );

	if ( config.autoOpen ) {
		open();
	}

	function open() {
		document.querySelectorAll( '#poststuff .postbox' ).forEach( function ( postbox ) {
			rememberPosition( postbox );
			postbox.style.display = 'none';
		} );

		overlay.hidden = false;
		document.body.classList.add( 'qutlet-wizard-open' );
		goToStep( 0 );
	}

	function close() {
		currentStepNodes = [];

		// Pełny reset: KAŻDY węzeł kiedykolwiek dotknięty w tej sesji wraca na
		// swoje miejsce i odzyskuje SWOJĄ oryginalną widoczność — nie zawsze
		// '' (widoczny). Postbox schowany wcześniej przez użytkownika przez
		// „Opcje ekranu" (inline `display:none` z jQuery `.hide()`, WP core
		// `postbox.js`) ma wrócić schowany, nie zostać przywrócony na siłę.
		originalPositions.forEach( function ( pos, el ) {
			releaseNode( el, pos.display );

			if ( pos.placeholder.parentNode ) {
				pos.placeholder.parentNode.removeChild( pos.placeholder );
			}
		} );

		originalPositions.clear();

		overlay.hidden = true;
		document.body.classList.remove( 'qutlet-wizard-open' );
	}

	function finish() {
		close();

		// D-17.1: kreator to WYŁĄCZNIE nakładka nawigacyjna, bez własnego
		// zapisu — pola kroków 3–5 (bez AJAX-a) żyją w natywnym `<form
		// id="post">` i zapisują się dopiero przy zbiorczym submicie Woo/WP.
		// „Zakończ" woła NATYWNY przycisk Update (`#publish`, ten sam kod co
		// ręczne kliknięcie — nonce/walidacja WP bez zmian), żeby te pola
		// faktycznie się zapisały zamiast cicho zniknąć razem z modalem.
		var publishBtn = document.getElementById( 'publish' );

		if ( publishBtn ) {
			publishBtn.click();
		}
	}

	function goToStep( index ) {
		if ( index < 0 || index >= config.steps.length ) {
			return;
		}

		currentStepNodes.forEach( function ( el ) {
			releaseNode( el, 'none' ); // Opuszczony krok — wraca do stanu „schowany", jak reszta postboxów w trakcie sesji.
		} );
		currentStepNodes = [];

		config.steps[ index ].selectors.forEach( function ( selector ) {
			var el = document.querySelector( selector );

			if ( ! el ) {
				return; // Box nie istnieje na tym ekranie (np. qutlet-ai nieaktywne) — pomijamy, krok wtedy pusty.
			}

			rememberPosition( el );
			bodyEl.appendChild( el );
			el.style.display = '';
			currentStepNodes.push( el );
		} );

		currentStepIndex = index;
		updateChrome();
	}

	function rememberPosition( el ) {
		if ( originalPositions.has( el ) ) {
			return;
		}

		var placeholder = document.createComment( 'qutlet-wizard-placeholder' );
		el.parentNode.insertBefore( placeholder, el );

		originalPositions.set( el, { placeholder: placeholder, display: el.style.display } );
	}

	function releaseNode( el, display ) {
		var pos = originalPositions.get( el );

		if ( ! pos || ! pos.placeholder.parentNode ) {
			return;
		}

		pos.placeholder.parentNode.insertBefore( el, pos.placeholder );
		el.style.display = display;
	}

	function updateChrome() {
		stepDots.forEach( function ( dot, i ) {
			dot.classList.toggle( 'is-active', i === currentStepIndex );
			dot.classList.toggle( 'is-done', i < currentStepIndex );
		} );

		backBtn.disabled = 0 === currentStepIndex;
		nextBtn.textContent = ( currentStepIndex === config.steps.length - 1 ) ? config.i18n.finish : config.i18n.next;
	}

	function buildOverlay() {
		var el = document.createElement( 'div' );
		el.className = 'qutlet-wizard-overlay';
		el.hidden = true;

		var dotsHtml = config.steps.map( function ( step, i ) {
			return '<li data-qutlet-wizard-step-index="' + i + '">' +
				'<span class="qutlet-wizard-dot"></span>' +
				'<span class="qutlet-wizard-step-label">' + escapeHtml( step.title ) + '</span>' +
				'</li>';
		} ).join( '' );

		el.innerHTML =
			'<div class="qutlet-wizard-card" role="dialog" aria-modal="true" aria-label="' + escapeHtml( config.i18n.title ) + '">' +
				'<div class="qutlet-wizard-header">' +
					'<h2>' + escapeHtml( config.i18n.title ) + '</h2>' +
					'<button type="button" class="qutlet-wizard-close" data-qutlet-wizard-close aria-label="' + escapeHtml( config.i18n.close ) + '">&times;</button>' +
				'</div>' +
				'<ol class="qutlet-wizard-steps" data-qutlet-wizard-steps>' + dotsHtml + '</ol>' +
				'<div class="qutlet-wizard-body" data-qutlet-wizard-body></div>' +
				'<div class="qutlet-wizard-actions">' +
					'<button type="button" class="button" data-qutlet-wizard-back>' + escapeHtml( config.i18n.back ) + '</button>' +
					'<button type="button" class="button button-primary button-hero" data-qutlet-wizard-next>' + escapeHtml( config.i18n.next ) + '</button>' +
				'</div>' +
			'</div>';

		return el;
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = String( str );

		return div.innerHTML;
	}
} )();
