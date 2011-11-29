<?php

$args = $_SERVER['argv'];
array_shift($args);

$settings = array(
	'project_dir' 	=> null,
	'container'	=> null,
	'remote_dir'	=> null,
	'pubkey'	=> null,
	'privkey'	=> null,
	'username'	=> null
);

foreach ($args as $arg) {
	$matches = array();
        if (preg_match('/--(\w+)=(.+)/', $arg, $matches)) {
		$settings[$matches[1]] = $matches[2];	
	}
}

foreach (array_keys($settings) as $setting) {
	if ($settings[$setting] == null) {
		die("Missing the setting {$setting}\n");
	}
}

INotifyManager::setWatchDescriptor(inotify_init());
echo 'Connecting to ' . $settings['container'] . "\n";
SSHManager::init(
	$settings['container'],
	$settings['username'],
	$settings['pubkey'],
	$settings['privkey'],
	$settings['project_dir'],
	$settings['remote_dir']
);
addWatch($settings['project_dir']);

while ($events = inotify_read(INotifyManager::getWatchDescriptor())) {
	foreach ($events as $event) {
		switch(true) {
			case ($event['mask'] & IN_MODIFY || $event['mask'] & IN_CLOSE_WRITE ):
				handleModify($event['wd']);
			break;
			case ($event['mask'] & IN_CREATE):
				handleCreate($event['name'], $event['wd']);
                        break;
                        case ($event['mask'] & IN_DELETE):
				handleDelete($event['name'], $event['wd']);
                        break;
			default:
//				echo $event['mask'] . ' ';
			break;

		}
	}
}

function handleModify($wd) {
	$filename = INotifyManager::getWatchName($wd);
	echo $filename . " modified\n";
	if (!is_dir($filename)) {
		SSHManager::put(
			INotifyManager::getWatchName($wd)
		);
	}
}

function handleCreate($file, $wd) {
	$fileName = INotifyManager::getWatchName($wd) . '/' . $file;
	echo "{$fileName} created\n";
	addWatch($fileName);
	if (is_dir($fileName)) {
		SSHManager::mkdir($fileName);
	} else {
		SSHManager::put(
			$fileName
                );

	}
}

function handleDelete($file, $wd) {
	$fileName = INotifyManager::getWatchName($wd) . '/' . $file;
	echo "$fileName deleted\n";
	$wd = INotifyManager::getWatchId($fileName);
	INotifyManager::unsetWatchName($wd);
	SSHManager::unlink($fileName);
	echo "Watch for $fileName removed\n";
}

function addWatch($file) {
	$file = realpath($file);
	// We add children first because we don't want to trip off other watches
	if (is_dir($file)) {
		foreach (glob("{$file}/*") as $dir) {
			addWatch($dir);
		}
	}
	if (!file_exists($file)) return; 
	$watch = inotify_add_watch(INotifyManager::getWatchDescriptor(), $file, IN_ALL_EVENTS);
	INotifyManager::setWatchName($watch, $file);
	echo "Watching {$file}\n";
}

class SSHManager
{

	protected static $projectDir;
	protected static $remoteDir;
	protected static $ssh;

	public static function init($host, $username, $pubkey, $privkey, $projectDir, $remoteDir)
	{
		self::$ssh = ssh2_connect($host);
		if (ssh2_auth_pubkey_file(self::$ssh, $username, $pubkey, $privkey)) {
			self::$projectDir = realpath($projectDir);
			self::$remoteDir = ($remoteDir);
			return true;
		} else {
			throw new Exception('Unable to authenticate');
		}
	}

	public static function mkdir($filename)
	{
		$remoteFilename = self::convertLocalFilename($filename);
		echo "mkdir($filename $remoteFilename)\n";
		ssh2_sftp_mkdir(ssh2_sftp(self::$ssh), $remoteFilename);
		
	}

	public static function unlink($filename)
	{
		$remoteFilename = self::convertLocalFilename($filename);
		echo "unlink($filename $remoteFilename)\n";
		ssh2_sftp_unlink(ssh2_sftp(self::$ssh), $remoteFilename);
	}

	public static function put($filename)
	{
		// Can be called if there are a string of successive notifications that step on each other's toes
		if (!file_exists($filename)) {
			return;
		}
		$remoteFilename = self::convertLocalFilename($filename);
		echo "put($filename $remoteFilename)\n";
		$sftp = ssh2_sftp(self::$ssh);
		$fh = fopen("ssh2.sftp://" . $sftp . $remoteFilename, 'w');
		fwrite($fh, file_get_contents($filename));
		return;
	}

	public static function convertLocalFilename($filename)
	{
		return self::$remoteDir . substr($filename, strlen(self::$projectDir));
	}

}

class INotifyManager 
{
	protected static $watchList = array();

	protected static $wd;

	public static function setWatchDescriptor($wd)
	{
		self::$wd = $wd;
	}

	public static function getWatchDescriptor()
	{
		return self::$wd;
	}

	public static function getWatchId($name)
	{
		return array_search($name, self::$watchList);
	}

	public static function getWatchName($wd)
	{
		if (isset(self::$watchList[$wd])) {
			return self::$watchList[$wd];
		}
		throw new Exception('Something has really gone wrong.  Barfing so you can reset everything');
	}

	public static function setWatchName($wd, $dir)
	{
		self::$watchList[$wd] = $dir;
	}

        public static function unsetWatchName($wd)
        {
              unset(self::$watchList[$wd]);
        }

}
