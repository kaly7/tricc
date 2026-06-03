<?php
return [
    'db_host'     => '127.0.0.1',
    'db_name'     => 'tricc',
    'db_user'     => 'tricc_user',
    'db_pass'     => 'CHANGE_ME',

    'jwt_secret'  => 'CHANGE_ME_RANDOM_32_CHARS',

    // Apple APNs (.p8 fájl alapú JWT auth)
    'apns_key_file'  => '/opt/tricc/AuthKey_XXXXXXXXXX.p8',
    'apns_key_id'    => 'XXXXXXXXXX',
    'apns_team_id'   => 'XXXXXXXXXX',
    'apns_bundle_id' => 'hu.example.tricc',
];
