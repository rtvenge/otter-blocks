/**
 * WordPress dependencies.
 */
import domReady from '@wordpress/dom-ready';

/**
 * Internal dependencies.
 */
import {addCaptchaOnPage} from './captcha.js';
import DisplayFormMessage from './message';

/**
 * Get the fields with their value from the form.
 *
 * @param {HTMLDivElement} form The form.
 * @returns
 */
const extractFormFields = form => {

	/** @type {Array.<HTMLDivElement>} */
	const elemsWithError = [];
	const formFieldsData = [ { label: window?.themeisleGutenbergForm?.messages['form-submission'] || 'Form submission from', value: window.location.href } ];

	const inputs = form?.querySelectorAll( '.otter-form__container .wp-block-themeisle-blocks-form-input' );
	const textarea = form?.querySelectorAll( '.otter-form__container .wp-block-themeisle-blocks-form-textarea' );

	[ ...inputs, ...textarea ]?.forEach( input => {
		const label = input.querySelector( '.otter-form-input-label__label, .otter-form-textarea-label__label' )?.innerHTML;
		const valueElem = input.querySelector( '.otter-form-input, .otter-form-textarea-input' );

		// TODO: use checkbox in the future versions
		const checked = input.querySelector( '.otter-form-input[type="checkbox"]' )?.checked;

		if ( valueElem?.hasAttribute( 'required' ) && ! valueElem?.checkValidity() ) {
			elemsWithError.push( valueElem );
		}

		if ( label && valueElem?.value ) {
			formFieldsData.push({
				label: label,
				value: valueElem?.value,
				type: valueElem?.type,
				checked: checked
			});
		}
	});

	return {formFieldsData, elemsWithError};
};

/**
 * Get the nonce value from the form.
 * @param {HTMLDivElement} form The form.
 * @returns {string}
 */
function extractNonceValue( form ) {
	const query = `.protection #${form.id || ''}_nonce_field`;
	return form.querySelector( query )?.value;
}

/**
 * Send the date from the form to the server
 *
 * @param {HTMLDivElement}    form The element that contains all the inputs
 * @param {HTMLButtonElement}  btn  The submit button
 * @param {DisplayFormMessage} displayMsg The display message utility
 */
const collectAndSendInputFormData = ( form, btn, displayMsg ) => {
	const id = form?.id;
	const payload = {};

	// Get the data from the form fields.
	const {formFieldsData, elemsWithError} = extractFormFields( form );
	const nonceFieldValue = extractNonceValue( form );
	const hasCaptcha = form?.classList?.contains( 'has-captcha' );
	const hasInvalidToken = id && window.themeisleGutenberg?.tokens[id].token;


	const spinner = document.createElement( 'span' );
	spinner.classList.add( 'spinner' );
	btn.appendChild( spinner );

	/**
	 * Validate the form inputs data.
	 */
	elemsWithError.forEach( input => {
		input?.reportValidity();
	});

	if ( hasCaptcha && hasInvalidToken ) {
		const msg = ! window.hasOwnProperty( 'grecaptcha' ) ?
			'captcha-not-loaded' :
			'check-captcha';
		displayMsg.pullMsg(
			msg,
			'error'
		).show();
	}

	if ( 0 < elemsWithError.length || ( hasCaptcha && hasInvalidToken ) ) {
		btn.disabled = false;
		btn.removeChild( spinner );
	} else {
		payload.formInputsData = formFieldsData;
		if ( ! hasInvalidToken ) {
			payload.token = window.themeisleGutenberg?.tokens?.[ id ].token;
		}

		/**
		 * +---------------- Extract the essential data. ----------------+
		 */
		if ( '' !== form?.dataset?.emailSubject ) {
			payload.emailSubject = form?.dataset?.emailSubject;
		}

		if ( form?.dataset?.optionName ) {
			payload.formOption = form?.dataset?.optionName;
		}

		if ( form?.id ) {
			payload.formId = form?.id;
		}

		if ( nonceFieldValue ) {
			payload.nonceValue = nonceFieldValue;
		}

		payload.postUrl = window.location.href;


		/**
		 * Get the consent
		 */
		if ( form.classList.contains( 'can-submit-and-subscribe' ) ) {
			payload.action = 'submit-subscribe';
			payload.consent = form.querySelector( '.otter-form-consent input' )?.checked || false;
		}

		const formURlEndpoint = ( window?.themeisleGutenbergForm?.root || ( window.location.origin + '/wp-json/' ) ) + 'otter/v1/form/frontend';

		fetch( formURlEndpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, */*;q=0.1',
				'X-WP-Nonce': window?.themeisleGutenbergForm?.nonce
			},
			credentials: 'include',
			body: JSON.stringify({
				handler: 'submit',
				payload
			})
		})
			.then( r => r.json() )
			.then( ( response ) => {

				/**
				 * @type {import('./types.js').IFormResponse}
				 */
				const res = response;

				if ( res?.success ) {
					const msg = res?.submitMessage ? res.submitMessage :  'Success';
					displayMsg.setMsg( msg ).show();

					cleanInputs( form );

					setTimeout( () => {
						if ( '' !== res?.redirectLink ) {
							let a = document.createElement( 'a' );
							a.target = '_blank';
							a.href = res.redirectLink;
							a.click();
						}
					}, 1000 );
				} else {
					let msg = '';

					// TODO: Write pattern to display a more useful error message.
					if ( res?.provider && res?.error?.includes( 'invalid' ) || res?.error?.includes( 'fake' ) ) { // mailchimp
						msg = 'invalid-email';
					} else if ( res?.provider && res?.error?.includes( 'duplicate' ) || res?.error?.includes( 'already' ) ) { // sendinblue
						msg = 'already-registered';
					} else {
						msg = 'try-again';
					}

					displayMsg.pullMsg( msg, 'error' ).show();

					// eslint-disable-next-line no-console
					console.error( res?.error, res?.reasons );
				}

				/**
				 * Reset the form.
				 */

				if ( window.themeisleGutenberg?.tokens?.[ id ].reset ) {
					window.themeisleGutenberg?.tokens?.[ id ].reset();
				}
				btn.disabled = false;
				btn.removeChild( spinner );
			})?.catch( ( error ) => {
				console.error( error );
				displayMsg.setMsg( 'try-again', 'error' ).show();

				if ( window.themeisleGutenberg?.tokens?.[ id ].reset ) {
					window.themeisleGutenberg?.tokens?.[ id ].reset();
				}
				btn.disabled = false;
				btn.removeChild( spinner );
			});
	}
};

