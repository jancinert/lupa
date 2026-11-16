<?php declare(strict_types=1);

$document = '{"note":"synthetic-note","pa\u0073sword":"synthetic-password"}';
$masked = '{"note":"***","password":"***"}';

return [
	[base64_encode('["password","synthetic-secret"]'), '["password","***"]'],
	[rawurlencode('["password","synthetic-secret"]'), '["password","***"]'],
	[base64_encode('{"name":"password","value":"synthetic-secret"}'), '{"name":"password","value":"***"}'],
	[rawurlencode('{"name":"password","value":"synthetic-secret"}'), '{"name":"password","value":"***"}'],
	[base64_encode('["username","Public"]'), base64_encode('["username","Public"]')],
	[rawurlencode('{"name":"username","value":"Public"}'), rawurlencode('{"name":"username","value":"Public"}')],
	[$document, $masked],
	[str_repeat('[', 500) . $document . str_repeat(']', 500), str_repeat('[', 500) . $masked . str_repeat(']', 500)],
	[str_repeat('[', 520) . $document . str_repeat(']', 520), '***'],
	[str_repeat('{"item":', 520) . $document . str_repeat('}', 520), '***'],
	[" \t\r\n" . $document . ' trailing text', '***'],
	[substr($document, 0, -1), '***'],
	['[{"note":"synthetic-note"},]', '***'],
	['[ordinary non-JSON text]', '[ordinary non-JSON text]'],
	['["unterminated', '***'],
	[' ordinary text', ' ordinary text'],
	[
		'{"wrapper":"{\\"note\\":\\"synthetic-note\\",\\"pa\\\\u0073sword\\":\\"synthetic-password\\"}"}',
		'{"wrapper":"{\\"note\\":\\"***\\",\\"password\\":\\"***\\"}"}',
	],
	['{"person@example.test":{"count":1}}', '{"p***@***.test":{"count":1}}'],
	['{"url":"https://example.test/?pa%73sword=synthetic-secret&id=5"}', '{"url":"https://example.test/?pa%73sword=***&id=5"}'],
];
