<?php

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo json_encode([
		'success' => false,
		'message' => 'Метод не поддерживается.',
	], JSON_UNESCAPED_UNICODE);
	exit;
}

$configPath = __DIR__ . '/callback-config.php';

if (!is_file($configPath)) {
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'message' => 'Не найден файл конфигурации отправки.',
	], JSON_UNESCAPED_UNICODE);
	exit;
}

$config = require $configPath;
$debugMode = !empty($config['debug']);

$name = trim((string) ($_POST['name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$company = trim((string) ($_POST['company'] ?? ''));
$formName = trim((string) ($_POST['form_name'] ?? 'callback'));

if ($company !== '') {
	echo json_encode([
		'success' => true,
		'message' => 'Заявка отправлена.',
	], JSON_UNESCAPED_UNICODE);
	exit;
}

$phoneDigits = preg_replace('/\D+/', '', $phone);

if ($name === '' || $phone === '' || strlen((string) $phoneDigits) < 10) {
	http_response_code(422);
	echo json_encode([
		'success' => false,
		'message' => 'Пожалуйста, укажите имя и корректный телефон.',
	], JSON_UNESCAPED_UNICODE);
	exit;
}

$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = $host !== '' ? $host : 'site';
$sentAt = date('d.m.Y H:i:s');
$formLabel = $formName === 'callback' ? 'Обратный звонок' : $formName;

$emailRows = [
	[
		'label' => 'Имя',
		'value' => $name,
		'emoji' => '👤',
	],
	[
		'label' => 'Телефон',
		'value' => $phone,
		'emoji' => '📞',
		'isAccent' => true,
	],
	[
		'label' => 'Форма',
		'value' => $formLabel,
		'emoji' => '📝',
	],
	[
		'label' => 'Сайт',
		'value' => $host,
		'emoji' => '🌐',
	],
	[
		'label' => 'Дата',
		'value' => $sentAt,
		'emoji' => '🕒',
	],
];

$telegramMessage = "🚨 <b>Новая заявка на эвакуатор!</b>\n\n"
	. "<b>Имя:</b> " . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
	. "<b>Телефон:</b> " . htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
	. "<b>Форма:</b> " . htmlspecialchars($formLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
	. "<b>Сайт:</b> " . htmlspecialchars($host, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
	. "<b>Дата:</b> " . htmlspecialchars($sentAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$emailSubject = '🚨 Новая заявка на эвакуатор!';
$emailBody = buildEmailBodyHtml('🚨 Новая заявка на эвакуатор!', $emailRows);

$makeResult = sendMakeWebhook(
	(string) ($config['make_webhook_url'] ?? ''),
	[
		'form_name' => $formName,
		'name' => $name,
		'phone' => $phone,
		'host' => $host,
		'sent_at' => $sentAt,
		'message' => $telegramMessage,
	]
);

$emailResult = sendEmailMessage(
	(string) ($config['email_to'] ?? ''),
	(string) ($config['from_name'] ?? 'Заявка с сайта'),
	(string) ($config['from_email'] ?? ''),
	$emailSubject,
	$emailBody,
	$host,
	[
		'host' => (string) ($config['smtp_host'] ?? ''),
		'port' => (int) ($config['smtp_port'] ?? 0),
		'encryption' => (string) ($config['smtp_encryption'] ?? ''),
		'username' => (string) ($config['smtp_username'] ?? ''),
		'password' => (string) ($config['smtp_password'] ?? ''),
	]
);

if (!empty($makeResult['success']) || !empty($emailResult['success'])) {
	echo json_encode([
		'success' => true,
		'partial' => !(!empty($makeResult['success']) && !empty($emailResult['success'])),
		'message' => 'Заявка отправлена. Скоро перезвоним.',
	], JSON_UNESCAPED_UNICODE);
	exit;
}

$response = [
	'success' => false,
	'message' => 'Не удалось отправить заявку. Проверьте настройки Make и почты.',
];

if ($debugMode) {
	$response['message'] = 'Ошибка отправки. Make: ' . ($makeResult['error'] ?? 'unknown') . '; Почта: ' . ($emailResult['error'] ?? 'unknown');
	$response['debug'] = [
		'make' => $makeResult['error'] ?? 'unknown',
		'email' => $emailResult['error'] ?? 'unknown',
	];
}

http_response_code(500);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;

function sendMakeWebhook($webhookUrl, $payload)
{
	if ($webhookUrl === '') {
		return [
			'success' => false,
			'error' => 'make_disabled',
		];
	}

	$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	if ($payloadJson === false) {
		return [
			'success' => false,
			'error' => 'make_json_encode_failed',
		];
	}

	if (function_exists('curl_init')) {
		$ch = curl_init($webhookUrl);

		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_POSTFIELDS => $payloadJson,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Content-Length: ' . strlen($payloadJson),
			],
		]);

		$response = curl_exec($ch);
		$errorCode = curl_errno($ch);
		$errorMessage = curl_error($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if ($errorCode !== 0) {
			return [
				'success' => false,
				'error' => 'make_curl_' . $errorCode . ($errorMessage !== '' ? ': ' . $errorMessage : ''),
			];
		}

		if ($httpCode < 200 || $httpCode >= 300) {
			return [
				'success' => false,
				'error' => 'make_http_' . $httpCode . ($response ? ': ' . trim((string) $response) : ''),
			];
		}

		return [
			'success' => true,
			'error' => '',
		];
	}

	$options = [
		'http' => [
			'method' => 'POST',
			'header' => "Content-Type: application/json\r\n",
			'content' => $payloadJson,
			'timeout' => 20,
		],
	];

	$response = @file_get_contents($webhookUrl, false, stream_context_create($options));

	if ($response === false) {
		return [
			'success' => false,
			'error' => 'make_stream_failed',
		];
	}

	return [
		'success' => true,
		'error' => '',
	];
}

function sendEmailMessage($to, $fromName, $fromEmail, $subject, $body, $host, $smtpConfig = [])
{
	if ($to === '') {
		return [
			'success' => false,
			'error' => 'email_disabled',
		];
	}

	if ($fromEmail === '') {
		$cleanHost = preg_replace('/^www\./', '', $host);
		$fromEmail = str_contains((string) $cleanHost, '.') ? 'noreply@' . $cleanHost : 'noreply@example.com';
	}

	if (
		(string) ($smtpConfig['host'] ?? '') !== ''
		&& (int) ($smtpConfig['port'] ?? 0) > 0
		&& (string) ($smtpConfig['username'] ?? '') !== ''
		&& (string) ($smtpConfig['password'] ?? '') !== ''
	) {
		return sendEmailViaSmtp(
			(string) $smtpConfig['host'],
			(int) $smtpConfig['port'],
			(string) ($smtpConfig['encryption'] ?? ''),
			(string) $smtpConfig['username'],
			(string) $smtpConfig['password'],
			$to,
			$fromName,
			$fromEmail,
			$subject,
			$body,
			$host
		);
	}

	$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
	$encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

	$headers = [
		'MIME-Version: 1.0',
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
		'Reply-To: ' . $fromEmail,
		'X-Mailer: PHP/' . phpversion(),
	];

	$parameters = '-f' . $fromEmail;
	$mailSent = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), $parameters);

	return [
		'success' => $mailSent,
		'error' => $mailSent ? '' : 'mail_function_failed',
	];
}

function sendEmailViaSmtp($smtpHost, $smtpPort, $smtpEncryption, $smtpUsername, $smtpPassword, $to, $fromName, $fromEmail, $subject, $body, $host)
{
	$transportHost = $smtpHost;

	if (function_exists('mb_strtolower') && mb_strtolower($smtpEncryption) === 'ssl') {
		$transportHost = 'ssl://' . $smtpHost;
	} elseif (strtolower($smtpEncryption) === 'ssl') {
		$transportHost = 'ssl://' . $smtpHost;
	}

	$errorCode = 0;
	$errorMessage = '';
	$socket = @stream_socket_client(
		$transportHost . ':' . $smtpPort,
		$errorCode,
		$errorMessage,
		10,
		STREAM_CLIENT_CONNECT
	);

	if (!$socket) {
		return [
			'success' => false,
			'error' => 'smtp_connect_failed: ' . ($errorMessage !== '' ? $errorMessage : (string) $errorCode),
		];
	}

	stream_set_timeout($socket, 10);

	$serverResponse = '';

	if (!smtpExpect($socket, 220, $serverResponse)) {
		fclose($socket);

		return [
			'success' => false,
			'error' => 'smtp_greeting_failed: ' . trim($serverResponse),
		];
	}

	$clientHost = preg_replace('/^www\./', '', $host);
	$clientHost = $clientHost !== '' ? $clientHost : 'localhost';

	if (!smtpWrite($socket, 'EHLO ' . $clientHost) || !smtpExpect($socket, 250, $serverResponse)) {
		fclose($socket);

		return [
			'success' => false,
			'error' => 'smtp_ehlo_failed: ' . trim($serverResponse),
		];
	}

	$isTls = function_exists('mb_strtolower') ? mb_strtolower($smtpEncryption) === 'tls' : strtolower($smtpEncryption) === 'tls';

	if ($isTls) {
		if (!smtpWrite($socket, 'STARTTLS') || !smtpExpect($socket, 220, $serverResponse)) {
			fclose($socket);

			return [
				'success' => false,
				'error' => 'smtp_starttls_failed: ' . trim($serverResponse),
			];
		}

		if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
			fclose($socket);

			return [
				'success' => false,
				'error' => 'smtp_tls_crypto_failed',
			];
		}

		if (!smtpWrite($socket, 'EHLO ' . $clientHost) || !smtpExpect($socket, 250, $serverResponse)) {
			fclose($socket);

			return [
				'success' => false,
				'error' => 'smtp_ehlo_tls_failed: ' . trim($serverResponse),
			];
		}
	}

	if (!smtpWrite($socket, 'AUTH LOGIN') || !smtpExpect($socket, 334, $serverResponse)) {
		fclose($socket);

		return [
			'success' => false,
			'error' => 'smtp_auth_init_failed: ' . trim($serverResponse),
		];
	}

	if (!smtpWrite($socket, base64_encode($smtpUsername)) || !smtpExpect($socket, 334, $serverResponse)) {
		fclose($socket);

		return [
			'success' => false,
			'error' => 'smtp_auth_username_failed: ' . trim($serverResponse),
		];
	}

	if (!smtpWrite($socket, base64_encode($smtpPassword)) || !smtpExpect($socket, 235, $serverResponse)) {
		fclose($socket);

		return [
			'success' => false,
			'error' => 'smtp_auth_password_failed: ' . trim($serverResponse),
		];
	}

	if (!smtpWrite($socket, 'MAIL FROM:<' . $fromEmail . '>') || !smtpExpect($socket, 250, $serverResponse)) {
		fclose($socket);

		return [
			'success' => false,
			'error' => 'smtp_mail_from_failed: ' . trim($serverResponse),
		];
	}

	if (!smtpWrite($socket, 'RCPT TO:<' . $to . '>') || !smtpExpect($socket, [250, 251], $serverResponse)) {
		fclose($socket);

		return [
			'success' => false,
			'error' => 'smtp_rcpt_to_failed: ' . trim($serverResponse),
		];
	}

	if (!smtpWrite($socket, 'DATA') || !smtpExpect($socket, 354, $serverResponse)) {
		fclose($socket);

		return [
			'success' => false,
			'error' => 'smtp_data_failed: ' . trim($serverResponse),
		];
	}

	$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
	$encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
	$messageIdDomain = preg_replace('/[^a-z0-9.-]/i', '', $clientHost);
	$messageIdDomain = $messageIdDomain !== '' ? $messageIdDomain : 'localhost';

	try {
		$messageId = sprintf('<%s@%s>', bin2hex(random_bytes(8)), $messageIdDomain);
	} catch (Exception $exception) {
		$messageId = sprintf('<%s@%s>', uniqid('mail-', true), $messageIdDomain);
	}

	$headers = [
		'Date: ' . date(DATE_RFC2822),
		'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
		'To: <' . $to . '>',
		'Subject: ' . $encodedSubject,
		'MIME-Version: 1.0',
		'Content-Type: text/html; charset=UTF-8',
		'Content-Transfer-Encoding: 8bit',
		'Message-ID: ' . $messageId,
		'X-Mailer: PHP/' . phpversion(),
	];

	$message = implode("\r\n", $headers)
		. "\r\n\r\n"
		. str_replace(["\r\n", "\r"], "\n", $body);

	$message = str_replace("\n", "\r\n", $message);
	$message = preg_replace('/^\./m', '..', $message);

	if (!smtpWrite($socket, $message . "\r\n.") || !smtpExpect($socket, 250, $serverResponse)) {
		fclose($socket);

		return [
			'success' => false,
			'error' => 'smtp_message_failed: ' . trim($serverResponse),
		];
	}

	smtpWrite($socket, 'QUIT');
	fclose($socket);

	return [
		'success' => true,
		'error' => '',
	];
}

function smtpWrite($socket, $command)
{
	return fwrite($socket, $command . "\r\n") !== false;
}

function smtpExpect($socket, $expectedCodes, &$responseText = '')
{
	$expectedCodes = (array) $expectedCodes;
	$responseText = smtpRead($socket);

	if ($responseText === '') {
		return false;
	}

	$code = (int) substr($responseText, 0, 3);

	return in_array($code, $expectedCodes, true);
}

function smtpRead($socket)
{
	$response = '';

	while (!feof($socket)) {
		$line = fgets($socket, 515);

		if ($line === false) {
			break;
		}

		$response .= $line;

		if (strlen($line) < 4 || $line[3] === ' ') {
			break;
		}
	}

	return $response;
}

function buildEmailBodyHtml($title, $rows)
{
	$safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$rowsHtml = '';

	foreach ($rows as $row) {
		$label = htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$value = htmlspecialchars((string) ($row['value'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$emoji = htmlspecialchars((string) ($row['emoji'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$valueStyle = !empty($row['isAccent'])
			? 'margin: 6px 0 0; font-size: 24px; line-height: 1.2; font-weight: 800; color: #111827;'
			: 'margin: 6px 0 0; font-size: 16px; line-height: 1.5; font-weight: 600; color: #111827;';

		$rowsHtml .= '<tr>'
			. '<td style="padding: 0 0 14px;">'
			. '<div style="padding: 18px 20px; border: 1px solid #e5e7eb; border-radius: 18px; background: #ffffff;">'
			. '<div style="font-size: 12px; line-height: 1.2; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280;">' . $emoji . ' ' . $label . '</div>'
			. '<div style="' . $valueStyle . '">' . nl2br($value) . '</div>'
			. '</div>'
			. '</td>'
			. '</tr>';
	}

	return '<!DOCTYPE html>'
		. '<html lang="ru">'
		. '<head>'
		. '<meta charset="UTF-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
		. '<title>' . $safeTitle . '</title>'
		. '</head>'
		. '<body style="margin: 0; padding: 24px 0; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; color: #111827;">'
		. '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-collapse: collapse;">'
		. '<tr>'
		. '<td align="center" style="padding: 0 16px;">'
		. '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width: 640px; border-collapse: collapse;">'
		. '<tr>'
		. '<td style="padding: 0 0 16px;">'
		. '<div style="padding: 32px 28px; border-radius: 28px; background: linear-gradient(135deg, #241308 0%, #0c0f0f 100%); box-shadow: 0 24px 48px rgba(17, 24, 39, 0.16);">'
		. '<div style="display: inline-block; margin-bottom: 16px; padding: 8px 14px; border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 999px; background: rgba(255, 255, 255, 0.04); font-size: 12px; line-height: 1.2; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #ff8a18;">Новая заявка</div>'
		. '<div style="font-size: 32px; line-height: 1.1; font-weight: 800; color: #ffffff;">' . $safeTitle . '</div>'
		. '<div style="margin-top: 12px; font-size: 16px; line-height: 1.6; color: rgba(255, 255, 255, 0.76);">Письмо сформировано автоматически с сайта. Данные клиента ниже.</div>'
		. '</div>'
		. '</td>'
		. '</tr>'
		. '<tr>'
		. '<td>'
		. '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-collapse: collapse;">'
		. $rowsHtml
		. '</table>'
		. '</td>'
		. '</tr>'
		. '</table>'
		. '</td>'
		. '</tr>'
		. '</table>'
		. '</body>'
		. '</html>';
}
