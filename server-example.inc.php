<?php

// Site config
$server = array(
   // Path to the directory containing the webiste. 
   // Must end with a '/' or '\' depending on the webserver OS.
   'host_address' => './',

   // Either 'http://' or 'https://'. 
   // Note: ECHO requires SSL certification (i.e., use HTTPS)
   //       to ensure client-server communications are encrypted. 
   //       Moreover, some JavaScript features like WebRTC used 
   //       for audio/video captures require a secure connection. 
   //
   //       For local development without an SSL certificate, you can
   //       leave this as 'http://' and then change settings on each 
   //       client's Google Chrome browser by creating an exception 
   //       for the specific website. 
   //       1. Go to chrome://flags/#unsafely-treat-insecure-origin-as-secure
   //       2. Enable the "Insecure origins treated as secure" setting. 
   //       3. Enter the URL that needs the exception. This is a 
   //          concatenation of $server['http'].$server['site_url']. 
   //          For example, 'http://127.0.0.1/ECHO'
   //       4. After modifying the field, click somewhere else on the page 
   //          and a footer will appear saying "Your changes will take effect
   //          the next time you relaunch Chorme." Click on Relaunch. 
   //       While this method works for development purposes, it is not
   //       recommended for real analog missions as it exposes all 
   //       communications as plain text. 
   'http' => 'http://',

   // Site URL without http (e.g., 'echo.space', 
   // 'analog.space/echo', '127.0.0.1', or '127.0.0.1/ECHO')
   'site_url' => '127.0.0.1',
);

// MySQL database login info
$database = array(
   'db_host' => '127.0.0.1',
   'db_user' => 'username',
   'db_pass' => 'password',
   'db_name' => 'delay',
   'db_prfx' => '',
);

// Other administrative settings. 
$admin = array(
   // Default password used when creating new accounts or resetting 
   // an account.
   'default_password' => 'password',
);
?>

