/* {[The file is published on the basis of YetiForce Public License 3.0 that can be found in the following directory: licenses/LicenseEN.txt or yetiforce.com]} */
Vtiger_Edit_Js("OSSPasswords_Edit_Js", {}, {
	/**
	 * Function to register recordpresave event
	 */
	registerRecordPreSaveEvent: function (form) {
		var thisInstance = this;
		if (typeof form === 'undefined') {
			form = this.getForm();
		}
		form.on(Vtiger_Edit_Js.recordPreSave, function (e, data) {
			var password = form.find('[name="password"]').val();
			var id = form.find('[name="record"]').val();
			var params = {};
			if (password === '**********') {
				params = {};
				params.data = {
					module: "OSSPasswords",
					action: "GetPass",
					record: id
				};
				params.async = false;
				AppConnector.request(params).then(
					function (data) {
						var response = data['result'];
						if (response['success']) {
							var el = document.getElementById("OSSPasswords_editView_fieldName_password");
							el.value = response['password'];
							el.onchange();
						}
					});
				// validate password
				passwordStrength('', '');
			}
			password = form.find('[name="password"]').val();
			params = {};
			params.data = {module: 'OSSPasswords', action: 'CheckPass', 'password': password, 'id': id};
			params.async = false;
			params.dataType = 'json';
			// if we doesn't have passwordError property it means that we are submitting first time
			// if we submitting first time we don't want to submit - we are only checking password
			// if password is ok then we are firing submit again with passwordError set
			if (typeof thisInstance.passwordError === 'undefined' || thisInstance.passwordError) {
				e.preventDefault();
			}
			AppConnector.request(params).then(function (data) {
				if (data.result.success === false) {
					var params = {
						text: data.result.message,
						sticker: false,
						sticker_hover: false,
						type: 'error'
					};
					Vtiger_Helper_Js.showPnotify(params);
					thisInstance.sending = false;
					thisInstance.passwordError = true;
				} else if (data.result.success === true && (typeof thisInstance.sending === 'undefined' || !thisInstance.sending)) {
					thisInstance.sending = true;
					thisInstance.passwordError = false;
					form.submit();
				} // else just send final request
			});
		});
	},
	generatePassword: function (e) {
		var element = jQuery(e.currentTarget);
		var form = element.closest('form');
		var min = parseInt(jQuery('#minChars').val());
		var max = parseInt(jQuery('#maxChars').val());
		var allowedChars = jQuery('#allowedLetters').val();
		var password = '';   // variable holding new password
		// array of allowed characters that will consist of password
		// if there there is something wrong build the password from only exclamation marks
		if (typeof (allowedChars) === 'undefined')
			allowedChars = '!';
		var chArray = allowedChars.split(',');
		// min length of a password
		if (typeof (min) === 'undefined')
			min = 10;   // default 10
		// max length of a password
		if (typeof (max) === 'undefined')
			max = 15;   // default 15
		// get the password length
		var passlength = parseInt(Math.random() * (max - min) + min);
		var i = 0;    // index for the loop
		// loop to get random string with *pass_length* characters
		for (i = 0; i <= passlength; i++) {
			var charIndex = parseInt(Math.random() * chArray.length);
			password += chArray[charIndex];
		}
		// get desired text field
		var passForm = form.find('[name ="password"]');
		// change its value to the generated password
		passForm.val(password);
		passForm.trigger('change');
	},
	registerButtonsEvents: function () {
		var thisInstance = this;
		$('.js-generatePass').on('click', function (e) {
			thisInstance.generatePassword(e);
		});
	},
	registerBasicEvents: function (container) {
		this._super(container);
		this.registerButtonsEvents();
		this.registerRecordPreSaveEvent(container);
	}
});
