<?php

use App\Domain\Money;

test('money class is autoloadable', function () {
    expect(class_exists(Money::class))->toBeTrue();
});
