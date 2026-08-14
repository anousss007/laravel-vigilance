<?php

use Illuminate\Support\Facades\Blade;

/**
 * Every Blade file in the package has to at least compile.
 *
 * Most views are covered by a render test, but not all of them, and a partial
 * with an unbalanced `{{` only fails when the one page that includes it is
 * exercised. Compiling the lot is cheap and catches the whole class of damage
 * a careless find-and-replace can do.
 */
it('compiles every view in the package', function () {
    $files = collect(
        (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../resources/views')
        ))
    )->filter(fn ($file) => $file->isFile() && str_ends_with($file->getFilename(), '.blade.php'));

    expect($files)->not->toBeEmpty();

    $broken = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file->getPathname());

        // Compile to PHP and lint that, which surfaces an unterminated echo or
        // a malformed directive without needing the view's data.
        $compiled = Blade::compileString($source);

        if (@eval('return true; ?>'.$compiled) === false) {
            $broken[] = $file->getPathname();
        }
    }

    expect($broken)->toBe([]);
});

it('leaves no unterminated echo in any view', function () {
    // The specific failure mode: `{{ expr` with its closing braces removed.
    // Compiling catches most of it, but this reads clearly in a diff.
    $files = collect(
        (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../resources/views')
        ))
    )->filter(fn ($file) => $file->isFile() && str_ends_with($file->getFilename(), '.blade.php'));

    foreach ($files as $file) {
        // Strip comments first: a multi-line {{-- … --}} block ends on a line
        // carrying a closing brace pair with no opener, which a naive per-line
        // count reads as unbalanced.
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname())) ?? '';

        expect(substr_count($source, '{{'))
            ->toBe(
                substr_count($source, '}}'),
                $file->getFilename().' has an unbalanced echo',
            );
    }
});

it('keeps Alpine bindings off the vendored components', function () {
    // Alpine's `:attr` shorthand and Blade's component prop binding are the
    // same syntax: on a plain element it is Alpine, on a component Blade
    // evaluates it as PHP. `:aria-expanded="drawer"` on a component therefore
    // dies on an undefined constant. Alpine-driven chrome stays plain markup.
    $files = collect(
        (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../resources/views')
        ))
    )->filter(fn ($file) => $file->isFile() && str_ends_with($file->getFilename(), '.blade.php'));

    foreach ($files as $file) {
        $source = (string) file_get_contents($file->getPathname());

        preg_match_all('/<x-vigilance::[a-z.\-]+((?:[^>"]|"[^"]*")*)>/', $source, $matches);

        foreach ($matches[1] as $attributes) {
            expect($attributes)
                ->not->toMatch('/(^|\s)@click/', $file->getFilename().' binds Alpine on a component')
                ->not->toMatch('/(^|\s):aria-/', $file->getFilename().' binds Alpine on a component');
        }
    }
});
