<?php

use Amims71\TinkerWeb\Runner\StatementSplitter;

it('splits top-level statements on a semicolon', function () {
    expect(StatementSplitter::split('$a = 1; $b = 2;'))->toBe(['$a = 1;', '$b = 2;']);
});

it('keeps a trailing expression without a semicolon', function () {
    expect(StatementSplitter::split('$a = 1; count($a)'))->toBe(['$a = 1;', 'count($a)']);
});

it('does not split on a semicolon inside a string', function () {
    expect(StatementSplitter::split('$s = "a;b"; strlen($s)'))->toBe(['$s = "a;b";', 'strlen($s)']);
});

it('does not split on a semicolon inside for(;;) parens', function () {
    expect(StatementSplitter::split('for ($i = 0; $i < 2; $i++) { echo $i; }'))
        ->toBe(['for ($i = 0; $i < 2; $i++) { echo $i; }']);
});

it('splits at the semicolon after a closure, not the closure brace', function () {
    expect(StatementSplitter::split('$f = function () { return 1; }; $f()'))
        ->toBe(['$f = function () { return 1; };', '$f()']);
});

it('keeps if/else as one statement (never splits on })', function () {
    expect(StatementSplitter::split('if (true) { echo 1; } else { echo 2; }'))
        ->toBe(['if (true) { echo 1; } else { echo 2; }']);
});

it('reports balance for the incomplete gate', function () {
    expect(StatementSplitter::isBalanced('foreach ($a as $b) {'))->toBeFalse();
    expect(StatementSplitter::isBalanced('$x = [1, 2'))->toBeFalse();
    expect(StatementSplitter::isBalanced('if (true) { echo 1; }'))->toBeTrue();
    expect(StatementSplitter::isBalanced('User::count()'))->toBeTrue();
});

it('tracks depth through curly-brace string interpolation', function () {
    expect(StatementSplitter::split('$s = "value: {$obj->prop}"; strlen($s)'))
        ->toBe(['$s = "value: {$obj->prop}";', 'strlen($s)']);
});

it('reports balance correctly with string interpolation', function () {
    expect(StatementSplitter::isBalanced('$s = "{$x}";'))->toBeTrue();
    // the interpolation must not cancel a genuinely unclosed brace
    expect(StatementSplitter::isBalanced('$s = "{$x}"; foreach ($a as $b) {'))->toBeFalse();
});

it('tracks depth through PHP attributes', function () {
    expect(StatementSplitter::isBalanced('#[Attr] function f() {}'))->toBeTrue();
});

it('identifies use and namespace as declarations', function () {
    expect(StatementSplitter::isDeclaration('use App\Models\User;'))->toBeTrue();
    expect(StatementSplitter::isDeclaration('use Foo;'))->toBeTrue();
    expect(StatementSplitter::isDeclaration('namespace App;'))->toBeTrue();
    expect(StatementSplitter::isDeclaration('$a = 1;'))->toBeFalse();
    expect(StatementSplitter::isDeclaration('User::count()'))->toBeFalse();
    expect(StatementSplitter::isDeclaration('foreach ($x as $y) { echo $y; }'))->toBeFalse();
});

it('replays only declarations that have an effect', function () {
    expect(StatementSplitter::preambleFor('use App\Models\User;'))->toBe('use App\Models\User; ');
    expect(StatementSplitter::preambleFor('use App\Models\User as U;'))->toBe('use App\Models\User as U; ');
    expect(StatementSplitter::preambleFor('use ArrayObject as AO;'))->toBe('use ArrayObject as AO; ');
    expect(StatementSplitter::preambleFor('namespace App\Foo;'))->toBe('namespace App\Foo; ');
    expect(StatementSplitter::preambleFor('use ArrayObject;'))->toBe(''); // no effect, skip
    expect(StatementSplitter::preambleFor('$a = 1;'))->toBe('');
});
