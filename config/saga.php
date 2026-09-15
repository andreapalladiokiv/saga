<?php

declare(strict_types=1);

return [

    /*
     * Where `php artisan make:saga` writes, and what it declares.
     *
     * These two go together. The namespace is what a generated saga is declared
     * in, and the directory is where its file goes; change one and you change
     * the other, or the class will not autoload.
     *
     * `path` is null by default, which means the application's own `app/Sagas` —
     * the same place the command uses when this file has not been published at
     * all. Set it to an absolute path to put the files somewhere else; a
     * relative one is taken as written, so give it the whole thing.
     *
     * Both are defaults. `--path` and `--namespace` on the command override
     * them, and always win.
     */

    'generator' => [
        'path' => null,
        'namespace' => 'App\\Sagas',
    ],

];
