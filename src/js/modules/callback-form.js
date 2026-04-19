function callbackForm() {
	const form = document.querySelector(".js-callback-form");

	if (!form) {
		return;
	}

	const submitButton = form.querySelector(".contacts-cta__button");
	const submitButtonText = form.querySelector(".contacts-cta__button-text");
	const status = form.querySelector(".contacts-cta__status");
	const phoneInput = form.querySelector('input[name="phone"]');
	const nameInput = form.querySelector('input[name="name"]');
	const consentInput = form.querySelector('input[name="consent"]');
	const defaultButtonText = submitButtonText ? submitButtonText.textContent : "";
	const nameAllowedKeyPattern = /^[a-zA-Zа-яА-ЯёЁ\s'-]$/;

	const validatePhone = () => {
		if (!phoneInput) {
			return true;
		}

		const digits = phoneInput.value.replace(/\D/g, "");

		if (!digits.length) {
			phoneInput.setCustomValidity("");
			return false;
		}

		if (digits.length < 11) {
			phoneInput.setCustomValidity("Введите телефон полностью");
			return false;
		}

		phoneInput.setCustomValidity("");
		return true;
	};

	const sanitizeName = (value) => value
		.replace(/[^a-zA-Zа-яА-ЯёЁ\s'-]/g, "")
		.replace(/\s+/g, " ")
		.replace(/\s?-\s?/g, "-")
		.replace(/'{2,}/g, "'")
		.slice(0, 30)
		.trimStart();

	const validateName = () => {
		if (!nameInput) {
			return true;
		}

		const normalizedValue = sanitizeName(nameInput.value).trim();

		if (normalizedValue !== nameInput.value) {
			nameInput.value = normalizedValue;
		}

		if (normalizedValue.length < 2) {
			nameInput.setCustomValidity("Введите имя минимум из 2 букв");
			return false;
		}

		nameInput.setCustomValidity("");
		return true;
	};

	const validateConsent = () => {
		if (!consentInput) {
			return true;
		}

		if (!consentInput.checked) {
			consentInput.setCustomValidity("Подтвердите согласие с политикой конфиденциальности");
			return false;
		}

		consentInput.setCustomValidity("");
		return true;
	};

	const setStatus = (message, type = "") => {
		if (!status) {
			return;
		}

		status.textContent = message;
		status.classList.remove("is-success", "is-error");

		if (type) {
			status.classList.add(`is-${type}`);
		}
	};

	const setSubmittingState = (isSubmitting) => {
		if (submitButton) {
			submitButton.disabled = isSubmitting;
		}

		form.setAttribute("aria-busy", String(isSubmitting));

		if (submitButtonText) {
			submitButtonText.textContent = isSubmitting ? "Отправляем..." : defaultButtonText;
		}
	};

	if (phoneInput) {
		phoneInput.addEventListener("input", validatePhone);
		phoneInput.addEventListener("blur", validatePhone);
	}

	if (nameInput) {
		nameInput.setAttribute("minlength", "2");
		nameInput.setAttribute("maxlength", "30");
		nameInput.setAttribute("pattern", "[A-Za-zА-Яа-яЁё\\s'\\-]{2,30}");

		nameInput.addEventListener("keydown", (event) => {
			if (event.ctrlKey || event.metaKey || event.altKey) {
				return;
			}

			if (event.key.length === 1 && !nameAllowedKeyPattern.test(event.key)) {
				event.preventDefault();
			}
		});

		nameInput.addEventListener("input", () => {
			const sanitizedValue = sanitizeName(nameInput.value);

			if (sanitizedValue !== nameInput.value) {
				nameInput.value = sanitizedValue;
			}

			validateName();
		});

		nameInput.addEventListener("paste", () => {
			requestAnimationFrame(() => {
				nameInput.value = sanitizeName(nameInput.value);
				validateName();
			});
		});

		nameInput.addEventListener("blur", validateName);
	}

	if (consentInput) {
		consentInput.addEventListener("change", () => {
			validateConsent();

			if (consentInput.checked) {
				setStatus("");
			}
		});
	}

	form.addEventListener("submit", async (event) => {
		event.preventDefault();

		validatePhone();
		validateName();
		validateConsent();

		if (!form.reportValidity()) {
			if (consentInput && !consentInput.checked) {
				setStatus("Подтвердите согласие с политикой конфиденциальности и обработкой персональных данных.", "error");
			}

			return;
		}

		setSubmittingState(true);
		setStatus("");

		try {
			const response = await fetch(form.action, {
				method: "POST",
				body: new FormData(form),
				headers: {
					"X-Requested-With": "XMLHttpRequest",
				},
			});

			const result = await response.json().catch(() => null);

			if (!response.ok || !result || !result.success) {
				throw new Error(result?.message || "Не удалось отправить заявку. Попробуйте еще раз.");
			}

			form.reset();
			validateConsent();
			setStatus(result.message || "Заявка отправлена. Скоро перезвоним.", "success");
		} catch (error) {
			setStatus(error.message || "Не удалось отправить заявку. Попробуйте еще раз.", "error");
		} finally {
			setSubmittingState(false);
		}
	});
}

export default callbackForm;