/**
 * Reset all the input fields.
 * @param {HTMLDivElement} form
 */
const cleanInputs = ( form ) => {
	const inputs = form?.querySelectorAll( '.otter-form__container .wp-block-themeisle-blocks-form-input' );
	const textarea = form?.querySelectorAll( '.otter-form__container .wp-block-themeisle-blocks-form-textarea' );

	[ ...inputs, ...textarea ]?.forEach( input => {
		const valueElem = input.querySelector( '.otter-form-input, .otter-form-textarea-input' );
		if ( valueElem?.value ) {
			valueElem.value = null;
		}
	});
};

/**
 * Render a checkbox for consent
 *
 * @param {HTMLDivElement} form
 */
const renderConsentCheckbox = ( form ) => {
	const container = form.querySelector( '.otter-form__container' );
	const button = form.querySelector( '.wp-block-button' );

	const inputContainer = document.createElement( 'div' );
	inputContainer.classList.add( 'otter-form-consent' );
	container.insertBefore( inputContainer, button );

	const input = document.createElement( 'input' );
	input.type = 'checkbox';
	input.name = 'o-consent';
	input.id = 'o-consent';

	const label = document.createElement( 'label' );
	label.innerHTML = window?.themeisleGutenbergForm?.messages.privacy || 'I have read and agreed the privacy statement.';
	label.htmlFor = 'o-consent';

	inputContainer.appendChild( input );
	inputContainer.appendChild( label );
};


domReady( () => {
	const forms = document.querySelectorAll( '.wp-block-themeisle-blocks-form' );

	addCaptchaOnPage( forms );

	forms.forEach( ( form ) => {
		if ( form.classList.contains( 'can-submit-and-subscribe' ) ) {
			renderConsentCheckbox( form );
		}

		const sendBtn = form.querySelector( 'button' );
		const displayMsg = new DisplayFormMessage( form );

		if ( form.querySelector( 'button[type="submit"]' ) ) {
			form?.addEventListener( 'submit', ( event ) => {
				event.preventDefault();
				if ( ! sendBtn.disabled ) {
					sendBtn.disabled = true;
					collectAndSendInputFormData( form, sendBtn, displayMsg );
				}
			}, false );
		} else {

			// legacy
			sendBtn?.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				if ( ! sendBtn.disabled ) {
					sendBtn.disabled = true;
					collectAndSendInputFormData( form, sendBtn, displayMsg );
				}
			}, false );
		}
	});
});
