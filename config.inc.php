<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once('server.inc.php');

$config = array();
$config['echo_version'] = '4.6';
$config['templates_dir'] = 'templates';
$config['modules_dir'] = 'modules';
$config['uploads_dir'] = 'uploads';
$config['logs_dir'] = 'logs';
$config['log_file'] = 'analog.log';
$config['delay_mars_file'] = 'mars_distance_echo.txt';

// Extension => Mime Type
$config['uploads_allowed'] = array(
    'application/msword' => 'doc' ,
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/pdf' => 'pdf' ,
    'text/plain' => 'txt' ,
    'application/vnd.ms-powerpoint' => 'ppt' ,
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-excel' => 'xls' ,
    'application/msword' => 'doc',
    'application/msword' => 'dot',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.template' => 'dotx',
    'application/vnd.ms-word.document.macroEnabled.12' => 'docm',
    'application/vnd.ms-word.template.macroEnabled.12' => 'dotm',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.ms-excel' => 'xlt',
    'application/vnd.ms-excel' => 'xla',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.template' => 'xltx',
    'application/vnd.ms-excel.sheet.macroEnabled.12' => 'xlsm',
    'application/vnd.ms-excel.template.macroEnabled.12' => 'xltm',
    'application/vnd.ms-excel.addin.macroEnabled.12' => 'xlam',
    'application/vnd.ms-excel.sheet.binary.macroEnabled.12' => 'xlsb',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.ms-powerpoint' => 'pot',
    'application/vnd.ms-powerpoint' => 'pps',
    'application/vnd.ms-powerpoint' => 'ppa',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/vnd.openxmlformats-officedocument.presentationml.template' => 'potx',
    'application/vnd.openxmlformats-officedocument.presentationml.slideshow' => 'ppsx',
    'application/vnd.ms-powerpoint.addin.macroEnabled.12' => 'ppam',
    'application/vnd.ms-powerpoint.presentation.macroEnabled.12' => 'pptm',
    'application/vnd.ms-powerpoint.template.macroEnabled.12' => 'potm',
    'application/vnd.ms-powerpoint.slideshow.macroEnabled.12' => 'ppsm',
    'application/vnd.ms-access' => 'mdb',
    'text/csv' => 'csv' ,
    'image/bmp' => 'bmp' ,
    'image/gif' => 'gif' ,
    'image/jpg' => 'jpg' ,
    'image/jpeg'  => 'jpeg',
    'image/png' => 'png' ,
    'image/tiff' => 'tif' ,
    'image/tiff' => 'tiff',
    'application/vnd.rar' => 'rar' ,
    'application/x-tar' => 'tar' ,
    'application/vnd.visio' => 'vsd' ,
    'audio/webm' => 'mka' ,
    'video/webm' => 'mkv' ,
    'video/x-matroska' => 'mkv' ,
    'application/zip' => 'zip' ,
    'application/x-7z-compressed' => '7z',
    'video/mp4' => 'mp4',
    'audio/mp4' => 'mp4',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
    'video/quicktime' => 'mov',
    'video/x-msvideo' => 'avi',
    'image/avif' => 'avif',
    'text/plain' => 'txt',
);

// Partial 7z file support. Not the cleanest, but quick and dirty solution.
$config['uploads_allowed_partial'] = array();
for($i = 0; $i < 1000; $i++)
{
    $config['uploads_allowed_partial'][] = str_pad(''.$i, 3, '0', STR_PAD_LEFT);
}

// this is the array of modules that are allowed to run
$config['modules_public'] = array(
    'home',
    'error',
    'file'
);

$config['modules_user'] = array_merge($config['modules_public'], array(
    'chat',
    'help'
));

$config['modules_admin'] = array_merge($config['modules_user'], array( 
    'admin',
    'debug'
));

$config['cookie_name'] = 'website';

// include some files that we need to run.
require_once('database/database.php');
require_once('database/dao.php');
require_once('modules/interface.php');
require_once('modules/default.php');
require_once('modules/error.php');
require_once('modules/debug.php');

// Include classes
require_once('class/log.php');
require_once('class/delay.php');
require_once('class/time.php');
require_once('class/serverFile.php');
require_once('class/message.php');
require_once('class/user.php');
require_once('class/list.php');
require_once('class/mission.php');
require_once('class/conversation.php');
require_once('class/attachment.php');
require_once('class/archive.php');
require_once('class/parsedown.php');
require_once('class/archiveMaker.php');

// Include database objects
require_once('database/missionDao.php');
require_once('database/usersDao.php');
require_once('database/conversationsDao.php');
require_once('database/participantsDao.php');
require_once('database/messagesDao.php');
require_once('database/messageStatusDao.php');
require_once('database/messageFileDao.php');
require_once('database/archiveDao.php');



?>
