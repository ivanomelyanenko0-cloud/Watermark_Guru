( function () {
	'use strict';

	var cfg = window.wmguruAdmin;
	var form = document.getElementById( 'wmguru-form' );
	if ( ! cfg || ! form ) {
		return;
	}

	/* ---------- Media picker for the logo layer ---------- */
	form.querySelectorAll( '.wmguru-media' ).forEach( function ( box ) {
		var input = box.querySelector( 'input[type="hidden"]' );
		var img = box.querySelector( '.wmguru-media-preview' );
		var clear = box.querySelector( '.wmguru-media-clear' );

		box.querySelector( '.wmguru-media-pick' ).addEventListener( 'click', function () {
			var frame = wp.media( {
				title: cfg.i18n.chooseImage,
				button: { text: cfg.i18n.useImage },
				library: { type: 'image' },
				multiple: false,
			} );
			frame.on( 'select', function () {
				var item = frame.state().get( 'selection' ).first().toJSON();
				var sizes = item.sizes || {};
				input.value = item.id;
				img.src = ( sizes.medium || sizes.full || item ).url;
				img.hidden = false;
				clear.hidden = false;
				schedulePreview();
			} );
			frame.open();
		} );

		clear.addEventListener( 'click', function () {
			input.value = '0';
			img.hidden = true;
			clear.hidden = true;
			schedulePreview();
		} );
	} );

	/* ---------- Live preview ---------- */
	var previewImg = document.getElementById( 'wmguru-preview-img' );
	var previewMsg = document.getElementById( 'wmguru-preview-msg' );
	var previewLabel = document.getElementById( 'wmguru-preview-label' );
	var previewReset = document.getElementById( 'wmguru-preview-reset' );
	var previewId = 0;
	var timer = null;
	var seq = 0;

	function post( action, extra ) {
		var data = new FormData( form );
		// The settings form already carries options.php's own "action" field; override it.
		data.set( 'action', action );
		data.set( 'nonce', cfg.nonce );
		Object.keys( extra || {} ).forEach( function ( k ) {
			data.set( k, extra[ k ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } ).then( function ( r ) {
			return r.json();
		} );
	}

	function renderPreview() {
		var mine = ++seq;
		previewMsg.textContent = cfg.i18n.rendering;
		post( 'wmguru_preview', { preview_id: previewId } )
			.then( function ( res ) {
				if ( mine !== seq ) {
					return;
				}
				if ( res && res.success ) {
					previewImg.src = res.data.src;
					previewMsg.textContent = res.data.notice || '';
				} else {
					previewMsg.textContent = ( res && res.data && res.data.message ) || cfg.i18n.previewFailed;
				}
			} )
			.catch( function () {
				if ( mine === seq ) {
					previewMsg.textContent = cfg.i18n.previewFailed;
				}
			} );
	}

	function schedulePreview() {
		clearTimeout( timer );
		timer = setTimeout( renderPreview, 350 );
	}

	form.addEventListener( 'input', schedulePreview );
	form.addEventListener( 'change', schedulePreview );

	document.getElementById( 'wmguru-preview-pick' ).addEventListener( 'click', function () {
		var frame = wp.media( {
			title: cfg.i18n.chooseImage,
			button: { text: cfg.i18n.useImage },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', function () {
			var item = frame.state().get( 'selection' ).first().toJSON();
			previewId = item.id;
			previewLabel.textContent = item.title || '#' + item.id;
			previewReset.hidden = false;
			renderPreview();
		} );
		frame.open();
	} );

	previewReset.addEventListener( 'click', function () {
		previewId = 0;
		previewLabel.textContent = cfg.i18n.sampleImage;
		previewReset.hidden = true;
		renderPreview();
	} );

	/* ---------- Delivery test / cache purge ---------- */
	form.querySelectorAll( '[data-wmguru-action]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var result = button.parentNode.querySelector( '.wmguru-action-result' );
			result.textContent = cfg.i18n.working;
			button.disabled = true;
			post( button.getAttribute( 'data-wmguru-action' ) )
				.then( function ( res ) {
					result.textContent = ( res && res.data && res.data.message ) || '';
					if ( res && res.success && button.getAttribute( 'data-reload' ) ) {
						setTimeout( function () {
							window.location.reload();
						}, 700 );
					}
				} )
				.catch( function () {
					result.textContent = cfg.i18n.requestFailed;
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	} );

	renderPreview();
} )();
