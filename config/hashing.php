<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver de hash padrão
    |--------------------------------------------------------------------------
    |
    | Argon2id (vencedor da Password Hashing Competition, RFC 9106) é o padrão: combina a resistência a
    | ataques por canal lateral do Argon2i com a resistência a GPUs/ASICs do Argon2d. O PHP não expõe o
    | Argon2d puro (só "argon2i" e "argon2id"); "argon2id" é a variante recomendada pela RFC e pelo OWASP.
    | Hashes bcrypt existentes continuam válidos e são migrados no próximo login (rehash_on_login).
    |
    | Suportados: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    /*
    | Parâmetros do Argon2 (valem para "argon" e "argon2id"): memória em KiB, lanes e iterações.
    | 64 MiB / 1 lane / 4 iterações ficam na faixa recomendada pelo OWASP para servidores modestos (~60 ms na VPS).
    */
    'argon' => [
        'memory' => (int) env('HASH_ARGON_MEMORY', 65536),
        'threads' => (int) env('HASH_ARGON_THREADS', 1),
        'time' => (int) env('HASH_ARGON_TIME', 4),
        // false: hashes bcrypt já gravados ainda são conferidos (pelo algoritmo embutido no próprio hash) e migrados
        // no login. Depois que não restar nenhum bcrypt, HASH_ARGON_VERIFY=true recusa qualquer hash que não seja argon2id.
        'verify' => (bool) env('HASH_ARGON_VERIFY', false),
    ],

    /*
    | Ao autenticar, senhas gravadas com outro driver/parâmetros são re-hasheadas de forma transparente.
    */
    'rehash_on_login' => true,

];
