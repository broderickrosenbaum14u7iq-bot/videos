/**
 * Frontend account page (Phase 9): display name, password, avatar.
 *
 * No framework, no build step. Loaded only on `/tai-khoan/`.
 */
(function () {
	'use strict';

	var config = window.TubeMembersAccountConfig || {};

	/* ---- Display name ------------------------------------------------- */

	var nameForm = document.querySelector('[data-tube-account-name-form]');

	if (nameForm) {
		nameForm.addEventListener('submit', function (event) {
			event.preventDefault();

			var savedHint = nameForm.querySelector('[data-tube-account-name-saved]');
			var button = nameForm.querySelector('button[type="submit"]');

			if (button) {
				button.disabled = true;
			}

			fetch(config.meUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.restNonce || '',
				},
				body: JSON.stringify({ display_name: nameForm.display_name.value }),
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (data) {
					if (data && data.success && savedHint) {
						savedHint.hidden = false;
						window.setTimeout(function () {
							savedHint.hidden = true;
						}, 2000);
					}
				})
				.catch(function () {
					// Fail silently -- the field simply keeps its current value.
				})
				.finally(function () {
					if (button) {
						button.disabled = false;
					}
				});
		});
	}

	/* ---- Password ------------------------------------------------------ */

	var passwordToggle = document.querySelector('[data-tube-account-password-toggle]');
	var passwordForm = document.querySelector('[data-tube-account-password-form]');

	if (passwordToggle && passwordForm) {
		passwordToggle.addEventListener('click', function () {
			passwordForm.hidden = !passwordForm.hidden;
		});

		passwordForm.addEventListener('submit', function (event) {
			event.preventDefault();

			var errorEl = passwordForm.querySelector('[data-tube-account-password-error]');
			var button = passwordForm.querySelector('button[type="submit"]');

			if (errorEl) {
				errorEl.hidden = true;
			}

			if (button) {
				button.disabled = true;
			}

			fetch(config.passwordUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.restNonce || '',
				},
				body: JSON.stringify({
					current_password: passwordForm.current_password.value,
					new_password: passwordForm.new_password.value,
					new_password_confirm: passwordForm.new_password_confirm.value,
				}),
			})
				.then(function (response) {
					return response.json().then(function (data) {
						return { ok: response.ok, data: data };
					});
				})
				.then(function (result) {
					if (result.ok && result.data && result.data.success) {
						// Changing the password rotates the session
						// token, which invalidates every nonce this page
						// loaded with -- adopt the fresh one so a
						// following avatar/display-name save on this
						// same page load doesn't 403.
						if (result.data.rest_nonce) {
							config.restNonce = result.data.rest_nonce;
						}

						passwordForm.reset();
						passwordForm.hidden = true;

						return;
					}

					if (errorEl) {
						errorEl.textContent =
							(result.data && result.data.error) || 'Không thể đổi mật khẩu. Vui lòng thử lại.';
						errorEl.hidden = false;
					}
				})
				.catch(function () {
					if (errorEl) {
						errorEl.textContent = 'Không thể kết nối máy chủ. Vui lòng thử lại.';
						errorEl.hidden = false;
					}
				})
				.finally(function () {
					if (button) {
						button.disabled = false;
					}
				});
		});
	}

	/* ---- Email verification (2026-08-27) --------------------------------- */

	var resendBtn = document.querySelector('[data-tube-account-resend-verification]');
	var resendHint = document.querySelector('[data-tube-account-resend-hint]');

	if (resendBtn) {
		resendBtn.addEventListener('click', function () {
			resendBtn.disabled = true;

			fetch(config.resendVerificationUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.restNonce || '' },
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (data) {
					if (resendHint) {
						resendHint.textContent =
							(data && data.message) || 'Không thể gửi email xác thực lúc này. Vui lòng thử lại sau.';
						resendHint.hidden = false;
					}
				})
				.catch(function () {
					if (resendHint) {
						resendHint.textContent = 'Không thể kết nối máy chủ. Vui lòng thử lại.';
						resendHint.hidden = false;
					}
				})
				.finally(function () {
					resendBtn.disabled = false;
				});
		});
	}

	/* ---- Avatar --------------------------------------------------------- */

	var avatarInput = document.querySelector('[data-tube-account-avatar-input]');
	var avatarPreview = document.querySelector('[data-tube-account-avatar-preview]');

	if (avatarInput) {
		avatarInput.addEventListener('change', function () {
			var file = avatarInput.files && avatarInput.files[0];

			if (!file) {
				return;
			}

			var body = new FormData();
			body.append('avatar', file);

			fetch(config.avatarUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.restNonce || '' },
				body: body,
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (data) {
					if (data && data.success && data.avatar_url && avatarPreview) {
						avatarPreview.src = data.avatar_url;
					}
				})
				.catch(function () {
					// Fail silently -- the previous avatar remains shown.
				})
				.finally(function () {
					avatarInput.value = '';
				});
		});
	}
})();
