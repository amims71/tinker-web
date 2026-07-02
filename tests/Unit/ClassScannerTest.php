<?php

use Amims71\TinkerWeb\Runner\ClassScanner;

beforeEach(function () {
    $this->proj = sys_get_temp_dir().'/tw-scan-'.bin2hex(random_bytes(4));
    mkdir($this->proj.'/vendor/composer', 0700, true);
    mkdir($this->proj.'/app/Models', 0700, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->proj));
});

it('unions classmap classes with a PSR-4 scan of non-vendor dirs, sorted and de-duped', function () {
    $p = $this->proj;
    file_put_contents("$p/vendor/composer/autoload_classmap.php",
        '<?php return '.var_export(['Vendor\\Pkg\\Thing' => '/x', 'App\\Models\\User' => '/y'], true).';');
    file_put_contents("$p/vendor/composer/autoload_psr4.php",
        '<?php return '.var_export(['App\\' => ["$p/app"], 'Vendor\\' => ["$p/vendor/pkg/src"]], true).';');
    file_put_contents("$p/app/Models/Order.php", "<?php\nnamespace App\\Models;\nclass Order {}\n");

    $classes = ClassScanner::scan($p);

    expect($classes)->toContain('Vendor\\Pkg\\Thing');   // from classmap
    expect($classes)->toContain('App\\Models\\User');    // from classmap
    expect($classes)->toContain('App\\Models\\Order');   // from PSR-4 scan (not in classmap)
    $sorted = $classes;
    sort($sorted);
    expect($classes)->toBe($sorted);                     // sorted
    expect(count($classes))->toBe(count(array_unique($classes))); // de-duped
});

it('returns an empty array when there are no composer autoload files', function () {
    expect(ClassScanner::scan($this->proj))->toBe([]);
});
