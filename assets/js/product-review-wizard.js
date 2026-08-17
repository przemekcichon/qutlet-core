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

	var originalPositions = new Map(); // el -> { parent, next } — pozycja PRZED dotknięciem przez kreator.
	var managedElements = new Set(); // każdy węzeł kiedykolwiek schowany/przeniesiony w tej sesji kreatora — pełny reset na close().
	var currentStepNodes = [];
	var currentStepIndex = 0;

	var overlay = buildOverlay();
	var stepsListEl = overlay.querySelector( '[data-qutlet-wizard-steps]' );
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
			close();

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

		// Pełny reset: KAŻDY węzeł kiedykolwiek schowany/przeniesiony w tej
		// sesji (postboxy schowane na open(), pola przenoszone między
		// krokami) wraca na oryginalne miejsce i staje się znów widoczny —
		// nie tylko bieżący krok (bug: element opuszczony przez goToStep()
		// zostawał widoczny na starym miejscu, bo tylko chowaliśmy CAŁE
		// postboxy na open(), a pojedyncze przenoszone węzły nigdy nie
		// wracały do stanu display:none).
		managedElements.forEach( function ( el ) {
			restorePosition( el );
			el.style.display = '';
		} );

		managedElements.clear();
		originalPositions.clear();

		overlay.hidden = true;
		document.body.classList.remove( 'qutlet-wizard-open' );
	}

	function goToStep( index ) {
		if ( index < 0 || index >= config.steps.length ) {
			return;
		}

		currentStepNodes.forEach( function ( el ) {
			restorePosition( el );
			el.style.display = 'none'; // Opuszczony krok — wraca do stanu „schowany", jak reszta postboxów.
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
		managedElements.add( el );

		if ( originalPositions.has( el ) ) {
			return;
		}

		originalPositions.set( el, { parent: el.parentNode, next: el.nextSibling } );
	}

	function restorePosition( el ) {
		var pos = originalPositions.get( el );

		if ( ! pos ) {
			return;
		}

		if ( pos.next && pos.next.parentNode === pos.parent ) {
			pos.parent.insertBefore( el, pos.next );
		} else {
			pos.parent.appendChild( el );
		}
	}

	function updateChrome() {
		stepsListEl.querySelectorAll( '[data-qutlet-wizard-step-index]' ).forEach( function ( dot, i ) {
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
