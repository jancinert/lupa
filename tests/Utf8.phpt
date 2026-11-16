<?php declare(strict_types=1);

use Lupa\Utf8;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$value = "ě\n🙂漢";
Assert::same(4, Utf8::length($value));
Assert::same('', Utf8::prefix($value, 0));
Assert::same("ě\n🙂", Utf8::prefix($value, 3));
Assert::same($value, Utf8::prefix($value, 4));
Assert::same("ě\n", Utf8::cut($value, 6));
Assert::same("ě\n🙂", Utf8::cut($value, 7));
Assert::same('ě…', Utf8::trimWidth('ěšč', 2));
