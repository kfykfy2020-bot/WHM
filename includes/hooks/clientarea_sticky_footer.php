<?php
if (!defined('WHMCS')) { die('Access Denied'); }
// Sticky footer: make the client-area body fill the viewport height so the footer
// sits flush at the bottom on short pages instead of leaving a tall empty grey box
// below it (#footer background ~= body background, so the gap looked like a tall footer).
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    return '<style>'
         . 'body{min-height:100vh;display:flex;flex-direction:column;}'
         . '#main-body{flex:1 0 auto;}'
         . '#header,#main-menu,#footer{flex-shrink:0;}'
         . '</style>';
});
