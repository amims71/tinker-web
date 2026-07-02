<?php

use Amims71\TinkerWeb\Connections\ConnectionStore;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/tinker-web-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0700, true);
    $this->store = new ConnectionStore($this->dir.'/connections.json');
});

afterEach(function () {
    @unlink($this->dir.'/connections.json');
    @rmdir($this->dir);
});

it('is empty when no file exists', function () {
    expect($this->store->all())->toBe([]);
});

it('remembers projects most-recent-first and de-duplicates', function () {
    $this->store->remember('/a');
    $this->store->remember('/b');
    $this->store->remember('/a');

    expect($this->store->all())->toBe(['/a', '/b']);
});

it('persists across instances', function () {
    $this->store->remember('/x');
    $fresh = new ConnectionStore($this->dir.'/connections.json');

    expect($fresh->all())->toBe(['/x']);
});

it('validates a project by its autoloader and bootstrap file', function () {
    expect($this->store->isValidProject('/definitely/not/a/project'))->toBeFalse();

    $project = $this->dir.'/proj';
    mkdir($project.'/vendor', 0700, true);
    mkdir($project.'/bootstrap', 0700, true);
    touch($project.'/vendor/autoload.php');
    touch($project.'/bootstrap/app.php');

    expect($this->store->isValidProject($project))->toBeTrue();

    // cleanup the fixture
    array_map('unlink', [$project.'/vendor/autoload.php', $project.'/bootstrap/app.php']);
    array_map('rmdir', [$project.'/vendor', $project.'/bootstrap', $project]);
});

it('resolves a relative path to its realpath and strips a trailing slash', function () {
    $sub = $this->dir.'/nested';
    mkdir($sub);
    $prev = getcwd();
    chdir($this->dir);
    try {
        expect(ConnectionStore::resolve('nested'))->toBe(realpath($sub));
        expect(ConnectionStore::resolve('./nested/'))->toBe(realpath($sub));
    } finally {
        chdir($prev);
        rmdir($sub);
    }
});

it('falls back to the trimmed input for a non-existent path', function () {
    expect(ConnectionStore::resolve('/no/such/path/'))->toBe('/no/such/path');
});
