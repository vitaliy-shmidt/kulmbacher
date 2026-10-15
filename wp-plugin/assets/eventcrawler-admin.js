/**
 * Eventcrawler – Crawler-Steuerung im Admin (docs/06-admin.md, Abschnitt 3).
 *
 * Eine Quelle = ein Request an admin-ajax.php (action "eventcrawler_crawl_source").
 * "Alle Quellen" arbeitet die Quellen aus window.eventcrawlerCrawl.sources (serverseitig aus dem Adapter-Register)
 * nacheinander ab. Kein Framework, keine hart codierten Quellen.
 */
( function () {
	'use strict';

	var config = window.eventcrawlerCrawl;
	var STORAGE_KEY = 'eventcrawlerCrawlSummary';
	var REQUEST_TIMEOUT_MS = 150000; // etwas über dem PHP-Limit von 120 s je Quelle
	var running = false;

	function el( id ) {
		return document.getElementById( id );
	}

	function formatStats( stats ) {
		return stats.found + ' gefunden, ' + stats['new'] + ' neu, ' + stats.updated + ' aktualisiert, ' +
			stats.unchanged + ' unverändert, ' + stats.filtered + ' gefiltert, ' + stats.failed + ' fehlerhaft.';
	}

	function formatDuration( seconds ) {
		return typeof seconds === 'number' ? ' (' + seconds.toFixed( 1 ).replace( '.', ',' ) + ' s)' : '';
	}

	/**
	 * Zeigt Zeilen im Fortschrittsbereich an (nur textContent, nie HTML aus Antworten).
	 */
	function render( box, type, heading, lines ) {
		box.className = 'notice inline notice-' + type;
		box.hidden = false;
		box.textContent = '';
		var p = document.createElement( 'p' );
		var strong = document.createElement( 'strong' );
		strong.textContent = heading;
		p.appendChild( strong );
		box.appendChild( p );
		var ul = document.createElement( 'ul' );
		lines.forEach( function ( line ) {
			var li = document.createElement( 'li' );
			li.textContent = line;
			ul.appendChild( li );
		} );
		box.appendChild( ul );
	}

	/**
	 * Crawlt genau eine Quelle. Liefert immer ein Ergebnisobjekt (ok + stats oder ok=false + message), wirft nie.
	 */
	function crawlOne( source ) {
		var body = new FormData();
		body.append( 'action', 'eventcrawler_crawl_source' );
		body.append( 'nonce', config.nonce );
		body.append( 'source', source.id );

		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var timer = controller ? setTimeout( function () { controller.abort(); }, REQUEST_TIMEOUT_MS ) : null;

		return fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', signal: controller ? controller.signal : undefined } )
			.then( function ( response ) {
				return response.text().then( function ( text ) {
					var json = null;
					try {
						json = JSON.parse( text );
					} catch ( e ) {
						json = null;
					}
					if ( json && json.success === true && json.data && json.data.stats ) {
						return { ok: true, stats: json.data.stats, duration: json.data.duration };
					}
					if ( json && json.success === false && json.data ) {
						return { ok: false, message: json.data.message || 'Unbekannter Fehler.', duration: json.data.duration };
					}
					if ( text === '-1' || response.status === 403 ) {
						return { ok: false, message: 'Sicherheitsprüfung fehlgeschlagen (Sitzung abgelaufen? Seite neu laden).' };
					}
					return { ok: false, message: 'Ungültige Antwort vom Server (HTTP ' + response.status + ').' };
				} );
			} )
			.catch( function ( error ) {
				var aborted = error && error.name === 'AbortError';
				return { ok: false, message: aborted ? 'Zeitüberschreitung nach ' + ( REQUEST_TIMEOUT_MS / 1000 ) + ' s.' : 'Netzwerkfehler: ' + ( error && error.message ? error.message : 'Verbindung abgebrochen.' ) };
			} )
			.then( function ( result ) {
				if ( timer ) {
					clearTimeout( timer );
				}
				return result;
			} );
	}

	function setBusy( busy ) {
		running = busy;
		el( 'eventcrawler-crawl-start' ).disabled = busy;
		el( 'eventcrawler-crawl-source' ).disabled = busy;
	}

	/**
	 * Arbeitet die Quellen sequentiell ab: Request starten, Antwort abwarten, anzeigen, nächste Quelle.
	 */
	function run( sources ) {
		var box = el( 'eventcrawler-crawl-progress' );
		var results = [];
		var single = sources.length === 1;

		function progressLines( current ) {
			return sources.map( function ( source, i ) {
				var r = results[ i ];
				if ( r ) {
					return r.ok ? '✓ ' + source.label + ': ' + formatStats( r.stats ) + formatDuration( r.duration )
						: '✗ ' + source.label + ': fehlgeschlagen – ' + r.message + formatDuration( r.duration );
				}
				return i === current ? '→ ' + source.label + ' wird verarbeitet …' : '… ' + source.label + ' wartet';
			} );
		}

		function next( i ) {
			if ( i >= sources.length ) {
				return Promise.resolve();
			}
			render( box, 'info', 'Crawler läuft – Quelle ' + ( i + 1 ) + ' von ' + sources.length + ': ' + sources[ i ].label, progressLines( i ) );
			return crawlOne( sources[ i ] ).then( function ( result ) {
				results[ i ] = result;
				return next( i + 1 ); // Fehler einer Quelle hält die nächste nicht auf.
			} );
		}

		return next( 0 ).then( function () {
			var ok = results.filter( function ( r ) { return r.ok; } );
			var total = { found: 0, 'new': 0, updated: 0, unchanged: 0, filtered: 0, failed: 0 };
			ok.forEach( function ( r ) {
				Object.keys( total ).forEach( function ( key ) {
					total[ key ] += Number( r.stats[ key ] ) || 0;
				} );
			} );
			var failedSources = results.length - ok.length;
			var type = failedSources > 0 ? 'error' : ( total.failed > 0 ? 'warning' : 'success' );
			var heading;
			if ( single ) {
				heading = ok.length ? 'Crawler abgeschlossen (' + sources[ 0 ].label + '): ' + formatStats( total )
					: 'Crawler fehlgeschlagen (' + sources[ 0 ].label + ').';
			} else {
				// "fehlerhaft" = einzelne Einträge; fehlgeschlagene Quellen werden getrennt genannt.
				heading = 'Crawler abgeschlossen: ' + ok.length + ' von ' + results.length + ' Quellen erfolgreich' +
					( failedSources ? ' (' + failedSources + ' fehlgeschlagen)' : '' ) + '. Summe der erfolgreichen Quellen: ' + formatStats( total );
			}
			return { type: type, heading: heading, lines: progressLines( -1 ) };
		} );
	}

	function start() {
		if ( running ) {
			return; // Doppelklick
		}
		var choice = el( 'eventcrawler-crawl-source' ).value;
		var sources = choice === 'all' ? config.sources : config.sources.filter( function ( s ) { return s.id === choice; } );
		if ( ! sources.length ) {
			return;
		}

		setBusy( true );
		run( sources ).then( function ( summary ) {
			// Liste neu laden; die Zusammenfassung überlebt den Reload nur im Browser (sessionStorage, kein Server-Status).
			try {
				window.sessionStorage.setItem( STORAGE_KEY, JSON.stringify( summary ) );
				window.location.reload();
				return;
			} catch ( e ) {
				render( el( 'eventcrawler-crawl-progress' ), summary.type, summary.heading + ' Liste bitte neu laden.', summary.lines );
			}
			setBusy( false );
		} ).catch( function ( error ) {
			render( el( 'eventcrawler-crawl-progress' ), 'error', 'Crawler abgebrochen: ' + ( error && error.message ? error.message : 'unbekannter Fehler' ), [] );
			setBusy( false );
		} );
	}

	function init() {
		var button = el( 'eventcrawler-crawl-start' );
		if ( ! button || ! config || ! Array.isArray( config.sources ) ) {
			return;
		}
		// Zusammenfassung des letzten Laufs nach dem Reload anzeigen (einmalig).
		try {
			var stored = window.sessionStorage.getItem( STORAGE_KEY );
			if ( stored ) {
				window.sessionStorage.removeItem( STORAGE_KEY );
				var summary = JSON.parse( stored );
				render( el( 'eventcrawler-crawl-progress' ), summary.type, summary.heading, summary.lines );
			}
		} catch ( e ) {
			// sessionStorage nicht verfügbar: nichts anzeigen.
		}
		button.addEventListener( 'click', start );
		button.disabled = false;
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

/**
 * Eventcrawler – Detail-Modal der Veranstaltungsliste (docs/06-admin.md, Abschnitt 11).
 *
 * Read-only Debug-Ansicht: Klick auf "Details" lädt die gespeicherten Werte EINES Events per POST an admin-ajax.php
 * (action "eventcrawler_get_event_details", nur event_id + Nonce). Werte werden unverändert angezeigt, NULL als "NULL".
 * Alle Werte nur über textContent bzw. als geprüfter http(s)-Link – nie als HTML.
 */
( function () {
	'use strict';

	var config = window.eventcrawlerDetails;
	var TEXT_FIELDS = [ 'title', 'description', 'venue_name', 'venue_address', 'city' ]; // Fließtext, keine Monospace-Schrift
	var URL_FIELDS = [ 'event_url', 'source_url' ];
	var modal = null; // { root, dialog, title, body, copy, copyStatus }
	var lastFocus = null;
	var requestId = 0;
	var currentFields = null;

	function make( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( typeof text === 'string' ) {
			node.textContent = text;
		}
		return node;
	}

	function build() {
		var root = make( 'div', 'eventcrawler-modal' );
		root.hidden = true;
		var dialog = make( 'div', 'eventcrawler-modal__dialog' );
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', 'eventcrawler-modal-title' );
		dialog.tabIndex = -1;

		var header = make( 'div', 'eventcrawler-modal__header' );
		var title = make( 'h2', '', 'Event DB-Daten' );
		title.id = 'eventcrawler-modal-title';
		var x = make( 'button', 'eventcrawler-modal__x', '×' );
		x.type = 'button';
		x.setAttribute( 'aria-label', 'Schließen' );
		header.appendChild( title );
		header.appendChild( x );

		var body = make( 'div', 'eventcrawler-modal__body' );

		var footer = make( 'div', 'eventcrawler-modal__footer' );
		var copy = make( 'button', 'button', 'Alle Werte kopieren' );
		copy.type = 'button';
		var copyStatus = make( 'span', 'eventcrawler-modal__copy-status' );
		copyStatus.setAttribute( 'aria-live', 'polite' );
		var close = make( 'button', 'button button-primary', 'Schließen' );
		close.type = 'button';
		footer.appendChild( copy );
		footer.appendChild( copyStatus );
		footer.appendChild( close );

		dialog.appendChild( header );
		dialog.appendChild( body );
		dialog.appendChild( footer );
		root.appendChild( dialog );
		document.body.appendChild( root );

		x.addEventListener( 'click', hide );
		close.addEventListener( 'click', hide );
		copy.addEventListener( 'click', copyAll );
		root.addEventListener( 'click', function ( event ) {
			if ( event.target === root ) {
				hide(); // Klick auf den abgedunkelten Hintergrund
			}
		} );
		document.addEventListener( 'keydown', onKeydown );

		return { root: root, dialog: dialog, title: title, body: body, copy: copy, copyStatus: copyStatus };
	}

	function onKeydown( event ) {
		if ( ! modal || modal.root.hidden ) {
			return;
		}
		if ( event.key === 'Escape' || event.key === 'Esc' ) {
			event.preventDefault();
			hide();
			return;
		}
		if ( event.key === 'Tab' ) {
			// Fokus im Dialog halten.
			var focusable = Array.prototype.filter.call(
				modal.dialog.querySelectorAll( 'a[href], button:not([disabled])' ),
				function ( node ) { return node.offsetParent !== null; }
			);
			if ( ! focusable.length ) {
				return;
			}
			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];
			if ( event.shiftKey && ( document.activeElement === first || document.activeElement === modal.dialog ) ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}
	}

	function setStatus( text, isError ) {
		modal.body.textContent = '';
		modal.body.appendChild( make( 'p', isError ? 'eventcrawler-modal__error' : 'eventcrawler-modal__loading', text ) );
	}

	function show( eventId ) {
		modal = modal || build();
		lastFocus = document.activeElement;
		modal.title.textContent = 'Event DB-Daten (ID ' + eventId + ')';
		setStatus( 'Lade Daten…', false );
		modal.copy.disabled = true;
		modal.copyStatus.textContent = '';
		currentFields = null;
		modal.root.hidden = false;
		document.body.classList.add( 'eventcrawler-modal-open' );
		modal.dialog.focus();
	}

	function hide() {
		if ( ! modal || modal.root.hidden ) {
			return;
		}
		requestId++; // eine noch laufende Antwort wird verworfen
		modal.root.hidden = true;
		document.body.classList.remove( 'eventcrawler-modal-open' );
		if ( lastFocus && typeof lastFocus.focus === 'function' ) {
			lastFocus.focus();
		}
	}

	/**
	 * Ein Wert genau wie gespeichert: null -> "NULL", '' -> "" (leerer String), all_day mit Klartext, URLs als Link.
	 */
	function renderValue( name, value ) {
		var dd = make( 'dd', TEXT_FIELDS.indexOf( name ) === -1 ? 'eventcrawler-mono' : '' );
		if ( value === null || value === '' ) {
			var marker = make( 'span', 'eventcrawler-db-null', value === null ? 'NULL' : '""' );
			marker.title = value === null ? 'Datenbankwert NULL' : 'leerer String';
			dd.appendChild( marker );
			return dd;
		}
		if ( URL_FIELDS.indexOf( name ) !== -1 && /^https?:\/\//i.test( value ) ) {
			var a = make( 'a', '', value );
			a.href = value;
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			dd.appendChild( a );
			return dd;
		}
		dd.textContent = value;
		if ( name === 'all_day' && ( value === '0' || value === '1' ) ) {
			dd.appendChild( make( 'span', 'eventcrawler-db-hint', value === '1' ? ' (Ja)' : ' (Nein)' ) );
		}
		return dd;
	}

	function renderFields( fields ) {
		modal.body.textContent = '';
		var dl = make( 'dl', 'eventcrawler-db-fields' );
		fields.forEach( function ( field ) {
			dl.appendChild( make( 'dt', '', String( field[ 0 ] ) ) );
			dl.appendChild( renderValue( String( field[ 0 ] ), field[ 1 ] === null ? null : String( field[ 1 ] ) ) );
		} );
		modal.body.appendChild( dl );
		modal.body.scrollTop = 0;
	}

	function load( eventId ) {
		var current = ++requestId;
		var body = new FormData();
		body.append( 'action', 'eventcrawler_get_event_details' );
		body.append( 'nonce', config.nonce );
		body.append( 'event_id', eventId );

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.text().then( function ( text ) {
					var json = null;
					try {
						json = JSON.parse( text );
					} catch ( e ) {
						json = null;
					}
					if ( json && json.success === true && json.data && Array.isArray( json.data.fields ) ) {
						return { ok: true, fields: json.data.fields };
					}
					if ( response.status === 404 ) {
						return { ok: false, message: 'Das Event existiert nicht (mehr). Bitte die Liste neu laden.' };
					}
					if ( response.status === 403 || text === '-1' || text === '0' ) {
						return { ok: false, message: 'Keine Berechtigung oder Sitzung abgelaufen. Bitte die Seite neu laden.' };
					}
					return { ok: false, message: 'Ungültige Antwort vom Server (HTTP ' + response.status + ').' };
				} );
			} )
			.catch( function () {
				return { ok: false, message: 'Netzwerkfehler.' };
			} )
			.then( function ( result ) {
				if ( current !== requestId ) {
					return; // Modal inzwischen geschlossen oder anderes Event angefordert
				}
				if ( result.ok ) {
					currentFields = result.fields;
					renderFields( result.fields );
					modal.copy.disabled = false;
				} else {
					setStatus( 'Event konnte nicht geladen werden. ' + result.message, true );
				}
			} );
	}

	/**
	 * Textform "name: wert" je Zeile, NULL bzw. "" wie im Modal.
	 */
	function copyText() {
		return currentFields.map( function ( field ) {
			var value = field[ 1 ];
			return field[ 0 ] + ': ' + ( value === null ? 'NULL' : ( value === '' ? '""' : value ) );
		} ).join( '\n' );
	}

	function copyAll() {
		if ( ! currentFields ) {
			return;
		}
		var text = copyText();
		var fallback = function () {
			var area = make( 'textarea', 'eventcrawler-copy-area' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			modal.dialog.appendChild( area );
			area.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			modal.dialog.removeChild( area );
			modal.copyStatus.textContent = ok ? 'Kopiert.' : 'Kopieren nicht möglich.';
			modal.copy.focus();
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( function () {
				modal.copyStatus.textContent = 'Kopiert.';
			}, fallback );
		} else {
			fallback();
		}
	}

	function init() {
		if ( ! config || ! config.ajaxUrl ) {
			return;
		}
		document.addEventListener( 'click', function ( event ) {
			var button = event.target && event.target.closest ? event.target.closest( '.eventcrawler-details' ) : null;
			if ( ! button ) {
				return;
			}
			event.preventDefault();
			var eventId = button.getAttribute( 'data-event-id' ) || '';
			if ( ! /^[1-9][0-9]*$/.test( eventId ) ) {
				return;
			}
			show( eventId );
			load( eventId );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
