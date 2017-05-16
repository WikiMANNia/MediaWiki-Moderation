/*
	This code handles VisualEditor and other API-based JavaScript editors.

	A. Why is this needed?

	1) api.php?action=edit returns error when we abort onPageContentSave hook,
	so VisualEditor panics "The edit wasn't saved! Unknown error!".
	2) when JavaScript-based editors load the main <textarea>
	(their analogue of #wpTextbox1 in index.php?action=edit),
	they ignore Preload hooks like onEditFormInitialText, so if this user
	has a pending change, it won't be preloaded at all.

	B. Why such an unusual implementation?

	It turns out, neither API (in MediaWiki core) nor VisualEditor provide
	any means of altering the API response via a PHP hook.
	Until this solution was found, it looked impossible to make Moderation
	work with VisualEditor by any changes on the Moderation side.

	C. How does this solution work?

	We intercept all AJAX responses (sic!), and if we determine that this
	response is either (1) or (2) (see above), WE FAKE THE AJAX RESPONSE.

	In (1), we replace error with "edit saved successfully!"
	In (2), we inject preloaded text from showUnmoderatedEdit().

	See also: [ext.moderation.preload.visualeditor.js].
*/

( function ( mw, $ ) {
	'use strict';

	mw.moderation = mw.moderation || {};
	mw.moderation.ajaxhook = mw.moderation.ajaxhook || {};

	var lastQuery = null;

	/* Remember the query from the last mw.Api call.
		We don't want to parse raw parameter "sendBody" in XMLHttpRequest.send(),
		because "sendBody" may be a FormData object (which can't be analyzed in IE).
	*/
	mw.moderation.trackAjax = function( apiObj ) {
		var oldFunc = apiObj.prototype.ajax;
		apiObj.prototype.ajax = function( parameters, ajaxOptions ) {
			lastQuery = parameters;

			var ret = oldFunc.apply( this, arguments );
			lastQuery = null;

			return ret;
		};
	};

	mw.loader.using( 'mediawiki.api', function() {
		mw.moderation.trackAjax( mw.Api );
	} );

	/* Make an API response for action=edit.
		This affects most API-based JavaScript editors, including MobileFrontend.
	*/
	mw.moderation.ajaxhook['edit'] = function() {
		var ret = {},
			timestamp = "2016-12-08T12:33:23Z"; /* TODO: recalculate */

		ret.edit = {
			"result": "Success", /* Uppercase */
			"pageid": mw.config.get( 'wgArticleId' ),
			"title": mw.config.get( 'wgTitle' ),
			"contentmodel": mw.config.get( 'wgPageContentModel' ),
			"oldrevid": mw.config.get( 'wgRevisionId' ),
			"newrevid": 0, /* NOTE: change if this causes problems in any API-based editors */
			"newtimestamp": timestamp
		};

		if ( ret.edit.pageid ) {
			ret.edit.new = "";
		}

		mw.hook( 'moderation.ajaxhook.edit' ).fire();
		return ret;
	}

	/**
		@brief Main logic of AJAX response rewriting.
		@param query API request, e.g. { action: "edit", "title": "Testpage1", ... }.
		@param ret API response, e.g. { edit: { result: "success", ... } }.
		@returns New API response (if overwrite is needed) or false (if no need to overwrite).
	*/
	function rewriteAjaxResponse( query, ret ) {

		/* Check whether we need to overwrite this AJAX response or not */
		if ( ret.error && ret.error.info.indexOf( 'moderation-edit-queued' ) != -1 ) {

			/* Set cookie for [ext.moderation.notify.js].
				It means "edit was just queued for moderation".
			*/
			$.cookie( 'modqueued', '1', { path: '/' } );

			/*
				Error from api.php?action=edit: edit was queued for moderation.
				We must replace this response with "Edit saved successfully!".
			*/
			var func = mw.moderation.ajaxhook[query.action];
			if ( !func ) {
				/* Nothing to overwrite */
			}

			return func(); /* Fake a successful API response */
		}

		return false; /* Nothing to overwrite */
	};

	/*
		This hook is called on "readystatechange" event of every (!) XMLHttpRequest.
		It runs in "capture mode" (will be called before any other
		readystatechange callbacks, unless they are also in capture mode).
	*/
	function on_readystatechange_global( query ) {
		if ( this.readyState != 4 ) {
			return; /* Not ready yet */
		}

		/* Get JSON response from API */
		var ret;
		try {
			ret = JSON.parse( this.responseText );
		}
		catch ( e ) {
			return; /* Not a JSON, nothing to overwrite */
		}

		ret = rewriteAjaxResponse( query, ret );
		if(ret) {
			/* Overwrite readonly fields in this XMLHttpRequest */
			Object.defineProperty( this, 'responseText', { writable: true } );
			Object.defineProperty( this, 'status', { writable: true } );
			this.responseText = JSON.stringify( ret );
			this.status = 200;
		}
	}

	/*
		Install on_readystatechange_global() callback which will be
		called for every XMLHttpRequest, regardless of who sent it.
	*/
	var oldSend = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.send = function( sendBody ) {
		if ( lastQuery ) {
			this.addEventListener( "readystatechange",
				on_readystatechange_global.bind( this, lastQuery ),
				true // capture mode
			);
		}

		oldSend.apply( this, arguments );
	};

}( mediaWiki, jQuery ) );
