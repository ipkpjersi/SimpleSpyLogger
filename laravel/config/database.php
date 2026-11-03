<?php

return [

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => false, // disable to preserve original behavior for existing applications
    ],

    /*
     * migrate:fresh, migrate:refresh, migrate:reset, migrate:rollback and db:wipe
     * refuse to run outside of the test suite unless this is true. Only flip it
     * on in .env for the one command you mean to run, then turn it back off.
     */
    'allow_destructive_commands' => (bool) env('DB_ALLOW_DESTRUCTIVE_COMMANDS', false),

];
