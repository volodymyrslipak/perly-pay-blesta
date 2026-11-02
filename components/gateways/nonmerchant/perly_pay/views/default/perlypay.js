/**
 * The overlay, printed inline after the pay button (lib/Process.php): a
 * press loads pay.js from Perly Pay with its integrity pinned and opens the
 * checkout over Blesta's page. Once paid, pay.js sends the client to
 * Blesta's "payment received" page. If anything fails — pay.js blocked, an
 * error — the link is followed as it is and the client lands on the payment
 * page itself.
 */
( function () {
	function loadPayJs( link, origin ) {
		return new Promise( function ( resolve, reject ) {
			if ( window.PerlyPay && typeof window.PerlyPay.open === 'function' ) {
				resolve();
				return;
			}
			const path = link.getAttribute( 'data-script' );
			const integrity = link.getAttribute( 'data-integrity' );
			if ( ! path || ! integrity ) {
				reject( new Error( 'pay.js is not pinned' ) );
				return;
			}
			const script = document.createElement( 'script' );
			script.src = origin + path;
			script.integrity = integrity;
			script.crossOrigin = 'anonymous';
			script.onload = function () {
				resolve();
			};
			script.onerror = reject;
			document.head.appendChild( script );
		} );
	}

	function open( link ) {
		const origin = new URL( link.href ).origin;
		return loadPayJs( link, origin ).then( function () {
			if ( ! window.PerlyPay || typeof window.PerlyPay.open !== 'function' ) {
				throw new Error( 'pay.js did not load' );
			}
			window.PerlyPay.open( {
				invoice: link.getAttribute( 'data-invoice' ),
				baseUrl: origin,
				paidUrl: link.getAttribute( 'data-paid-url' ) || window.location.href,
				mode: 'auto',
			} );
		} );
	}

	function wire() {
		const links = document.querySelectorAll( 'a.perlypay-button[data-perlypay="overlay"]' );
		Array.prototype.forEach.call( links, function ( link ) {
			if ( link.getAttribute( 'data-wired' ) === '1' || typeof window.Promise !== 'function' ) {
				return;
			}
			link.setAttribute( 'data-wired', '1' );
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				open( link ).catch( function () {
					window.location.assign( link.href );
				} );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', wire );
	} else {
		wire();
	}
} )();
