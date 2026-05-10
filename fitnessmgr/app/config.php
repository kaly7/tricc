<?php
declare(strict_types=1);

return [
  'db' => [
    'host'    => '127.0.0.1',
    'name'    => 'fitnessmgr_db',
    'user'    => 'ppdb',
    'pass'    => 'abrakadabra',
    'charset' => 'utf8mb4',
  ],

  'session_name'     => 'FITNESSMGR_SESSID',
  'auth_center_port' => 90,
  'module_slug'      => 'fitnessmgr',
  'base_path'        => '',
  'app_name'         => 'Fitness napló',

  // A rendszer egyetlen felhasználójának fitnessmgr_db user_id-ja (auth_db users.id alapján)
  'user_id' => 2,

  // Mattermost – REST API bot token alapú integráció
  'mattermost' => [
    'server_url'    => 'http://192.168.16.26:8065',
    'bot_token'     => 'jusdcigp97y6pmob5ediacdygr',
    'bot_id'        => 'hc43kjmsgjyyxcapfydgxhcr8e',  // fitness_guru bot ID
    'bot_username'  => 'fitness_guru',
    'bot_name'      => 'Fitness Guru',
    'bot_icon'      => ':apple:',
    'target_user'   => 'kaly',                          // DM cél: te
    'target_user_id'=> '7ycp3ik8gfn37q14rabr9j9cza',  // kaly user ID
    'dm_channel_id' => 'yrzkkztu87ftuj5pwmp5zrtrfe',  // DM channel (bot ↔ kaly)
    'channel'       => 'fitness',   // channel mód (ha a bot teambe kerül)
    'slash_token'   => '',          // /fitness slash command token (opcionális)
  ],

  // Időjárás a víz emlékeztetőhöz (wttr.in helyszín)
  'weather_location' => 'Budapest',

  // Claude API – console.anthropic.com oldalon kell API kulcsot létrehozni
  // A Claude Pro előfizetés NEM ad API hozzáférést, külön számlázás!
  'claude' => [
    'enabled' => true,
    'api_key' => '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',
    'model'   => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
  ],
];
