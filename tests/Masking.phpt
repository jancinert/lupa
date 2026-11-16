<?php declare(strict_types=1);

use Lupa\Masking;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$masking = new Masking(['birthDate'], ['customers' => ['note']]);


// keys and columns
Assert::true($masking->isSecretKey('password'));
Assert::true($masking->isSecretKey('apiKey'));
Assert::true($masking->isSecretKey('BirthDate'));
Assert::false($masking->isSecretKey('author'));
Assert::false($masking->isSecretKey('name'));
Assert::true($masking->isMaskedColumn('customers', 'note'));
Assert::true($masking->isMaskedColumn(null, 'note'));
Assert::false($masking->isMaskedColumn('orders', 'note'));
Assert::same(
	['db' => ['user' => 'app', 'password' => '***', 'token' => null], 'mail' => 'j***@***.cz'],
	$masking->maskData(['db' => ['user' => 'app', 'password' => 'secret', 'token' => null], 'mail' => 'jan.novak@example.cz']),
);


// shapes
$cases = [
	'write to jan.novak@example.cz now' => 'write to j***@***.cz now',
	'call +420 603 123 456' => 'call +*** *** *** 456',
	'call 603123456' => 'call ******456',
	'tel 222 333 444' => 'tel *** *** 444',
	'RČ 855215/1234' => 'RČ ******/****',
	'RČ 7801233540' => 'RČ ******/****',
	'IBAN CZ65 0800 0000 1920 0014 5399' => 'IBAN CZ** **** **** **** **** *399',
	'účet 19-2000145399/0800' => 'účet **-********99/0800',
	'card 4111 1111 1111 1111' => 'card **** **** **** 1111',
];
foreach ($cases as $input => $expected) {
	Assert::same($expected, $masking->maskText($input), $input);
}

// not personal data
foreach (['order 1234567890', 'id 123456789', 'price 1 234,50', 'date 2026-11-09 10:15:00', 'version 3.1.10', 'card 4111 1111 1111 1112'] as $text) {
	Assert::same($text, $masking->maskText($text), $text);
}

// detectors can be turned off
Assert::same('jan@example.cz', (new Masking([], [], ['phone']))->maskText('jan@example.cz'));


// secrets recognized by their shape and values of secret keys inside text
$shapes = [
	'mysql://root:hunter2@db/app' => 'mysql://root:***@db/app',
	'Authorization: Bearer abcdefgh12345678' => 'Authorization: Bearer ***',
	'jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl' => 'jwt ***',
	'database: Dibi\Connection([host: db, password: secret, lazy: true])' => 'database: Dibi\Connection([host: db, password: ***, lazy: true])',
	"'token' => 'abc', \"apiKey\": \"xyz\", pwd=1" => "'token' => ***, \"apiKey\": ***, pwd=***",
	'see https://example.com/a:b at 10:15' => 'see https://example.com/a:b at 10:15',
];
foreach ($shapes as $input => $expected) {
	Assert::same($expected, $masking->maskText($input), $input);
}


// secrets in places other than a plain assignment; made-up values only
$places = [
	'http://example.test/reset?token=made-up-reset-value' => 'http://example.test/reset?token=***',
	'https://example.test/api?id=5&api_key=made-up-key-value' => 'https://example.test/api?id=5&api_key=***',
	'id=5;password=made-up-value' => 'id=5;password=***',
	'--password made-up-value --host db' => '--password *** --host db',
	'--verbose --host db' => '--verbose --host db',
	'$password = "made-up-value";' => '$password = ***;',
	'$token = $this->getToken();' => '$token = $this->getToken();',
	'$this->password = $password;' => '$this->password = $password;',
	'$this->password = "made-up-value";' => '$this->password = ***;',
	'define("DB_PASSWORD", "made-up-value");' => 'define("DB_PASSWORD", ***);',
	'$user->setPassword("made-up-value")->setName("Jan")' => '$user->setPassword(***)->setName("Jan")',
	'x-api-key: made-up-value' => 'x-api-key: ***',
	'Authorization: Digest username="app", response="made-up-value"' => 'Authorization: ***',
	'Cookie: a=1; PHPSESSID=made-up-value' => 'Cookie: ***',
	'mysql://app:made@up@db/app' => 'mysql://app:***@db/app',
	'mysql://app:made/up@db/app' => 'mysql://app:***@db/app',
	'http://localhost:8080/path?mail=x' => 'http://localhost:8080/path?mail=x',
	'hash $argon2d$v=19$m=65536,t=3,p=4$bWFkZS11cA$bWFkZS11cC12YWx1ZQ' => 'hash ***',
	'hash $6$rounds=5000$madeupsalt$madeupvaluemadeupvaluemadeupvalue' => 'hash ***',
	'author: Jan, authors: 2' => 'author: Jan, authors: 2',
	'phone 420777123456' => 'phone *********456',
];
foreach ($places as $input => $expected) {
	Assert::same($expected, $masking->maskText($input), $input);
}

// numbers shaped like personal data are masked too, other numbers keep their type
Assert::same(
	['phone' => '******456', 'card' => '************1111', 'born' => '******/****', 'id' => 123456789, 'price' => 1234.5, 'author' => 'Jan', 'Authorization' => '***'],
	$masking->maskData(['phone' => 777123456, 'card' => 4111111111111111, 'born' => 7801233540, 'id' => 123456789, 'price' => 1234.5, 'author' => 'Jan', 'Authorization' => 'x']),
);

// a date and time in 14 digits, like the version of a migration, passes the checksum of a card by chance;
// a card of 14 digits does not look like one
Assert::same(
	['20240101120002', 20240101120002, 'version 20240101120002', '**********5904', '20241301120009'],
	$masking->maskData(['20240101120002', 20240101120002, 'version 20240101120002', '30569309025904', '20241301120009']),
);

// a value a tool declares to be a name of the code is read as one name, yet data in it is still found by its shape
$names = ['commands' => [['name' => 'password-reset:send', 'description' => 'password-reset:send'], ['name' => 'jan.novak@example.com'], ['name' => 'password: x']]];
Assert::same(
	['commands' => [['name' => 'password-reset:send', 'description' => 'password-reset:***'], ['name' => 'j***@***.com'], ['name' => 'password: ***']]],
	$masking->maskArray($names, [], ['name']),
);
Assert::same('password-reset:***', $masking->maskArray($names)['commands'][0]['name']);
