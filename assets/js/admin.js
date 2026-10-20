/**
 * WP Logbook admin screens.
 *
 * QSO form: live UTC clock, band from frequency, default RST per mode and
 * the "previous contacts" panel and the logbook picker. Logbook list:
 * batched QRZ.com uploads. Import: title of a new logbook. Settings and
 * Logbooks: QRZ.com connection test.
 */
( function () {
	'use strict';

	var config = window.wplbAdmin || {};
	var i18n = config.i18n || {};

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( text ).replace( /%(\d+)\$s/g, function ( match, index ) {
			var value = args[ index - 1 ];
			return value === undefined ? match : value;
		} ).replace( /%s/g, function () {
			return args.length ? args.shift() : '';
		} );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		Object.keys( data ).forEach( function ( key ) {
			var value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.append( key, value );
			}
		} );
		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} ).then( parse );
	}

	function get( action, data ) {
		var params = new URLSearchParams( data );
		params.append( 'action', action );
		return fetch( config.ajaxUrl + '?' + params.toString(), {
			credentials: 'same-origin',
		} ).then( parse );
	}

	function parse( response ) {
		return response.json().catch( function () {
			throw new Error( i18n.requestFailed );
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) || i18n.requestFailed );
			}
			return json.data;
		} );
	}

	function element( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function pad( number ) {
		return ( number < 10 ? '0' : '' ) + number;
	}

	/* Upper-case callsign, grid and reference fields as you type. */
	document.addEventListener( 'input', function ( event ) {
		var target = event.target;
		if ( target.classList && target.classList.contains( 'wplb-uppercase' ) && target.type !== 'password' ) {
			var start = target.selectionStart;
			var end = target.selectionEnd;
			var upper = target.value.toUpperCase();
			if ( target.id === 'wplb-gridsquare' || target.id === 'wplb-my-gridsquare' ) {
				// Grid squares are conventionally written FN31pr.
				upper = upper.slice( 0, 4 ) + upper.slice( 4, 6 ).toLowerCase() + upper.slice( 6 );
			}
			if ( upper !== target.value ) {
				target.value = upper;
				target.setSelectionRange( start, end );
			}
		}
	} );

	/* Confirm deletions. */
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( '.wplb-delete-link' );
		if ( link && ! window.confirm( i18n.confirmDeleteOne ) ) {
			event.preventDefault();
		}
	} );

	initQsoForm();
	initList();
	initImport();
	initSettings();

	function initQsoForm() {
		var form = document.getElementById( 'wplb-qso-form' );
		if ( ! form ) {
			return;
		}

		var isNew = form.getAttribute( 'data-new' ) === '1';
		var qsoId = form.getAttribute( 'data-id' ) || '0';
		var field = function ( id ) {
			return document.getElementById( id );
		};
		var dateOn = field( 'wplb-time-on' );
		var timeOn = field( 'wplb-time-on-time' );
		var freq = field( 'wplb-freq' );
		var band = field( 'wplb-band' );
		var mode = field( 'wplb-mode' );
		var rstSent = field( 'wplb-rst-sent' );
		var rstRcvd = field( 'wplb-rst-rcvd' );
		var callsign = field( 'wplb-callsign' );
		var logbook = field( 'wplb-logbook' );
		var station = field( 'wplb-station-callsign' );

		// A new QSO takes the station callsign of the logbook it goes into,
		// unless the callsign was changed by hand.
		if ( logbook && station && isNew ) {
			var stationOf = function () {
				var option = logbook.options[ logbook.selectedIndex ];
				return option ? option.getAttribute( 'data-station' ) || '' : '';
			};
			var previous = stationOf();
			logbook.addEventListener( 'change', function () {
				var next = stationOf();
				if ( station.value.trim().toUpperCase() === previous.toUpperCase() || station.value.trim() === '' ) {
					station.value = next;
				}
				previous = next;
			} );
		}

		function setNow( dateInput ) {
			var timeInput = field( dateInput.id + '-time' );
			var now = new Date();
			dateInput.value = now.getUTCFullYear() + '-' + pad( now.getUTCMonth() + 1 ) + '-' + pad( now.getUTCDate() );
			if ( timeInput ) {
				timeInput.value = pad( now.getUTCHours() ) + ':' + pad( now.getUTCMinutes() ) + ':' + pad( now.getUTCSeconds() );
			}
		}

		form.querySelectorAll( '.wplb-now' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var target = field( button.getAttribute( 'data-target' ) );
				if ( target ) {
					setNow( target );
					stopClock();
				}
			} );
		} );

		// A new QSO starts "now": keep the start time ticking until it is edited.
		var clock = null;
		function stopClock() {
			if ( clock ) {
				window.clearInterval( clock );
				clock = null;
			}
		}
		if ( isNew && dateOn && timeOn && ! form.querySelector( '.wplb-has-error' ) ) {
			setNow( dateOn );
			clock = window.setInterval( function () {
				setNow( dateOn );
			}, 1000 );
			[ dateOn, timeOn ].forEach( function ( input ) {
				input.addEventListener( 'focus', stopClock );
			} );
		}
		form.addEventListener( 'submit', stopClock );

		// Band follows the frequency.
		if ( freq && band ) {
			freq.addEventListener( 'input', function () {
				var mhz = parseFloat( freq.value.replace( ',', '.' ) );
				if ( isNaN( mhz ) ) {
					return;
				}
				( config.bands || [] ).some( function ( entry ) {
					if ( mhz >= entry[ 1 ] && mhz <= entry[ 2 ] ) {
						band.value = entry[ 0 ];
						return true;
					}
					return false;
				} );
			} );
		}

		// Default signal report for the mode, unless one was typed.
		function defaultRst( value ) {
			var parts = ( value || '' ).split( '|' );
			var name = parts[ 1 ] || parts[ 0 ];
			if ( [ 'SSB', 'AM', 'FM', 'DIGITALVOICE' ].indexOf( parts[ 0 ] ) !== -1 ) {
				return '59';
			}
			if ( [ 'FT8', 'FT4', 'FT2', 'JS8', 'Q65', 'FST4', 'FST4W', 'MSK144', 'JT65', 'JT9', 'JT4', 'JT6M', 'QRA64', 'WSPR', 'ISCAT', 'FSK441' ].indexOf( name ) !== -1 ) {
				return ''; // Reports are in dB.
			}
			if ( [ 'SSTV' ].indexOf( name ) !== -1 ) {
				return '595';
			}
			if ( [ 'ATV', 'FAX', 'PKT', 'DYNAMIC', 'ARDOP', 'WINMOR', 'PAC', 'PAX' ].indexOf( parts[ 0 ] ) !== -1 ) {
				return '';
			}
			return '599';
		}
		var lastDefault = mode ? defaultRst( mode.value ) : '';
		function applyRst( force ) {
			var value = defaultRst( mode.value );
			[ rstSent, rstRcvd ].forEach( function ( input ) {
				if ( input && ( force || input.value === '' || input.value === lastDefault ) ) {
					input.value = value;
				}
			} );
			lastDefault = value;
		}
		if ( mode ) {
			mode.addEventListener( 'change', function () {
				applyRst( false );
			} );
			if ( isNew && rstSent && rstSent.value === '' && rstRcvd && rstRcvd.value === '' ) {
				applyRst( true );
			}
		}

		// Previous contacts with the station being logged.
		var panel = field( 'wplb-lookup' );
		var lookupTimer = null;
		var lastLookup = '';
		function lookup() {
			var call = callsign.value.trim().toUpperCase();
			if ( call === lastLookup ) {
				return;
			}
			lastLookup = call;
			if ( call.length < 3 ) {
				panel.hidden = true;
				return;
			}
			get( 'wplb_lookup', { nonce: config.nonces.lookup, callsign: call, exclude: qsoId } ).then( function ( data ) {
				if ( call !== lastLookup ) {
					return;
				}
				renderLookup( data );
			} ).catch( function () {
				panel.hidden = true;
			} );
		}
		function renderLookup( data ) {
			var body = panel.querySelector( '.wplb-lookup-body' );
			body.textContent = '';
			body.appendChild( element( 'p', '', data.total ? format( i18n.previousCount, data.total ) : i18n.firstContact ) );
			if ( data.items.length ) {
				var list = element( 'ul', 'wplb-lookup-list' );
				data.items.forEach( function ( item ) {
					var li = element( 'li' );
					var link = element( 'a', '', item.callsign );
					link.href = item.url;
					li.appendChild( link );
					li.appendChild( document.createTextNode( ' ' + item.time + ' · ' + item.band + ' · ' + item.mode ) );
					list.appendChild( li );
				} );
				body.appendChild( list );
			}
			// Fill empty fields of a new QSO from the last contact with this station.
			var filled = false;
			if ( isNew && data.fill ) {
				Object.keys( data.fill ).forEach( function ( key ) {
					var input = field( 'wplb-' + key.replace( /_/g, '-' ) );
					if ( input && input.value === '' ) {
						input.value = data.fill[ key ];
						input.classList.add( 'wplb-filled' );
						filled = true;
					}
				} );
			}
			if ( filled ) {
				body.appendChild( element( 'p', 'description', i18n.filledFromLastQso ) );
			}
			panel.hidden = false;
		}
		if ( callsign && panel ) {
			callsign.addEventListener( 'input', function () {
				window.clearTimeout( lookupTimer );
				lookupTimer = window.setTimeout( lookup, 400 );
			} );
			if ( callsign.value ) {
				lookup();
			}
		}
	}

	function initList() {
		var panel = document.getElementById( 'wplb-qrz-panel' );
		var form = document.getElementById( 'wplb-list-form' );

		// Confirm bulk deletion; upload selected QSOs in batches.
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var submitter = event.submitter;
				var select = submitter && submitter.id === 'doaction2' ? form.querySelector( '#bulk-action-selector-bottom' ) : form.querySelector( '#bulk-action-selector-top' );
				if ( submitter && ( submitter.id === 'search-submit' || submitter.name === 'filter_action' ) ) {
					return;
				}
				var action = select ? select.value : '';
				var ids = Array.prototype.map.call( form.querySelectorAll( 'input[name="qso[]"]:checked' ), function ( input ) {
					return input.value;
				} );
				if ( action === 'delete' && ids.length && ! window.confirm( i18n.confirmDelete ) ) {
					event.preventDefault();
				}
				if ( action === 'qrz_upload' && panel ) {
					event.preventDefault();
					if ( ! ids.length ) {
						window.alert( i18n.selectFirst );
						return;
					}
					runUpload( ids );
				}
			} );
		}

		var button = document.getElementById( 'wplb-qrz-upload-pending' );
		if ( button ) {
			button.addEventListener( 'click', function () {
				runUpload( null, parseInt( button.getAttribute( 'data-total' ), 10 ) || 0 );
			} );
		}

		function runUpload( ids, total ) {
			var progress = panel.querySelector( '.wplb-qrz-progress' );
			var bar = progress.querySelector( 'progress' );
			var text = progress.querySelector( '.wplb-qrz-progress-text' );
			var errors = panel.querySelector( '.wplb-qrz-errors' );
			var queue = ids ? ids.slice() : null;
			var totals = { processed: 0, uploaded: 0, failed: 0 };
			var batchSize = config.batchSize || 10;

			total = ids ? ids.length : total;
			errors.textContent = '';
			progress.hidden = false;
			panel.querySelectorAll( 'button' ).forEach( function ( b ) {
				b.disabled = true;
			} );

			function update() {
				bar.max = Math.max( total, 1 );
				bar.value = totals.processed;
				text.textContent = format( i18n.progress, totals.processed, total );
			}

			function finish( stopMessage ) {
				bar.value = bar.max;
				text.textContent = format( i18n.done, totals.uploaded, totals.failed ) + ( stopMessage ? ' ' + i18n.stopped + ' ' + stopMessage : '' );
				var reload = element( 'a', 'button', i18n.reload );
				reload.href = window.location.href;
				text.appendChild( document.createTextNode( ' ' ) );
				text.appendChild( reload );
			}

			function next() {
				var data = { nonce: config.nonces.upload };
				if ( queue ) {
					if ( ! queue.length ) {
						return finish();
					}
					data.ids = queue.splice( 0, batchSize );
				}
				post( 'wplb_qrz_upload', data ).then( function ( result ) {
					totals.processed += result.processed;
					totals.uploaded += result.uploaded;
					totals.failed += result.failed;
					( result.errors || [] ).forEach( function ( message ) {
						errors.appendChild( element( 'li', '', message ) );
					} );
					if ( ! queue ) {
						total = Math.max( total, totals.processed + result.remaining );
					}
					update();
					if ( result.stopped ) {
						return finish( result.stop_message );
					}
					if ( queue || ( result.remaining > 0 && result.processed > 0 ) ) {
						return next();
					}
					finish();
				} ).catch( function ( error ) {
					finish( error.message );
				} );
			}

			update();
			next();
		}
	}

	function initImport() {
		var select = document.getElementById( 'wplb-import-logbook' );
		var row = document.querySelector( '.wplb-new-logbook' );
		if ( ! select || ! row ) {
			return;
		}
		var title = row.querySelector( 'input' );
		function toggle() {
			var isNewLogbook = select.value === 'new';
			row.hidden = ! isNewLogbook;
			title.required = isNewLogbook;
		}
		select.addEventListener( 'change', function () {
			toggle();
			if ( select.value === 'new' ) {
				title.focus();
			}
		} );
		toggle();
	}

	function initSettings() {
		var button = document.getElementById( 'wplb-qrz-test' );
		if ( ! button ) {
			return;
		}
		var output = document.getElementById( 'wplb-qrz-test-result' );
		var keyInput = document.getElementById( 'wplb-qrz-key' );
		var stationInput = document.getElementById( 'wplb-logbook-station' );

		button.addEventListener( 'click', function () {
			output.className = 'wplb-test-result';
			output.textContent = i18n.testing;
			button.disabled = true;
			post( 'wplb_qrz_test', {
				nonce: config.nonces.test,
				key: keyInput ? keyInput.value.trim() : '',
				logbook: button.getAttribute( 'data-logbook' ) || '0',
				station: stationInput ? stationInput.value.trim() : '',
			} ).then( function ( data ) {
				output.className = 'wplb-test-result is-ok';
				output.textContent = '';
				output.appendChild( element( 'strong', '', data.message ) );
				if ( data.details && data.details.length ) {
					var list = element( 'dl' );
					data.details.forEach( function ( row ) {
						list.appendChild( element( 'dt', '', row.label ) );
						list.appendChild( element( 'dd', '', row.value ) );
					} );
					output.appendChild( list );
				}
				if ( data.warning ) {
					output.appendChild( element( 'p', 'wplb-test-warning', data.warning ) );
				}
			} ).catch( function ( error ) {
				output.className = 'wplb-test-result is-error';
				output.textContent = error.message;
			} ).then( function () {
				button.disabled = false;
			} );
		} );
	}
}() );
